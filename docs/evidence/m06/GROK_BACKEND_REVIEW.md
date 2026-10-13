# Tinjauan silang backend dan integrasi M06

Sumber beku `8d0b3df8f73860b66277fed1494f196d47525d4a`. `git rev-parse HEAD` mengembalikan SHA yang sama. `git status --short` hanya `?? .agents/`, dan folder itu tidak diubah. Tanggal tinjauan 12 Oktober 2026.

Tinjauan membaca [CONTRACT.md](CONTRACT.md), [CLAUDE_FIXES.md](CLAUDE_FIXES.md), berkas `rest-api` yang berubah pada commit ini, tes M06, [openapi.yaml](../../../docs/openapi.yaml), [API.md](../../../docs/API.md), [gateway.Caddyfile](../../../infra/docker/gateway.Caddyfile), [compose.production.yml](../../../infra/compose.production.yml), dan [deploy-staging-immutable.sh](../../../infra/scripts/deploy-staging-immutable.sh). PHPUnit, Pint, CI GitHub, staging, emulator, dan SMTP tidak dijalankan. Postgres portabel tidak dihidupkan.

## Temuan

### Sedang — bootstrap pengurus non-interaktif tanpa password env terkunci bila mailer menulis log

`rt05:pengurus buat` tanpa `RT05_ADMIN_PASSWORD` dan tanpa terminal interaktif menyimpan password acak yang tidak dicetak, memanggil `sendResetLink`, lalu menulis "Email reset diantrekan". Notifikasi baru menolak kirim di `shouldSend`, setelah commit, bila mailer `log`, failover/roundrobin yang memuat `log`, atau nama mailer tidak dikenal. Tidak ada perintah susulan untuk mengganti password. Token reset berlaku 60 menit.

[compose.production.yml](../../../infra/compose.production.yml) dan [production.env.example](../../../infra/env/production.env.example) memakai default `MAIL_MAILER=log`. Tes operator yang menutup jalur ini memakai `Notification::fake()`, sehingga penolakan `shouldSend` tidak ikut terbukti.

Setelah SMTP nyata terpasang, `POST /auth/forgot-password` dapat mengantre token baru. Sampai saat itu akun pengurus hasil jalur ini tidak dapat masuk.

- Berkas: [PengurusAccount.php](../../../rest-api/app/Console/Commands/PengurusAccount.php) (`passwordSource`, pesan sukses), [ResetPasswordNotification.php](../../../rest-api/app/Notifications/ResetPasswordNotification.php) (`shouldSend`).
- Repro: `MAIL_MAILER=log`, jalankan `php artisan rt05:pengurus buat nama@contoh.test --no-interaction` tanpa `RT05_ADMIN_PASSWORD`. Keluar 0 dan teks antrean muncul. Log worker hanya berisi peringatan tanpa token. Login dengan password yang diketahui gagal. Setelah 60 menit baris `password_reset_tokens` tidak lagi berlaku.

### Rendah — `current_password` masih menerima sufiks di atas 72 byte

Login dan web-login menolak input lebih dari 72 byte di `credentialsMatch` sebelum `Hash::check`. `PATCH /auth/password` dan `POST /auth/logout-all` memakai `current_password:sanctum`, yang memanggil `Hash::check` tanpa batas byte itu.

Pada PHP 8.4.13 di mesin tinjauan, `password_verify` atas hash bcrypt 72 byte mengembalikan true bila plaintext ditambah sufiks. `password_verify` yang berisi NUL mengembalikan false. `password_hash` dengan NUL melempar `ValueError`. Login dengan NUL tetap jawaban kredensial salah. Konfirmasi password saat ini dengan 72 byte plus sufiks lolos bcrypt.

- Berkas: [AuthController.php](../../../rest-api/app/Http/Controllers/AuthController.php) (`logoutAll`, `changePassword`, `credentialsMatch`).
- Repro: simpan password persis 72 byte UTF-8, masuk, lalu kirim `PATCH /api/v1/auth/password` atau `POST /api/v1/auth/logout-all` dengan `current_password` = 72 byte itu ditambah `Z`. Login dengan string yang sama ditolak 422. Mutasi dengan `current_password` itu diterima.

### Rendah — batas gagal token dan lupa password dapat ketat atau longgar di luar angka dokumen

