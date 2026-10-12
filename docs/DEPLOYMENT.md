# Deployment dan operasi — Hostinger / morriz.tech

## Target dan status

Target aplikasi: https://takeda.morriz.tech; staging https://takeda-staging.morriz.tech.
Domain utama morriz.tech adalah koreksi eksplisit pemilik pada 10 Oktober 2026.
VPS Hostinger disebut RAM 8 GB/storage 100 GB dalam konteks; kapasitas bebas/CPU/OS/service harus diinspeksi sebelum alokasi. Dokumen ini tidak menyatakan DNS atau service sudah dikonfigurasi.

Neon berada di luar VPS. Mobile berjalan di HP. VPS menjalankan website, API, worker, scheduler, Redis dan media; dapat menyajikan APK.

## Inspeksi sebelum deploy

Catat OS, Docker/Compose, reverse proxy existing, Coolify/panel jika ada, ports/networks, free RAM/disk, restart policy, service lain termasuk agent pengguna, backup destination, DNS records, dan hak deploy.

Jangan install ulang OS, stop service lain, flush firewall, atau menjalankan proxy kedua pada 80/443. Jika Coolify sudah terpasang, reuse routing/resource Compose yang didukung. Jika tidak ada orchestrator, Docker Compose + satu reverse proxy adalah default. Tidak membuat cluster/Kubernetes.

## Docker topology

| Service | Build/source | Catatan |
| --- | --- | --- |
| gateway | Config route/TLS | Reuse proxy existing bila tersedia |
| web-nginx | website public/static | FPM web upstream |
| web | website image PHP-FPM | Rendering Blade; tidak punya DB warga |
| api-nginx | API public/index.php | FPM API upstream |
| api | rest-api image PHP-FPM | HTTP runtime |
| worker-default | image API sama | Notifications/default |
| worker-media | image API sama | Transcode |
| worker-exports | image API sama | CSV |
| scheduler | image API sama | schedule:work single instance |
| redis-queue | Redis pinned supported image | AOF, volume, noeviction |
| redis-cache | Redis pinned supported image | Cache/session/limiter |
| migrate | image API sama, one-shot | Direct DB connection; no restart |

PHP image memakai ext pdo_pgsql, redis, mbstring, intl, fileinfo, pcntl untuk worker, image library yang dibutuhkan. Pin version/digest setelah build diuji. Node build stage menghasilkan Vite assets; composer install --no-dev di final image. Tidak vendor/node_modules dari mesin pengguna ke image.

web/API mempunyai APP_KEY sendiri. Worker/scheduler memakai APP_KEY dan config API yang sama. Queue/cache/session connection dipetakan eksplisit di config Laravel.

## Resource awal

Baseline planning, setelah mengukur headroom:

| Service kelompok | Batas awal rancangan |
| --- | --- |
| API FPM | 768 MiB; jumlah child disesuaikan memory |
| Web FPM | 384 MiB |
| Worker default/media/exports | 256/512/256 MiB, masing-masing satu proses |
| Scheduler | 192 MiB |
| Redis queue/cache containers | 384/256 MiB termasuk overhead |
| Nginx/proxy | Ukur pemakaian; limit sesuai host |

Sisakan headroom OS dan service existing. Angka bukan benchmark; memory tinggi pada foto/exports diukur M15. Set log rotation, disk alert >80%, dan quota staging/media agar storage 100 GB tidak habis oleh logs/build layers.

## Environment dan jaringan

Production dan staging tidak berbagi database branch, prefix Redis, volume media, APP_KEY, session cookie, device token, FCM project/topic, atau outbound recipient settings.

Staging push/email memakai test recipient/project; tidak mengirim notifikasi ke warga production. Staging host noindex dan access terbatas sesuai kebutuhan demo.

Hanya API/worker menyimpan database URL. Runtime pooled; migrate/backup direct role. FCM service account file mount read-only. Secret tidak dikirim sebagai build arg yang masuk layer image.

`DATABASE_URL_UNPOOLED` hanya diberikan ke `migrate`, tidak ke API/worker/
scheduler. `python3 scripts/check_database_isolation.py` memeriksa JSON
`docker compose config --format json` sebelum build/rollout; jangan menampilkan
hasil config dengan env nyata. Smoke direct menggunakan
`docker compose ... run --rm --no-deps --pull never migrate php artisan rt05:db-smoke --connection=pgsql_migrations`.
Root `.dockerignore` wajib dipakai; `.env` dan cached PHP config tidak masuk
build context. Jangan memakai cache/image lama sebagai bukti bebas credential.

Health:

- live: proses HTTP sehat tanpa memanggil DB.
- ready: dependency tersedia dengan timeout, tidak memaparkan hostname/credential.
- workers: heartbeat updated oleh loop/job; scheduler heartbeat.
- public status endpoint minimal; detail operasi hanya internal.

## DNS dan TLS

1. Setelah akses DNS diotorisasi, buat A record takeda dan takeda-staging ke IP VPS yang benar.
2. Jangan mengubah apex morriz.tech/MX/domain service lain.
3. Jangan menambah AAAA kecuali IPv6 server dan routing sudah berfungsi.
4. Proxy menerbitkan sertifikat TLS host tersebut; uji renewal dan HTTPS redirect.
5. Verifikasi dari jaringan luar: public routes, /api/v1, csrf cookie, no internal ports.
6. Trusted proxy/forwarded headers dan secure cookie diuji di domain nyata.

Tidak mencantumkan IP/credential palsu dalam config. Agent menulis placeholder dan daftar input yang dibutuhkan.

## Staging awal M04

Deploy fondasi web/API/Redis/worker/scheduler dengan data sintetis. Uji health, koneksi Neon staging, job benar-benar dieksekusi, persist queue setelah restart, dan web baseline. Belum diumumkan sebagai sistem warga production.

Adapter FCM/SMTP dapat fake secara eksplisit sampai credential tersedia; test nyata wajib sebelum feature disebut operational.

### Kondisi aktual VPS (diinspeksi 10 Oktober 2026)

- Hostinger KVM 2 `srv2030339.hstgr.cloud`, IP 187.77.113.136, Ubuntu 24.04 dengan Coolify. Pemakaian saat inspeksi: CPU 9%, memori 23%, disk 14/100 GB.
- Proxy Coolify (Traefik) memegang port 80/443 (menjawab 404/503 untuk host tanpa aplikasi). Panel Coolify `:8000` tidak terjangkau dari luar; firewall Hostinger tanpa aturan.
- DNS dibuat atas izin pemilik: A `takeda` dan `takeda-staging` → 187.77.113.136 (TTL 300). Apex `@` (2.57.91.91) dan `www` tidak diubah. Resolusi lewat 8.8.8.8 terverifikasi.
- Neon: branch `production` (kosong, role runtime `rt05_app` dibuat via SQL), `staging` (salinan dev berisi data demo sintetis), `dev`.

### Runbook deploy (dijalankan pemilik di Konsol web Hostinger)

Stack RT05 berjalan sebagai project Docker Compose tersendiri (`rt05-staging` / `rt05-production`) di belakang proxy Coolify melalui label Traefik pada jaringan `coolify` (`infra/compose.vps.yml`). Tidak ada port host baru; service lain tidak disentuh.

1. hPanel → VPS → Ringkasan → **Konsol web** (login root).
2. Jalankan:

   ```bash
   curl -fsSL https://raw.githubusercontent.com/morrispes5/RT-05-TAKEDA/main/infra/scripts/deploy-vps.sh -o /root/rt05-deploy.sh
   bash /root/rt05-deploy.sh staging
   ```

3. Saat diminta, tempel isi `infra/env/.env.staging` dari PC pengembang (file rahasia, di-gitignore), lalu Ctrl-D. File disimpan di `/root/.rt05/.env.staging` (mode 600).
4. Verifikasi dari luar: `https://takeda-staging.morriz.tech/health/ready` → `{"status":"ready"...}`, `/profil` tampil, aplikasi mobile build dengan `API_BASE_URL=https://takeda-staging.morriz.tech`.
5. Production tetap mengikuti gate M16. Bootstrap pengurus pertama memakai
   `docker compose -p rt05-production ... exec api php artisan rt05:pengurus buat <email>`.
   M06 tidak mencetak password: operator memberi password melalui prompt
   tersembunyi atau env `RT05_ADMIN_PASSWORD` yang dikelola privat. Mode
   noninteraktif tanpa password memakai email reset dan memerlukan SMTP nyata.
6. Update: jalankan ulang perintah langkah 2 (pull kode, build, migrasi additive, restart).

Rollback cepat: `docker compose -p rt05-<env> ... down` menghentikan stack RT05 saja (volume tetap). Jangan `docker system prune` atau menghapus volume.

### Rotasi credential (dijalankan pemilik)

Branch Neon menyalin role beserta password dari induknya, jadi `rt05_owner` di production, dev, dan staging awalnya berbagi password yang sama. Setelah sebuah env bocor, rotasi semua branch yang berbagi password.

