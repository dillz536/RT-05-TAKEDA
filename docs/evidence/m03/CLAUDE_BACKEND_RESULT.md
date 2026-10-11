# M03 backend — hasil Claude (rest-api)

Tanggal: 11 Oktober 2026. Branch kerja bersama `codex/m03-async-recovery` (dari main c644b84). Owner: Claude, eksklusif `rest-api/**` dan file ini. Belum ada commit/push dari Claude; Git dan docs utama milik coordinator.

Status jujur: **implementasi selesai, tes DB belum dijalankan.** PHPUnit yang memakai Postgres (AsyncOutboxTest, ServicesTest) belum pernah dijalankan. PC ini tidak punya Postgres atau Redis (ADR19), jadi tes tersebut harus lulus di CI sebelum gate M03 boleh dinilai. Proses worker/Redis sungguhan (kill/restart, AOF, queue loss) dikerjakan Grok di CI integrasi.

## Yang dibangun

| Gate M03 (ROADMAP/ASYNC_JOBS) | Implementasi | Bukti tes (CI) |
| --- | --- | --- |
| Dispatcher lease + SKIP LOCKED, enqueue ID ke Redis | `app/Domain/Operations/OutboxDispatcher.php`: klaim lease 120 detik, `pushOn('notifications', ProcessOutboxEvent(id))`. `published_at` diisi hanya sesudah push berhasil | `test_dispatcher_publishes_after_enqueue_and_only_consumer_completes` |
| completed hanya oleh consumer, atomik + idempotent | `app/Jobs/ProcessOutboxEvent.php`: `lockForUpdate` event; inbox + `completed_at` dalam satu transaksi; skip bila completed/failed | idem; `test_duplicate_delivery_creates_one_inbox_per_recipient` |
| Duplicate event → satu inbox | Consumer skip completed + `UNIQUE(event_id, penerima_id)` | `test_duplicate_delivery…`, `ServicesTest::test_status_change_notifies_owner_once_even_if_replayed` (disesuaikan) |
| Lease kedaluwarsa → replay | Klaim memilih `dispatch_lease_until < now()` | `test_expired_lease_is_reclaimed_and_active_lease_is_skipped` |
| Stale published (queue loss) → replay | Klaim memilih `published_at < now() - RT05_OUTBOX_STALE_SECONDS` (default 900) | `test_stale_published_event_is_replayed_but_recent_one_is_not` |
| Redis gagal → event tetap pending, kode aman | `last_error_code=enqueue_failed:<Kelas>`, lease dilepas, `available_at` backoff 2/4/8/15 menit; log hanya kelas exception. Command exit 1 | `test_enqueue_failure_keeps_event_and_domain_row_durable_with_safe_code` (pesan berisi DSN palsu tidak tersimpan) |
| Pembayaran tetap satu (queue down, retry klien, redelivery) | Tidak ada perubahan domain keuangan; consumer hanya menulis inbox | `test_offline_payment_stays_single_through_queue_outage_retry_and_redelivery` |
| DB rollback tidak meninggalkan event | Outbox ditulis di transaksi domain (sudah ada) | `test_domain_rollback_leaves_no_outbox_event` (Reports::createComplaint nyata di dalam transaksi yang gagal) |
| Retry terbatas / failed state | Job: tries 5, timeout 60, backoff 10/30/120/300. Percobaan habis → `failed_at` (parkir, tidak di-replay otomatis); `rt05:outbox-retry {event}` manual | `test_consumer_failure_rolls_back_then_parks_and_manual_retry_recovers` |
| Event tak dikenal tidak selesai diam-diam | `UnknownOutboxEvent` + middleware `FailOnException` → langsung parkir; tiga jenis no-op eksplisit (`akun.registrasi_utama`, `iuran.tagihan_dibuat`, `iuran.pembayaran_dibalik`) | `test_unknown_event_type_is_parked_not_silently_completed` |
| Anonimitas pada failed_jobs/log | Exception consumer dibungkus `OutboxConsumerFailed` (kelas + SQLSTATE, tanpa SQL/binding/ID, tanpa previous) | assert pada tes consumer failure |
| Queue config | `config/queue.php`: default `redis` → Redis connection `queue`, `retry_after=180`, `after_commit=true`; koneksi tak terpakai (database/sqs/…) dihapus | `scratchpad` cek config (lihat bawah) |
| Redis queue/cache terpisah | `config/database.php`: `queue` (DB0), `cache` (DB1), `default` = alias queue; `REDIS_QUEUE_*`/`REDIS_CACHE_*` jatuh ke `REDIS_*`; timeout koneksi 2 detik; prefix default `takeda:<APP_ENV>:` | idem |
| Lock scheduler di instance noeviction | `config/cache.php`: store `redis` connection `cache`, `lock_connection=queue`; store `coordination` (queue) untuk heartbeat; sesi → `cache` | idem |
| Heartbeat worker/scheduler, internal saja | `app/Support/Heartbeat.php`; listener `Looping` (AppServiceProvider, throttle 30 detik, gagal Redis tidak menghentikan worker); `Schedule::call` tiap menit; `rt05:heartbeat-check` | `tests/Feature/HeartbeatTest.php` (5 tes, **lulus lokal**) |
| Smoke CI | `rt05:async-smoke --phase=prepare [--with-inbox] / verify --event --timeout --expect-inbox / status`; semua phase ditolak kecuali non-production + DB `*_test` lokal/CI + Redis lokal/CI; stdout prepare hanya `event_id=<uuid>` | `test_async_smoke_prepare_and_verify_without_leaking_identity`, `test_async_smoke_refuses_non_test_targets` |
| Schema | Migration additive `2026_10_11_000700_outbox_consumer_failure.php`: kolom `failed_at timestamptz(6)`, index pending tanpa event diparkir | migrate:fresh pada `rt05_test` (CI) |

