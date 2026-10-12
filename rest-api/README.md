# rest-api/ — Laravel REST API RT05 TAKEDA

**Status: fondasi M02, layanan M06–M12 dan async M03 (lihat docs/PROGRESS.md).** Laravel 13.35 (sama dengan `website/`), PHP 8.4, PostgreSQL saja. `rest-api/` adalah pemilik tunggal database dan aturan bisnis; website dan mobile hanya memanggil `/api/v1`. Kontrak: [docs/openapi.yaml](../docs/openapi.yaml) (mesin) dan [docs/API.md](../docs/API.md) (desain).

## Yang sudah ada (M02)

| Bagian | Isi |
| --- | --- |
| Endpoint | `GET /health/live` (tanpa DB), `GET /health/ready` (DB + kebijakan TLS, 503 tanpa detail), `GET /api/v1` |
| Konvensi | `X-Request-Id` (UUID), error envelope tunggal `{"error":{code,message,fields?,request_id}}`, `ApiResource` dengan `meta.request_id`, semua error JSON tanpa stack trace |
| Database | Koneksi `pgsql` (runtime, pooled, role DML) dan `pgsql_migrations` (direct, pemilik schema); TLS `DB_SSLMODE`/`DB_SSLROOTCERT`; named prepares dimatikan untuk pooler Neon |
| Schema fondasi | `failed_jobs`, `audit_logs` (append-only via trigger), `outbox_events`, `idempotency_requests`, `system_settings` |
| Command | `rt05:db-smoke`, `rt05:db-grant-runtime-role` |
| Tes | Postgres nyata dengan role runtime; contract test OpenAPI; CI `.github/workflows/api.yml` |

Endpoint yang dirancang tetapi belum dibangun tercatat `x-status: planned` di OpenAPI dan menghasilkan 404 sampai milestone-nya.

## Async (M03)

Alur: transaksi domain menulis `outbox_events` → scheduler `rt05:outbox-dispatch` (tiap menit) mengklaim event dengan lease `FOR UPDATE SKIP LOCKED`, mengirim ID event ke antrean Redis `notifications`, lalu mengisi `published_at` → worker menjalankan `App\Jobs\ProcessOutboxEvent` yang mengunci event, menulis inbox, dan mengisi `completed_at` dalam satu transaksi. Detail rancangan: [docs/ASYNC_JOBS.md](../docs/ASYNC_JOBS.md).

| Kondisi | Perilaku |
| --- | --- |
| Redis tidak tersedia saat enqueue | Event tetap pending, `last_error_code=enqueue_failed:<Kelas>`, dicoba lagi dengan backoff ≤15 menit; transaksi domain tidak terpengaruh |
| Dispatcher mati sebelum enqueue | Lease (`RT05_OUTBOX_LEASE_SECONDS`, 120) habis → diklaim ulang |
| Job hilang dari Redis | `published_at` tanpa `completed_at` > `RT05_OUTBOX_STALE_SECONDS` (900) → dikirim ulang |
| Job/event ganda | Consumer berhenti bila event sudah completed; inbox `UNIQUE(event_id, penerima_id)` |
| Consumer gagal | Rollback; retry 5× (backoff 10/30/120/300 detik, timeout 60); habis → `failed_at` (diparkir). Jenis event tak dikenal langsung diparkir. `failed_jobs`/log hanya memuat kelas + SQLSTATE |

Worker: `php artisan queue:work redis --queue=notifications,default --sleep=2 --tries=5 --timeout=60 --max-time=3600` (media/exports: `--queue=media` atau `--queue=exports`, `--timeout=120`). Scheduler: `php artisan schedule:work`.

| Command | Kegunaan |
| --- | --- |
| `rt05:outbox-dispatch` | Klaim + enqueue; exit 1 bila ada enqueue gagal |
| `rt05:outbox-retry {event}` | Ulang manual satu event yang diparkir/tertahan |
| `rt05:heartbeat-check --component=…` | Probe internal heartbeat `worker-default`/`worker-media`/`worker-exports`/`scheduler`; exit 0 sehat, 1 basi/hilang/store gagal, 2 argumen salah |
| `rt05:async-smoke --phase=prepare/verify/status` | Smoke sintetis untuk CI; hanya database `*_test` lokal/CI, Redis lokal/CI, non-production |
| `rt05:staging-smoke --confirm-staging --phase=prepare/verify` | Smoke M04 di staging: satu event `rt05.smoke` tanpa penerima (tanpa akun/inbox/keuangan); hanya `APP_ENV=staging` + flag konfirmasi, dicek sebelum akses DB; prepare mencetak UUID saja |