`TokenAttempts::ensureAllowed` membaca limiter sebelum transaksi. Hit cache terjadi setelah transaksi gagal dan advisory lock registrasi lepas. Permintaan paralel dari satu IP sama-sama lolos pemeriksaan, lalu masing-masing dihitung. Ruang enam digit tetap besar, tetapi angka 10 kegagalan per jam di [API.md](../../../docs/API.md) tidak tertahan pada burst.

`POST /auth/reset-password` memakai limiter `lupa-password` yang sama dengan lupa password: 3 permintaan per 15 menit per IP dan email. Satu lupa password ditambah dua submit form yang gagal membuat reset berikutnya, termasuk token yang masih berlaku, membalas 429.

- Berkas: [AuthController.php](../../../rest-api/app/Http/Controllers/AuthController.php) (`registerWithToken`), [TokenAttempts.php](../../../rest-api/app/Domain/Identity/TokenAttempts.php), [routes/api.php](../../../rest-api/routes/api.php), [AppServiceProvider.php](../../../rest-api/app/Providers/AppServiceProvider.php).
- Repro token: lebih dari 10 `POST /auth/register` paralel, token enam digit salah, IP sama. Jumlah 422 `REGISTRATION_TOKEN_INVALID` dapat melebihi 10 sebelum 429.
- Repro reset: tiga permintaan `forgot-password` atau `reset-password` untuk email yang sama dari IP yang sama dalam 15 menit. Permintaan keempat 429 walaupun token reset masih berlaku.

### Rendah — gateway menimpa `Referrer-Policy: no-referrer`

Halaman reset mengirim `Referrer-Policy: no-referrer`, CSP `default-src 'none'` dengan nonce, dan token hanya di fragment. Blok `header` gateway menetapkan `Referrer-Policy strict-origin-when-cross-origin` untuk setiap respons, termasuk hasil proxy. Bentuk direktif itu menimpa header upstream yang senama. Tes `php -S` melihat `no-referrer` karena tidak melewati gateway.

Meta `referrer` di HTML tetap `no-referrer`, dan fragment tidak masuk Referer, jadi token tidak ikut header ini. Header yang dilihat browser lewat gateway berbeda dari kalimat OpenAPI dan tes langsung.

- Berkas: [gateway.Caddyfile](../../../infra/docker/gateway.Caddyfile), [BrowserController.php](../../../rest-api/app/Http/Controllers/BrowserController.php).
- Repro: `GET /reset-password` melalui gateway, baca `Referrer-Policy`.

## Sesuai kontrak pada pembacaan sumber

Angka PHPUnit Claude tidak diulang sebagai hasil tinjauan ini.

