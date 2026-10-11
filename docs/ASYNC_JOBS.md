# Redis, worker, outbox, dan scheduler

## Prinsip

Keuangan dicatat sinkron di Postgres. Pekerjaan lambat/side effect dijalankan worker. Delivery queue setidaknya sekali: job dapat diterima lebih dari satu kali. Idempotency DB membuat hasil bisnis tidak ganda; jangan menjanjikan exactly-once network delivery.

## Redis

| Service | Kegunaan | Persist/eviction |
| --- | --- | --- |
| redis-queue | Queue, coordination locks | AOF everysec + volume; noeviction |
| redis-cache | Cache public, session CMS, rate limits | Bounded TTL; allkeys-lru; session loss meminta login ulang |

Tidak publish port 6379 ke internet. Pakai ACL/password per env dan Docker private network. Prefix takeda:ENV:, credential production berbeda staging. Kapasitas awal rancangan 256 MiB queue dan 128 MiB cache; limit container memberi overhead tambahan. Sesuaikan setelah melihat memory usage host, bukan klaim kapasitas pasti.

Limiter registrasi/login harus fail closed bila dependency gagal. Public read dapat bypass cache dan query API/Postgres. Jangan melakukan flushall di instance bersama.

## Worker awal

| Worker/queue | Pekerjaan | Timeout |
| --- | --- | --- |
| worker-default: notifications,default | Inbox/push/email/cache invalidation, outbox consumer | 60 detik |
| worker-media: media | Validasi/transcode/thumbnail | 120 detik |
| worker-exports: exports | CSV chunked dan metadata | 120 detik |

retry_after connection=180 detik, lebih panjang dari timeout worker maksimum. Network timeouts 10–20 detik per call; tries=5; backoff rancangan 10/30/120/300 detik. Job besar dipecah, bukan menambah timeout tanpa batas.

Command worker yang digunakan untuk M03:

```bash
php artisan queue:work redis --queue=notifications,default --sleep=2 --tries=5 --timeout=60 --max-time=3600
php artisan queue:work redis --queue=media --sleep=2 --tries=5 --timeout=120 --max-time=3600
php artisan queue:work redis --queue=exports --sleep=2 --tries=5 --timeout=120 --max-time=3600
php artisan schedule:work
```

Gunakan image API sama, restart policy/process manager, graceful SIGTERM, stop grace ≥180 detik. Deploy image baru melakukan rolling restart worker. Connection config after_commit=true; dispatch tetap memakai outbox untuk durability antar Postgres dan Redis.

## Transactional outbox

1. API transaction menulis domain + audit + outbox event stable UUID.
2. Dispatcher tiap menit mencari available rows, lock/lease terbatas, publish job event ID ke Redis.
3. Setelah enqueue berhasil, published_at diisi; completed_at tetap kosong sampai consumer selesai.
4. Consumer memuat event dari Postgres dan membuat hasil idempotent, lalu completed_at.
5. Lease expired atau published tanpa completion melewati batas →replay. Dispatcher menggunakan SKIP LOCKED jika beberapa proses.
6. Consumer inbox unique event+recipient; fanout broadcast per recipient/chunk, bukan satu job membawa semua data warga.

Jangan menandai event completed hanya karena enqueue. Crash sesudah enqueue sebelum mark bisa menghasilkan duplikat; constraint consumer menangani. Crash sesudah mark tetapi Redis kehilangan job juga dipulihkan lewat stale published replay.

Inbox dibuat idempotent lebih dahulu. Push eksternal tidak memiliki exactly-once guarantee; retry memakai logical notification ID dan payload/collapse behavior yang sesuai. Duplicate delivery bisa terjadi dan tidak boleh menggandakan finance.

### Pemulihan implementasi M03

`OutboxDispatcher` mengklaim event dengan lease `RT05_OUTBOX_LEASE_SECONDS`
(default 120 detik). `ProcessOutboxEvent` membawa ID event saja dan mengunci row
Postgres saat menulis inbox serta completion dalam transaksi yang sama. Event
published tanpa completion dapat diantrekan ulang setelah
`RT05_OUTBOX_STALE_SECONDS` (default 900 detik). Durasi ini lebih panjang dari
jendela retry normal agar replay tidak segera menduplikasi pekerjaan yang masih
berjalan. Gangguan enqueue meninggalkan event di Postgres dengan backoff dan
kode kelas error; pesan exception/DSN tidak disimpan pada event.

Setelah percobaan consumer habis, `failed_at` memarkir event sehingga dispatcher
tidak mengulangnya otomatis tanpa batas. Operator memperbaiki penyebabnya lalu
menggunakan `php artisan rt05:outbox-retry EVENT_UUID` untuk satu event. Event
yang sudah completed tidak dikirim ulang. Kolom ini ditambahkan dengan migration
nullable; tidak mengubah nominal tagihan atau ledger.