1. Neon Console → project rt05-takeda → Branches → pilih branch → Roles → `rt05_owner` → Reset password. Salin password baru (hanya tampil sekali) ke bagian password `DATABASE_URL_UNPOOLED` di `infra/env/.env.<env>`. Untuk dev, perbarui `rest-api/.env`.
2. Jika `rt05_app` ikut bocor (staging/dev berbagi password), reset juga lalu perbarui `DATABASE_URL`.
3. APP_KEY baru dibuat/disimpan oleh pemilik dalam konsol privat/secret store,
   tanpa output tool agent/screenshot. `API_APP_KEY` dan `WEB_APP_KEY` berbeda
   per lingkungan. Backup kunci/konfigurasi lama secara terenkripsi untuk
   pemulihan terkontrol, inventaris data terenkripsi sebelum mengganti key.
   Token registrasi staging dibuat ulang melalui `rt05:token-rotate` sesudah
   key baru aktif; jangan menghapus data bisnis untuk memudahkan rotasi.
   Jangan meninggalkan key yang terpapar pada `APP_PREVIOUS_KEYS` runtime:
   key tersebut masih dapat dipakai mendekripsi/menerima payload lama.
   APP_KEY baru tidak otomatis mencabut bearer Sanctum; revokasi token/sesi
   terdampak memerlukan tindakan operator terpisah yang tercatat.
4. Pemilik memperbarui env privat server secara atomik, mode600/direktori700,
   menjaga field lain dan salinan pemulihan; **jangan menghapus env existing
   terlebih dahulu**. Tidak menempel nilai melalui percakapan/agent/browser
   yang sedang dibaca agent. Setelah patch merged dan CI sukses, gunakan
   rollout staging SHA tetap; production mengikuti gate M16 sendiri.
5. Recreate lima container runtime RT05 agar env/config cache lama hilang;
   verifikasi `/health/ready`, DML smoke dengan DDL denied, heartbeat, serta
   env runtime tidak mempunyai direct credential. Direct smoke hanya job
   migrasi sementara. Audit akses dan tutup sesi DB lama yang terkait role
   terdampak dengan tindakan operator yang dibatasi pada environment RT05.

Penolakan otomatis sebelumnya atas penulisan secret store tetap berlaku:
agent menyiapkan patch/runbook dan bukti nonsecret, tidak menyiasati penolakan
dengan browser/helper lain. Sampai pemilik melakukan rotasi yang sah, insiden
tetap open. Bukti penutupan hanya tanggal/environment/nama role atau key,
hasil penolakan credential lama, readiness/role baru, revokasi sesi dan audit;
**tanpa nilai password/APP_KEY/DSN atau data warga**. Build/migrasi sukses
bukan bukti rotasi.

## Staging M06: akses akun dan reset

Gunakan `infra/scripts/deploy-staging-immutable.sh <sha40>` pada konsol Hostinger
setelah SHA dipush pada branch `codex/m06-account-access`, review dan CI lulus.
Script hanya menerima ancestry main atau branch staging yang disebut persis;
gunakan SHA penuh, bukan branch bergerak. Script tidak mengubah env privat,
worktree lama, volume, DNS, proxy, port host, atau service aplikasi lain.
Migration `akun.versi_sesi` additive dijalankan oleh job migrator sekali pakai;
credential direct tetap tidak diberikan kepada API/worker/scheduler.

Setelah rollout, catat SHA image, readiness, heartbeat worker/scheduler, smoke
DML dengan DDL ditolak, serta volume dan container unrelated tetap tersedia.
Browser pada origin staging memanggil `GET /sanctum/csrf-cookie`, lalu
`POST /api/v1/auth/web-login` dengan header `X-XSRF-TOKEN` dari cookie
`rt05_api_xsrf`. Cookie sesi `rt05_api_session` HttpOnly/Secure/Lax. Tanpa
CSRF, mutasi cookie wajib419; bearer mobile tidak memerlukan CSRF. Uji
logout/logout-all/ganti password dan penolakan sesi lama sesudahnya.

Form reset tersedia di `/reset-password`, aset brand di `/auth-assets/*`.
Link email menyimpan email/token pada fragment HTTPS yang dibaca JS dan
dihapus dari address bar; jangan menaruh token pada query atau log. Android
dapat memakai kode reset dari email pada form aplikasi. Reset sekali pakai
berlaku60menit dan mencabut semua sesi. Respons forgot202 hanya menyatakan
permintaan diterima, bukan bukti email terkirim.

SMTP diisi oleh operator pada env staging privat, dengan inbox uji sintetis.
Gunakan `MAIL_MAILER=smtp` dan konfigurasi host/port/scheme/login/from yang
benar-benar tersedia. Jangan menampilkan env atau mengirim secret lewat chat.
Mailer log dilarang membawa token reset; adapter array hanya untuk tes.
Worker-default harus benar-benar mengirim antrean, lalu email diterima di
inbox uji dan link/kode dapat dipakai sekali. Catat timestamp dan screenshot
aman tanpa token/password, bukan isi secret. Tanpa bukti inbox penerimaan
reset tetap blocked, M06 belum passed, dan M07 belum dibuka.