- Rotasi mengambil advisory lock `rt05:token-registrasi` lalu `rt05:registrasi`. Registrasi hanya mengambil lock kedua, kemudian `FOR SHARE` pada token aktif. Rotasi memakai `FOR UPDATE`. Urutan itu tidak membentuk deadlock. Kode baru menolak digest token aktif dan token terakhir, batas 50 percobaan, lalu exception yang menggulung transaksi. Scheduler `rt05:token-rotate --if-due` berjalan tiap jam; rotasi saat umur 7 hari; masa berlaku 8 hari.
- Penolakan menyimpan alasan di `catatan`, status permohonan `ditolak`, akun tetap `tambahan` / `menunggu` / `warga_id` null, bearer dihapus, dan `versi_sesi` naik. Alasan pemohon hanya keluar bila status permohonan `ditolak`. Rumah, pengaduan, aspirasi, dan logout-all berada di grup akun aktif. Setuju setelah status bukan `diajukan` mengembalikan 409 `REQUEST_ALREADY_PROCESSED`. Permohonan utama sudah `disetujui`.
- Cookie sesi `rt05_api_session` HttpOnly, SameSite `lax`, path `/`, domain host-only. Cookie CSRF `rt05_api_xsrf` dapat dibaca CMS dan dikirim sebagai `X-XSRF-TOKEN`. Compose mengunci `SESSION_SECURE_COOKIE=true` dan lifetime 120. Nama ini terpisah dari `XSRF-TOKEN` dan cookie sesi website. `RequireCsrfToken` menolak bypass tes dan bypass `Sec-Fetch-Site: same-origin`. Sanctum memakai kelas yang sama. Gateway meneruskan `/api`, `/api/*`, `/health/*`, `/sanctum/csrf-cookie`, `/reset-password`, dan `/auth-assets/*` ke `api:80`. Cookie aplikasi lain yang gagal didekripsi diubah menjadi null.
- `versi_sesi` naik pada reset, logout-all, ganti password, ubah status, grant/revoke peran, setuju, dan tolak. Pemeriksaan cookie membandingkan generasi, idle `session.lifetime`, peran pengurus, dan status aktif.
- Password baru menolak NUL, menolak di atas 72 byte UTF-8, lalu menuntut minimal 8 karakter Unicode serta huruf dan angka. Aturan mobile memakai urutan yang sama (`runes.length`, `\p{L}`, `\p{N}`). Hasher bcrypt memakai `limit` 72. Login tetap `max:200` ditambah `credentialsMatch`. `NewPassword.maxLength` 72 di OpenAPI adalah batas karakter; deskripsi schema menyebut pemeriksaan byte.
- Reset mengantre `ShouldQueueAfterCommit` dan `ShouldBeEncrypted` pada antrean `default`. Tautan memakai fragment. Masa berlaku 60 menit. Pemakaian ulang dikunci `lockForUpdate` lalu broker. `shouldSend` menolak transport log di setiap environment dan menolak `APP_URL` non-HTTPS di luar local/testing. Kalimat lupa password sama dengan kalimat antrean mobile.
- Aset halaman tiga berkas same-origin. SHA-256 cocok dengan sumber website: logo `61376072a1208ad46ea1840cf7701ead7e0fdfa9ea2120667fe7bbf089bcfb98`, Public Sans `5ed4d31c988e73b258894244f209069ebe77dc7e564861954b21198b6de90d68`, Bricolage `a97804dc9fbe5fc972a08018c5eda4dab7ef2346f64c57e61419d05e6de4ea1c`. Indeks Git menandai woff2 sebagai `-text`. `.dockerignore` tidak membuang `public/auth-assets/`. Image API menyalin pohon `rest-api/`.
- Perintah operator tidak menyimpan password bawaan. Audit mencatat kanal `env`, `prompt`, atau `email_reset`. Env dan prompt melewati `PasswordPolicy`. Grant/revoke mengunci baris, mencabut bearer dan sesi browser, dan menolak mencabut pengurus aktif terakhir.
- Skrip deploy menolak argumen `production`, membaca env staging mode 600, dan tidak mencetak nilai secret. SHA harus ancestor `origin/main` atau branch `codex/m04-staging-recovery` / `codex/m06-account-access`. Pola `RT05_ENV` menerima `staging` atau `stg`.

## CI Linux

[api.yml](../../../.github/workflows/api.yml) memakai `ubuntu-latest`, PHP 8.4, Postgres 17, dan `DATABASE_URL` host `127.0.0.1` database `rt05_test`. Allowlist [race-worker.php](../../../rest-api/tests/Support/race-worker.php) memuat host itu dan sufiks `_test`. Worker concurrency tidak bergantung pada pemisah path Windows. Workflow berjalan pada pull request atau push `main`. Pembacaan statis tidak menemukan cacat yang pasti menggagalkan job tersebut. Setelah laporan ini disusun, koordinasi melaporkan CI API 171 tes / 1461 assertion, build infra, 107 tes mobile, serta APK dev dan staging lulus pada SHA yang sama, tanpa rollout staging. Angka itu dicatat dari pesan `msg_e30e84a3a3ef`; log GitHub tidak dibuka ulang di tinjauan ini.

## Pesan koordinasi

`msg_e30e84a3a3ef` meminta tinjauan backend selesai lebih dulu dan menahan perbaikan mobile. Temuan mobile di [CLAUDE_MOBILE_REVIEW.md](CLAUDE_MOBILE_REVIEW.md) (race logout, bearer hilang saat kembali dari registrasi, subtitle penolakan) tidak diubah di tugas ini.

## Batas

Staging, emulator, inbox SMTP, dan production tidak dijalankan. Gate SMTP tetap di owner. M07 dan insiden credential tidak dibuka. SHA ini tidak dibandingkan dengan `origin` pada tinjauan ini. Deploy tetap mengikuti syarat ancestor di skrip. Rollout staging belum ada menurut pesan koordinasi di atas.

## Perintah tinjauan

- `git rev-parse HEAD` → `8d0b3df8f73860b66277fed1494f196d47525d4a`
- PHP 8.4.13: `password_verify` dengan NUL = false; `password_hash` dengan NUL = `ValueError`; `password_verify` 72 byte ditambah sufiks = true
- SHA-256 tiga aset auth cocok dengan berkas sumber website
- `node infra/scripts/check-repo.mjs` → `OK: 631 berkas dilacak; tanpa file terlarang, pola secret, .git bersarang, atau tautan relatif rusak; vercel.json setara.`