Heartbeat bersifat internal. Probe
`php artisan rt05:heartbeat-check --component=worker-default --component=scheduler`
mengembalikan exit 0 jika seluruh komponen sehat, 1 jika missing/stale/store tidak
tersedia, dan 2 jika argumen tidak valid. Probe tidak mengekspos data lewat API
publik. Komponen media/exports mempunyai probe sendiri meskipun job domainnya
belum menjadi bagian penerimaan tahap ini.

Config Redis menggunakan koneksi `queue` dan `cache`, dengan env
`REDIS_QUEUE_*` serta `REDIS_CACHE_*`. Alias `default` menunjuk queue untuk
kompatibilitas. Cache/rate limit/session memakai cache; coordination store dan
lock menggunakan queue agar tidak terpengaruh eviction cache. Compose memakai
nama service queue `redis` untuk mempertahankan volume `redis-data` yang sudah
ada; nama konsep `redis-queue` pada tabel bukan instruksi membuat instance ketiga.

Implementasi dan skrip tes belum membuktikan recovery sampai hasil CI tercatat
di [PROGRESS.md](PROGRESS.md) dan [evidence/m03](evidence/m03/).

## Job/domain

| Job/command | Trigger dan dedup |
| --- | --- |
| GenerateMonthlyCharges | House+period unique; scheduler catch-up |
| RotateRegistrationInvite | Token validity/lock; revoke old atomik |
| PublishOutboxEvents | Lease rows; retry/replay stale |
| DeliverNotification | event+recipient unique inbox |
| SendDuesReminder | house+period+reminder_slot+recipient |
| SendAgendaReminder | agenda_id+starts_at_revision+recipient |
| ProcessMedia | media ID; ready skip; retry safe |
| BuildCsvExport | export ID; chunks; atomic final file |
| InvalidatePublicContentCache | entity+published_version; TTL fallback |
| CleanupTemporaryMedia | Only unreferenced, expired, private staging assets |
| ExpireExports | File expired; never delete financial records |
| BackupRuntime | Operator system job, bukan web endpoint publik |

## Jadwal rancangan Asia/Jakarta

| Jadwal | Aksi |
| --- | --- |
| Tiap menit | Dispatch outbox dan heartbeat scheduler |
| Harian 00:10 | Ensure charge bulan berjalan/catch-up sejak billing start |
| Tiap jam | Ensure token rotation jika token telah berumur ≥7 hari |
| Tiap jam | Agenda reminder untuk window H-1 yang belum dikirim |
| Tanggal 5 dan 20, 09:00 | Pengingat iuran unpaid periode berjalan |
| Harian 02:00 | Expire export dan orphan staging media >24 jam |
| Harian 03:00 | Backup DB/media sesuai runbook operator |

Manual token rotation mengubah activated_at; scheduled job memeriksa setiap jam dan tidak merotasi lagi sebelum umur tujuh hari; expiry tetap divalidasi; bila scheduler terlambat dan token sudah expired, registrasi menolaknya sampai rotasi berikutnya atau rotasi manual admin. Seluruh jadwal punya withoutOverlapping/central lock dan DB dedup. Waktu di atas adalah konfigurasi v1, bukan fakta kebijakan RT lama.

Agenda reminder catch-up setelah downtime harus memiliki grace window; jangan membanjiri warga untuk agenda yang sudah selesai. Reminder iuran membatasi target primary account.

## Kegagalan dan observability

Metrics: oldest pending outbox age, retries, failed jobs, queue depth, worker heartbeat, scheduler heartbeat, provider failure, media backlog.

Alert rancangan: worker/scheduler absent >5 menit, outbox age >5 menit, failed finance integrity check, backup terakhir >26 jam. Threshold diuji, bukan semua error kecil dikirim sebagai alert.

Failed job mencatat reference ID/code yang aman. Retry manual berdasarkan job/event ID; jangan replay seluruh antrean tanpa melihat dampak. Queue penuh/noeviction menghasilkan error dan alert; domain transaction yang sudah commit tetap tersimpan serta side effect pending di outbox.

## Bukti penerimaan

Kill worker sesudah domain commit →restart→satu inbox, pembayaran tetap satu. Dispatch event dua kali→consumer idempotent. Redis queue restart dengan AOF→pending job dipulihkan; simulasi queue loss→outbox replay. Scheduler overlap→satu charge dan satu reminder logical. Provider down→retry terbatas/failed state, API finance tetap konsisten.
