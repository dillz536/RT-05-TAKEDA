<?php

/*
| Pengaturan operasional RT05 TAKEDA (docs/ASYNC_JOBS.md). Hanya angka/nama, tanpa credential.
*/

return [

    'outbox' => [
        // null = QUEUE_CONNECTION. Event dikirim sebagai ID saja ke antrean notifications.
        'queue_connection' => env('RT05_OUTBOX_QUEUE_CONNECTION'),
        'queue' => env('RT05_OUTBOX_QUEUE', 'notifications'),
        'batch' => (int) env('RT05_OUTBOX_BATCH', 100),
        // Lease klaim dispatcher (klaim → enqueue). Dispatcher mati di tengah → event diklaim ulang.
        'lease_seconds' => (int) env('RT05_OUTBOX_LEASE_SECONDS', 120),
        // published tanpa completed lebih lama dari ini dianggap hilang dari antrean → replay.
        // Default > jendela retry job (5 percobaan, backoff 10/30/120/300, timeout 60).
        'stale_seconds' => (int) env('RT05_OUTBOX_STALE_SECONDS', 900),
    ],

    // M06 identitas (docs/API.md §Autentikasi, SECURITY.md §Identitas).
    'auth' => [
        // Nama cookie CSRF API; berbeda dari XSRF-TOKEN milik website pada origin yang sama.
        'csrf_cookie' => env('RT05_XSRF_COOKIE', 'rt05_api_xsrf'),
        // Kegagalan token registrasi per IP per jam (selain throttle registrasi 5/10 menit).
        'token_failures_per_hour' => (int) env('RT05_TOKEN_FAILURES_PER_HOUR', 10),
    ],

    'heartbeat' => [
        // Store cache untuk heartbeat; redis → instance queue (noeviction), selain itu store default.
        'store' => env('RT05_HEARTBEAT_STORE', env('CACHE_STORE', 'file') === 'redis' ? 'coordination' : null),
        'max_age_seconds' => (int) env('RT05_HEARTBEAT_MAX_AGE', 300),
        // Penulisan dari loop worker dibatasi agar tidak menulis Redis setiap iterasi.
        'write_interval_seconds' => (int) env('RT05_HEARTBEAT_WRITE_INTERVAL', 30),
        'components' => ['worker-default', 'worker-media', 'worker-exports', 'scheduler'],
    ],

];