Redis: koneksi `queue` (antrean, lock scheduler, heartbeat; AOF + noeviction) dan `cache` (cache, sesi, rate limiter). `REDIS_QUEUE_*`/`REDIS_CACHE_*` jatuh ke `REDIS_HOST/PORT/PASSWORD` dengan DB 0/1. Prefix default `takeda:<APP_ENV>:`. Lokal tanpa Redis: `QUEUE_CONNECTION=sync` sehingga dispatch langsung memproses event.

## Identitas dan sesi (M06)

| Bagian | Perilaku |
| --- | --- |
| Registrasi utama | Satu transaksi termasuk bearer perangkat; diserialkan advisory lock, token aktif dikunci `FOR SHARE`. Alamat ternormalisasi yang sudah ada (termasuk rumah kosong/arsip) → 409 `HOUSE_ALREADY_REGISTERED` tanpa merge. Pelanggaran constraint unik dari penulis lain → konflik generik, bukan 500 |
| Token RT | String enam digit, rotasi diserialkan advisory lock rotasi lalu mengantre pada advisory lock registrasi (FIFO: registrasi yang sudah berjalan selesai dulu, registrasi yang datang sesudahnya ditolak karena token lama dicabut) + `FOR UPDATE`. Kode baru tidak pernah sama dengan kode token yang dicabut/terakhir. Kegagalan token dibatasi `RT05_TOKEN_FAILURES_PER_HOUR` (10) per IP → 429. Scheduler `rt05:token-rotate --if-due` tiap jam: rotasi bila token berumur 7 hari atau tidak ada token aktif |
| Mobile | Bearer Sanctum per perangkat 30 hari; tanpa CSRF; request tanpa Origin/Referer tidak memakai cookie |
| Kata sandi baru | `PasswordPolicy`: min 8 karakter berisi huruf dan angka, **maks 72 byte UTF-8**, tanpa NUL → 422 (registrasi, tambahan, reset, ganti, `rt05:pengurus`). PHP 8.4 memotong bcrypt diam-diam setelah 72 byte dan NUL menghasilkan 500; `config/hashing.php` memasang limit 72 sehingga hasher menolak, bukan memotong. Login: input > 72 byte tidak pernah cocok |
| Penolakan akun tambahan | Permohonan `ditolak` + alasan; akun tetap `tambahan`/`menunggu` tanpa warga, bearer/sesi lama dicabut. Login ulang memberi bearer terbatas (`me`, `additional-account-status` dengan alasan, `logout`); layanan warga 403. `nonaktif`/`diarsipkan` ditolak di semua endpoint |
| CMS | `GET /sanctum/csrf-cookie` → cookie `rt05_api_session` + `rt05_api_xsrf` (`RT05_XSRF_COOKIE`, tidak bentrok dengan `XSRF-TOKEN` website). `POST /api/v1/auth/web-login` hanya pengurus aktif; CSRF selalu wajib (`RequireCsrfToken`: tidak dilewati saat tes, `Sec-Fetch-Site` saja tidak cukup). Origin stateful: `SANCTUM_STATEFUL_DOMAINS`, default host `APP_URL` |
| Pencabutan sesi browser | Sesi menyimpan `akun.versi_sesi`; reset/ganti password, logout-all, perubahan status, approve/reject, dan grant/revoke operator menaikkan versi. Setiap request cookie memeriksa versi, idle `SESSION_LIFETIME` (120 menit), peran pengurus, dan status aktif; gagal → sesi diakhiri, 401 |
| Reset password | Respons lupa password selalu 202 generik. Email `ResetPasswordNotification` diantrekan `default` setelah commit dengan payload terenkripsi; tautan `APP_URL/reset-password#token=…&email=…` (fragment, tidak sampai ke log) dan kode yang sama untuk Android. Token 60 menit, sekali pakai (baris dikunci), mencabut semua bearer dan sesi. Di **semua** environment (termasuk local yang terhubung Neon dev) email tidak dikirim bila mailer bertransport log, termasuk di dalam failover/roundrobin (peringatan tanpa token; respons tetap 202). Di luar local/testing juga bila `APP_URL` bukan HTTPS |
| Halaman reset | `GET /reset-password`: logo gapura dan font Public Sans/Bricolage Grotesque (latin, variable) disalin byte-identik dari `website/` ke `public/auth-assets/` beserta lisensi OFL; CSP `img-src 'self'; font-src 'self'`. Gateway meneruskan `/auth-assets/*` ke API |
| Operator | `rt05:pengurus buat <email>`: password dari env `RT05_ADMIN_PASSWORD`, prompt tersembunyi, atau (non-interaktif) acak tanpa ditampilkan + email reset. `grant`/`revoke` mencabut token dan sesi; pengurus aktif terakhir tidak dapat dicabut. Semua diaudit tanpa password |

