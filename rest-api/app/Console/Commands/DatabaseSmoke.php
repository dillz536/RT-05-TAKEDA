<?php

namespace App\Console\Commands;

use App\Support\Database\TlsPolicy;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Smoke sintetis koneksi database tanpa meninggalkan data: tulis-baca di dalam transaksi
 * yang selalu di-rollback. Untuk runtime juga membuktikan role tidak dapat menjalankan DDL.
 * Output tidak memuat hostname, password, atau URL.
 */
class DatabaseSmoke extends Command
{
    protected $signature = 'rt05:db-smoke {--connection=pgsql : pgsql (runtime) atau pgsql_migrations}';

    protected $description = 'Uji baca/tulis sintetis, TLS, role, dan batas DDL pada koneksi Postgres';

    public function handle(): int
    {
        $name = (string) $this->option('connection');
        if (! in_array($name, TlsPolicy::CONNECTIONS, true)) {
            $this->error('Koneksi tidak dikenal.');

            return self::INVALID;
        }
        if (config("database.connections.$name") === null) {
            $this->error('Koneksi tidak tersedia pada proses ini. Gunakan job migrasi untuk koneksi DDL.');

            return self::INVALID;
        }

        $failures = TlsPolicy::violations();
        $db = DB::connection($name);

        try {
            $info = $db->selectOne(<<<'SQL'
                select current_setting('server_version') as server_version,
                       current_user as role,
                       current_database() as database,
                       current_setting('application_name') as application_name,
                       coalesce((select ssl from pg_stat_ssl where pid = pg_backend_pid()), false) as ssl,
                       (select version from pg_stat_ssl where pid = pg_backend_pid()) as tls_version
            SQL);
        } catch (Throwable $e) {
            $this->error('Koneksi gagal: '.$e::class);

            return self::FAILURE;
        }

        $key = 'smoke.'.Str::uuid();
        $readBack = null;
        $ddl = 'not_checked';

        try {
            $db->beginTransaction();
            $db->insert('insert into system_settings (key, value) values (?, ?::jsonb)', [$key, json_encode(['ok' => true])]);
            $readBack = $db->selectOne('select value from system_settings where key = ?', [$key])?->value;

            if ($name === 'pgsql') {
                try {
                    $db->statement('savepoint ddl_probe');
                    $db->statement('create table rt05_ddl_probe (id int)');
                    $ddl = 'allowed';
                } catch (QueryException $e) {
                    $ddl = $e->getCode() === '42501' ? 'denied' : 'error:'.$e->getCode();
                } finally {
                    $db->statement('rollback to savepoint ddl_probe');
                }
            }
        } catch (Throwable $e) {
            $failures[] = 'tulis/baca sintetis gagal: '.$e::class.' SQLSTATE '.$e->getCode();
        } finally {
            if ($db->transactionLevel() > 0) {
                $db->rollBack();
            }
        }

        $leftover = $db->selectOne('select count(*) as n from system_settings where key = ?', [$key])->n;
        $tls = TlsPolicy::effective($name);
        $host = (string) (new ConfigurationUrlParser)->parseConfiguration($db->getConfig())['host'];
        $pooled = str_contains($host, '-pooler');
        $neon = str_ends_with($host, '.neon.tech');

        $this->table(['Pemeriksaan', 'Hasil'], [
            ['connection', $name],
            ['server_version', $info->server_version],
            ['role', $info->role],
            ['database', $info->database],
            ['application_name', $info->application_name],
            ['endpoint', $pooled ? 'pooled' : 'direct/lokal'],
            // Neon mengakhiri TLS di proxy/pooler, sehingga pg_stat_ssl melihat koneksi internal ke
            // compute. TLS client→Neon dijamin libpq oleh sslmode (verify-full menolak sertifikat salah).
            ['ssl (sisi server)', $info->ssl ? 'on ('.$info->tls_version.')' : ($neon ? 'n/a (TLS diakhiri proxy Neon)' : 'off')],
            ['sslmode efektif', $tls['sslmode']],
            ['tulis+baca dalam transaksi', $readBack !== null ? 'ok' : 'gagal'],
            ['baris tersisa setelah rollback', (string) $leftover],
            ['DDL oleh role ini', $ddl],
        ]);

        if ($readBack === null) {
            $failures[] = 'baris sintetis tidak terbaca';
        }
        if ((int) $leftover !== 0) {
            $failures[] = 'baris sintetis tersisa setelah rollback';
        }
        if ($name === 'pgsql' && $ddl !== 'denied') {
            $failures[] = "role runtime harus ditolak DDL (hasil: $ddl)";
        }

        foreach ($failures as $failure) {
            $this->error($failure);
        }

        return $failures === [] ? self::SUCCESS : self::FAILURE;
    }
}