## Pipeline rilis

1. CI lint/tests/contract/build mengeluarkan image immutable dengan tag commit SHA.
2. Deploy staging, run migration direct sekali, lalu smoke/e2e.
3. Catat artifact APK signed/checksum/version dan release notes bila mobile berubah.
4. Production: pastikan scope deploy sudah diotorisasi, backup terbaru/restore path, disk/headroom, dan schema compatibility.
5. Jalankan migration additive single instance; stop rollout jika gagal.
6. Start API/web image baru, readiness lulus, alihkan traffic; restart workers/scheduler ke image sama.
7. Run smoke finance tanpa mengarang pembayaran real; gunakan transaksi terkontrol yang ditetapkan mitra atau check read-only bila data real.
8. Pantau error rate, queue/outbox, latency, DB connections, saldo reconciliation.
9. Catat tag/image/migration/environment/results pada PROGRESS dan release log.

Migration bukan per-container startup bersamaan. Jangan menjalankan seed demo otomatis production. Bootstrap admin dilakukan command operator tanpa default password, secret interactive/runtime dan audit.

## Backup

Target v1: encrypted daily DB dump + media backup off-VPS, 7 harian dan 4 mingguan sebagai rancangan. Destination harus disediakan; folder lain di VPS sama bukan offsite backup.

Backup worker memakai direct URL, client pg_dump yang kompatibel server, akses read minimal dan exit checking. Secret tidak ditempel pada command yang tercatat shell history/log.

Simpan checksum, manifest timestamp/schema/app version, keberhasilan, dan lokasi privat. Backup konfigurasi/kunci encryption/signing dikelola terpisah aman; jangan memasukkan secrets ke arsip source.

Restore drill: restore DB ke branch/instance terisolasi, restore media, konfigurasi key yang relevan, verify row counts, sample attachments, charge/payment/ledger sum, auth/reset, published content. Catat durasi terhadap target RTO 4 jam dan kehilangan data terhadap RPO 24 jam.

Queue AOF membantu restart; bukan pengganti backup DB atau outbox. RPO nyata bergantung backup/PITR provider yang tersedia dan perlu dibuktikan.

## Rollback

- Simpan image release sebelumnya dan config compatible.
- Ganti traffic/image ke versi sebelumnya; restart worker ke versi compatible event schema.
- Jangan otomatis migrate:rollback pada production setelah ada transaksi baru.
- Skema additive memungkinkan aplikasi lama tetap berfungsi; destructive schema memerlukan release terpisah.
- Release sebelum isolasi credential12 Oktober tidak lagi menjadi target
  rollback yang aman. Guard menolaknya sebelum perubahan service. Siapkan
  release kompatibel yang juga memuat patch keamanan; jangan mengembalikan
  credential DDL ke runtime demi rollback.
- Jika data rusak, hentikan mutation terkait, buat snapshot evidence, rencanakan restore/reconcile; jangan restore seluruh production hingga menghapus transaksi valid tanpa persetujuan.

## Runbook

| Gejala | Tindakan |
| --- | --- |
| Neon unavailable | Baca error aman; readiness gagal; jangan fallback ke DB kosong; mutation gagal jelas |
| Redis queue down | Domain committed tetap ada; recover Redis, replay pending/stale outbox |
| Cache down | Public read fallback terkontrol; login limiter fail closed; session mungkin perlu login ulang |
| Push/SMTP down | Retry terbatas; inbox tetap ada; jangan membalik pembayaran |
| Worker absent | Restart image benar, inspect failed jobs/heartbeat |
| Media disk penuh | Hentikan upload, alert; bersihkan orphan sesuai kebijakan; jangan hapus album referenced |
| Double payment suspect | Reconcile charge/allocations/ledger, jangan edit saldo manual |
| Credential leak | Revoke/rotate, audit akses, rebuild config; hapus dari source history dengan prosedur |

Dashboard minimum: uptime/public smoke, latency/error, queue/outbox age, worker/scheduler, failed jobs, CPU/RAM/disk, DB connections, backup success. Gunakan fasilitas existing dahulu; monitoring stack besar bukan syarat v1.

## Biaya

Neon, backup, email provider, push tooling/distribusi, domain, dan VPS dapat mempunyai limit/biaya berbeda. Paket ini tidak memesan layanan atau menjamin semua gratis. M04/M16 mencatat plan aktual, budget, dan alert biaya; layanan berbayar baru memerlukan instruksi pemilik.