Cron domain (tagihan, pengingat agenda/iuran, rotasi token) tidak diubah; hanya baris heartbeat scheduler yang ditambah ke `routes/console.php`.

## File (semua di rest-api/)

Baru: `app/Domain/Operations/OutboxDispatcher.php`, `app/Jobs/ProcessOutboxEvent.php`, `app/Support/Heartbeat.php`, `app/Console/Commands/{AsyncSmoke,HeartbeatCheck,OutboxRetry}.php`, `app/Exceptions/{UnknownOutboxEvent,OutboxConsumerFailed}.php`, `config/rt05.php`, `database/migrations/2026_10_11_000700_outbox_consumer_failure.php`, `tests/Feature/{AsyncOutboxTest,HeartbeatTest}.php`.

Diubah: `app/Domain/Community/Notifications.php` (dispatch inline dihapus; handler smoke, no-op eksplisit, unknown → exception), `app/Console/Commands/DemoSeed.php`, `app/Models/OutboxEvent.php`, `app/Providers/AppServiceProvider.php`, `config/{queue,database,cache,session}.php`, `routes/console.php`, `tests/Feature/ServicesTest.php`, `.env.example`, `README.md`.

## Kontrak untuk infra (Grok), disetujui coordinator

- Worker default: `php artisan queue:work redis --queue=notifications,default --sleep=2 --tries=5 --timeout=60 --max-time=3600`. Media/exports: `--queue=media` / `--queue=exports`, `--timeout=120` (belum ada job; hanya heartbeat).
- Env: `REDIS_HOST` (queue), `REDIS_CACHE_HOST`/`REDIS_CACHE_PORT` (cache), `CACHE_STORE=redis`, `QUEUE_CONNECTION=redis`, `SESSION_DRIVER=redis`, `REDIS_PREFIX` per env. CI boleh `RT05_OUTBOX_STALE_SECONDS=5`.
- Probe: `rt05:heartbeat-check --component=scheduler --component=worker-default --max-age=300`.
- Skenario integrasi: `async-smoke prepare --with-inbox` → `outbox-dispatch` → (kill worker / restart Redis AOF / hapus kunci `queues:notifications*` berprefix lalu tunggu stale) → `outbox-dispatch` → `async-smoke verify --event=<id> --expect-inbox=1`.

