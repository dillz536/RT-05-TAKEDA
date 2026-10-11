<?php

/*
| Antrean RT05 TAKEDA (docs/ASYNC_JOBS.md). Produksi/staging memakai Redis instance "queue"
| (AOF, noeviction). Tes memakai sync/fake. Tidak ada tabel jobs: durability antar Postgres
| dan Redis dijamin outbox_events, bukan antrean.
|
| retry_after (180 detik) harus lebih panjang dari timeout worker terlama (120 detik) agar job
| yang masih berjalan tidak diambil ulang. after_commit: job yang di-dispatch di dalam transaksi
| baru dikirim setelah commit.
*/

return [

    'default' => env('QUEUE_CONNECTION', 'redis'),

    'connections' => [

        'sync' => [
            'driver' => 'sync',
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'queue'),
            'queue' => env('REDIS_QUEUE', 'default'),
            'retry_after' => (int) env('REDIS_QUEUE_RETRY_AFTER', 180),
            'block_for' => null,
            'after_commit' => true,
        ],

    ],

    'batching' => [
        'database' => env('DB_CONNECTION', 'pgsql'),
        'table' => 'job_batches',
    ],

    // Job gagal permanen dicatat di Postgres (payload hanya ID event, tanpa PII).
    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'pgsql'),
        'table' => 'failed_jobs',
    ],

];
