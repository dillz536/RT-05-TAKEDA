<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Menerapkan infra/sql/grant-runtime-role.sql melalui koneksi migration (pemilik schema),
 * untuk lingkungan tanpa psql. Sumber SQL tetap satu file; variabel psql
 * :"owner_role" dan :"app_role" diganti identifier yang di-quote.
 */
class GrantRuntimeRole extends Command
{
    protected $signature = 'rt05:db-grant-runtime-role {app_role : Nama role runtime (DML saja)}';

    protected $description = 'Beri role runtime hak DML minimal pada schema public (idempotent)';

    public function handle(): int
    {
        $appRole = (string) $this->argument('app_role');
        if (! preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $appRole)) {
            $this->error('Nama role tidak valid.');

            return self::INVALID;
        }

        if (config('database.connections.pgsql_migrations') === null) {
            $this->error('Koneksi migrasi tidak tersedia pada proses ini. Gunakan job migrasi.');

            return self::INVALID;
        }

        $db = DB::connection('pgsql_migrations');
        $ownerRole = $db->selectOne('select current_user as role')->role;
        if ($ownerRole === $appRole) {
            $this->error('Role runtime harus berbeda dari role migration.');

            return self::INVALID;
        }

        $db->transaction(function () use ($db, $ownerRole, $appRole): void {
            foreach (self::statements($ownerRole, $appRole) as $statement) {
                $db->statement($statement);
            }
        });

        $this->info("Hak DML diterapkan untuk role runtime; pemilik schema: $ownerRole.");

        return self::SUCCESS;
    }

    /** @return list<string> */
    public static function statements(string $ownerRole, string $appRole): array
    {
        $path = base_path('../infra/sql/grant-runtime-role.sql');
        if (! is_file($path)) {
            throw new RuntimeException('infra/sql/grant-runtime-role.sql tidak ditemukan.');
        }

        $quote = fn (string $identifier) => '"'.str_replace('"', '""', $identifier).'"';
        $sql = preg_replace('/^\s*(--.*|\\\\.*)$/m', '', (string) file_get_contents($path));
        $sql = str_replace([':"owner_role"', ':"app_role"'], [$quote($ownerRole), $quote($appRole)], $sql);

        return array_values(array_filter(array_map('trim', explode(';', $sql))));
    }
}