Tes M06 memakai PostgreSQL nyata: race registrasi/rotasi/reset dengan proses PHP independen (`tests/Support/race-worker.php`, barrier advisory lock, dan handshake deterministik: worker berhenti di gerbang lock di dalam transaksi, PHPUnit memastikan worker lain mengantre lewat `pg_locks`/`pg_stat_activity` sebelum gerbang dilepas) dan CSRF lewat server PHP terpisah + cookie jar (`M06BrowserHttpTest`). Transport mail array hanya membuktikan isi dan antrean, bukan pengiriman SMTP sampai inbox.

## Struktur dan konvensi domain

```text
app/Http/Controllers/<Domain>/   tipis: authorize → FormRequest → Action → Resource
app/Http/Requests/<Domain>/      validasi + authorize() memanggil Policy
app/Http/Resources/<Domain>/     turunan ApiResource; field allowlist eksplisit
app/Policies/                    otorisasi per resource; resource privat di luar scope → 404
app/Actions/<Domain>/            transaksi DB + audit_logs + outbox_events dalam satu commit
app/Http/Responses/ApiError.php  satu-satunya pembuat error JSON
```

- Mutation penting: `DB::transaction` + row lock bila perlu + `audit_logs` + `outbox_events`. Side effect (email/push) lewat outbox, bukan di dalam transaksi.
- Mutation finance wajib `Idempotency-Key` (tabel `idempotency_requests`).
- Uang `bigint` rupiah; waktu `timestamptz` UTC; ID UUID.
- Setiap route baru wajib ada di `docs/openapi.yaml` dengan `x-status: implemented`; `OpenApiContractTest` gagal bila route dan kontrak berbeda.

## Menjalankan

```powershell
Set-Location rest-api
composer install
Copy-Item .env.example .env
php artisan key:generate
composer migrate          # php artisan migrate --database=pgsql_migrations --force
php artisan serve         # http://127.0.0.1:8000/health/live
php artisan rt05:db-smoke
```

### Database untuk tes

Tes **selalu** memakai PostgreSQL nyata pada database `*_test` di host lokal (`tests/bootstrap.php` menolak selain itu, jadi tes tidak pernah menyentuh Neon). Migration tes dijalankan sekali via `pgsql_migrations`, lalu tes berjalan sebagai role runtime `rt05_app`.

- Dengan Docker: `docker compose -f infra/compose.local.yml up -d` (Postgres 17 di `127.0.0.1:54329`, role dari [infra/docker/postgres/init](../infra/docker/postgres/init/01-rt05-roles.sql)), lalu `php artisan test`.
- Tanpa Docker: tes berjalan di GitHub Actions (`api.yml`) pada setiap PR.

### Neon (dev)

Project Neon `rt05-takeda` (aws-ap-southeast-1, Postgres 17): branch `production` (kosong, belum dipakai) dan `dev` (data sintetis). Role `rt05_owner` = pemilik schema/migration; role `rt05_app` = runtime DML, dibuat lewat SQL sehingga bukan anggota `neon_superuser`.

`.env` lokal untuk Neon dev:

```dotenv
DATABASE_URL=postgresql://rt05_app:***@<endpoint>-pooler.<region>.aws.neon.tech/rt05_takeda?sslmode=verify-full
DATABASE_URL_UNPOOLED=postgresql://rt05_owner:***@<endpoint>.<region>.aws.neon.tech/rt05_takeda?sslmode=verify-full
DB_SSLMODE=verify-full
DB_SSLROOTCERT=C:/Users/<anda>/.rt05/ca-bundle.crt
```

- URL dari Neon membawa `sslmode=require`; ganti ke `verify-full` karena parameter URL mengalahkan `DB_SSLMODE`.
- `sslrootcert=system` **tidak** berfungsi pada PHP Windows ("unregistered scheme"); pakai file CA bundle (mis. salinan `C:\Program Files\Git\mingw64\etc\ssl\certs\ca-bundle.crt`) pada path **tanpa spasi**, karena DSN Laravel tidak meng-quote nilai. Image Linux (M04) dapat memakai `system` atau bundle OS.
- Setelah migration baru: `php artisan rt05:db-grant-runtime-role rt05_app` (idempotent; default privileges juga sudah diset).
- Staging/production: `/health/ready` dan `rt05:db-smoke` menolak sslmode selain `verify-full` atau tanpa `sslrootcert`.

Credential tidak pernah di-commit, ditulis ke log, atau dikirim ke website/mobile.
