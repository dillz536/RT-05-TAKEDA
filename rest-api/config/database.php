<?php

use Pdo\Pgsql;

/*
| RT05 TAKEDA hanya memakai PostgreSQL (Neon pada dev/staging/production, Postgres lokal pada
| local/CI). Dua koneksi memakai database yang sama dengan role berbeda:
|
| - pgsql            runtime API/worker; DATABASE_URL = endpoint pooled Neon, role DML saja.
| - pgsql_migrations migration/backup; DATABASE_URL_UNPOOLED = endpoint direct, role pemilik schema.
|
| Jalankan migration lewat `composer migrate` (php artisan migrate --database=pgsql_migrations).
| TLS: DB_SSLMODE=verify-full + DB_SSLROOTCERT (path CA bundle atau "system" untuk libpq >= 16).
*/

$pgsql = fn (?string $url, string $appName) => [
    'driver' => 'pgsql',
    'url' => $url,
    'host' => env('DB_HOST', '127.0.0.1'),
    'port' => env('DB_PORT', '5432'),
    'database' => env('DB_DATABASE', 'rt05_dev'),
    'username' => env('DB_USERNAME', 'rt05_app'),
    'password' => env('DB_PASSWORD', ''),
    'charset' => 'utf8',
    'prefix' => '',
    'prefix_indexes' => true,
    'search_path' => 'public',
    'sslmode' => env('DB_SSLMODE', 'prefer'),
    'sslrootcert' => env('DB_SSLROOTCERT'),
    'application_name' => $appName,
    'timezone' => 'UTC',
    'options' => [
        // pdo_pgsql memetakan ATTR_TIMEOUT ke libpq connect_timeout (detik).
        PDO::ATTR_TIMEOUT => (int) env('DB_CONNECT_TIMEOUT', 5),
        // Pooler Neon (PgBouncer mode transaksi) menolak DEALLOCATE SQL yang dikirim pdo_pgsql untuk
        // named prepared statement, sehingga transaksi gugur (25P02). Nonaktifkan named prepares;
        // parameter tetap dikirim terpisah lewat PQexecParams, bukan emulasi string.
        (defined('Pdo\Pgsql::ATTR_DISABLE_PREPARES') ? Pgsql::ATTR_DISABLE_PREPARES : PDO::PGSQL_ATTR_DISABLE_PREPARES) => true,
    ],
];

return [

    'default' => env('DB_CONNECTION', 'pgsql'),

    'connections' => [

        'pgsql' => $pgsql(env('DATABASE_URL'), 'rt05-api'),

        'pgsql_migrations' => $pgsql(env('DATABASE_URL_UNPOOLED', env('DATABASE_URL')), 'rt05-migrations'),

    ],

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    /*
    | Redis (docs/ASYNC_JOBS.md, docs/ARCHITECTURE.md §8). Dua instance terpisah:
    | - queue: antrean + lock koordinasi (scheduler, heartbeat); AOF, noeviction.
    | - cache: cache/session/rate limiter; TTL + eviction, boleh hilang.
    | Host/port/password per instance jatuh ke REDIS_HOST/REDIS_PORT/REDIS_PASSWORD bila satu
    | instance dipakai bersama (DB index berbeda). "default" = alias queue untuk kompatibilitas.
    | Prefix per lingkungan (takeda:<env>:); staging dan production tidak boleh berbagi prefix.
    */
    'redis' => (function () {
        $instance = fn (string $name, string $db) => [
            'host' => env("REDIS_{$name}_HOST", env('REDIS_HOST', '127.0.0.1')),
            'username' => env("REDIS_{$name}_USERNAME", env('REDIS_USERNAME')),
            'password' => env("REDIS_{$name}_PASSWORD", env('REDIS_PASSWORD')),
            'port' => env("REDIS_{$name}_PORT", env('REDIS_PORT', '6379')),
            'database' => env("REDIS_{$name}_DB", $db),
            // Gagal cepat: enqueue yang gagal membiarkan event outbox pending, bukan menggantung dispatcher.
            'timeout' => (float) env('REDIS_TIMEOUT', 2),
            'read_timeout' => (float) env('REDIS_READ_TIMEOUT', 5),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ];

        return [
            'client' => env('REDIS_CLIENT', 'phpredis'),

            'options' => [
                'cluster' => env('REDIS_CLUSTER', 'redis'),
                'prefix' => env('REDIS_PREFIX', 'takeda:'.env('APP_ENV', 'local').':'),
                'persistent' => env('REDIS_PERSISTENT', false),
            ],

            'queue' => $instance('QUEUE', '0'),
            'default' => $instance('QUEUE', '0'),
            'cache' => $instance('CACHE', '1'),
        ];
    })(),

];
