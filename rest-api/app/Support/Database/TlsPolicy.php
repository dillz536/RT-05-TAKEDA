<?php

namespace App\Support\Database;

use Illuminate\Support\ConfigurationUrlParser;

/**
 * Menilai sslmode efektif koneksi Postgres setelah URL diurai.
 * Parameter query pada DATABASE_URL (Neon memakai sslmode=require) mengalahkan DB_SSLMODE,
 * sehingga kebijakan diperiksa pada konfigurasi akhir, bukan pada env terpisah.
 */
final class TlsPolicy
{
    public const CONNECTIONS = ['pgsql', 'pgsql_migrations'];

    /** Lingkungan yang wajib memverifikasi sertifikat dan hostname server. */
    private const STRICT_ENVIRONMENTS = ['staging', 'production'];

    public static function effective(string $connection): array
    {
        $config = (new ConfigurationUrlParser)->parseConfiguration(config("database.connections.$connection", []));

        return [
            'sslmode' => $config['sslmode'] ?? 'prefer',
            'sslrootcert_set' => ! empty($config['sslrootcert']),
        ];
    }

    /** @return list<string> pelanggaran tanpa nilai rahasia atau hostname. */
    public static function violations(?string $environment = null): array
    {
        $environment ??= (string) app()->environment();
        if (! in_array($environment, self::STRICT_ENVIRONMENTS, true)) {
            return [];
        }

        $violations = [];
        foreach (self::CONNECTIONS as $connection) {
            if ($connection === 'pgsql_migrations' && config("database.connections.$connection") === null) {
                continue; // Optional only for runtime; any configured migration connection remains strict.
            }
            $tls = self::effective($connection);
            if ($tls['sslmode'] !== 'verify-full') {
                $violations[] = "$connection: sslmode harus verify-full (sekarang {$tls['sslmode']})";
            }
            if (! $tls['sslrootcert_set']) {
                $violations[] = "$connection: sslrootcert wajib diisi (path CA bundle atau system)";
            }
        }

        return $violations;
    }
}