## Perintah yang dijalankan dan hasil aktual (lokal Windows, PHP 8.4.13)

| Perintah (cwd rest-api) | Hasil |
| --- | --- |
| `php -l` seluruh `app config routes tests database` | 113 file, 0 error |
| `php vendor/bin/pint --test` | passed (sesudah `pint` memperbaiki single_quote/no_unused_imports pada 2 file) |
| `RT05_SKIP_TEST_MIGRATIONS=1 php vendor/bin/phpunit tests/Feature/HeartbeatTest.php tests/Unit` | 10 tes, 50 assertion, lulus (tanpa DB) |
| Boot config dengan env staging sintetis (script scratchpad, hanya kunci non-rahasia) | queue/default → host redis DB0, cache → redis-cache DB1, retry_after 180, after_commit true, lock_connection queue, session connection cache, heartbeat store coordination |
| `php artisan schedule:list` | heartbeat scheduler + outbox-dispatch tiap menit; jadwal domain tidak berubah |
| `php artisan rt05:async-smoke --phase=status` (`.env` lokal → Neon dev) | exit 1, ditolak guard sebelum koneksi DB apa pun |
| `php artisan rt05:heartbeat-check --component=scheduler` / `--component=api` | exit 1 `scheduler missing` / exit 2 |
| `node infra/scripts/check-repo.mjs` (root) | OK |
| `git diff --check -- rest-api` | bersih |

Tidak dijalankan: `php artisan test` penuh (butuh Postgres `*_test`), migrasi pada Neon apa pun, Redis, worker sungguhan.

## Keterbatasan dan tindak lanjut

1. **CI wajib**: AsyncOutboxTest (12 tes) dan ServicesTest yang diubah belum pernah dijalankan. SQL baru memakai `make_interval(secs => ?)` dan `FOR UPDATE SKIP LOCKED`. Kegagalan pertama di CI harus dibaca, bukan diasumsikan lulus.
2. Restart worker, AOF, queue loss dengan proses nyata, dan heartbeat dari container belum terbukti; PHPUnit memakai fake/sync. Bukti ini menjadi tanggung jawab CI integrasi Grok.
3. `/health/ready` tetap hanya memeriksa database. Redis/worker dipantau lewat `rt05:heartbeat-check` (internal) agar API tidak 503 saat queue down (DEPLOYMENT: domain tetap jalan). Kontrak OpenAPI tidak berubah.
4. Stale replay default 15 menit, jadi pemulihan queue loss di production paling cepat ~15 menit. Rate limiter login (store cache) gagal tertutup dengan 500 bila Redis cache down; perilaku bawaan, belum diuji.
5. Redis tanpa ACL/password pada compose saat ini (jaringan privat). Itu ranah infra; config sudah mendukung `REDIS_*_PASSWORD`.
6. Celah domain di luar scope M03, tidak disentuh: `rt05:pengingat-iuran` dedup tanpa constraint unik/withoutOverlapping; jam pengingat 08:00 vs 09:00 di docs; rotasi token mingguan vs cek jam ≥7 hari. Celah ini milik M10/M11/M06.
7. Staging belum menerima perubahan ini. Deploy berikutnya menjalankan migration additive `failed_at` dan butuh compose worker baru dari Grok. Event lama yang `published_at` terisi tanpa `completed_at` akan di-replay sesudah 15 menit; ini aman karena consumer idempotent.
8. Dispatch sebelumnya (audit `task_fba34a54cfb4`) tidak menghasilkan `CLAUDE_BACKEND_AUDIT.md`, karena digantikan dispatch implementasi ini. Temuan audit (consumer sinkron di scheduler, tidak ada job/heartbeat, `after_commit=false`, `retry_after=90`, worker hanya antrean `default`) sudah ditangani di atas.
