# M06 — hasil backend `rest-api/` (Claude)

Tanggal: 12 Oktober 2026. Branch `codex/m06-account-access`, baseline `fb6e59f`, pohon kerja bersama root/Grok (tidak di-commit, tidak switch branch). Lingkup: hanya `rest-api/` dan laporan ini. `docs/openapi.yaml`, ERD, gateway, website dan `mobile/` tidak disentuh.

**Hasil: kode dan tes M06 backend selesai; 162/162 PHPUnit lulus pada PostgreSQL 17 lokal, Pint bersih, `check-repo` exit 0.** Bukti CI GitHub, staging, dan SMTP sampai inbox belum ada (lihat Blocker).

## Keputusan kontrak (Orca ask, disetujui root)

| # | Keputusan | Dampak bagi root |
| --- | --- | --- |
| 1 | Tautan reset `https://APP_URL/reset-password#token=…&email=…`: token dan email di **fragment**, tidak pernah di query. GET `/reset-password` tidak memakai parameter | OpenAPI: hapus query `email`/`token` dari GET `/reset-password` |
| 2 | Cookie CSRF API bernama `rt05_api_xsrf` (`RT05_XSRF_COOKIE`). `XSRF-TOKEN` website (WEB_APP_KEY) pada origin yang sama tidak lagi menimpa. Header tetap `X-XSRF-TOKEN` | OpenAPI csrf-cookie dan CMS: baca cookie `rt05_api_xsrf` |
| 3 | CSRF ketat: token selalu wajib. Tidak dilewati saat unit test, dan `Sec-Fetch-Site: same-origin` saja tidak cukup | — |
| 4 | Kolom aditif `akun.versi_sesi`; sesi cookie diperiksa versi, idle 120 menit, peran pengurus, dan status aktif | ERD: catat `versi_sesi` |
| 5 | Rotasi mingguan: `rt05:token-rotate --if-due` tiap jam (menit 5); rotasi bila token berumur ≥7 hari atau tidak ada token aktif | DEPLOYMENT: teks rotasi |
| 6 | Tidak ada kode error baru. Limiter token → 429 `TOO_MANY_REQUESTS`; race → 409 `HOUSE_ALREADY_REGISTERED`/422 `REGISTRATION_REJECTED`/409 `CONFLICT` | — |

Payload registrasi/login existing (nama Indonesia, `data.token/token_type/expires_at/akun`) tidak berubah. Respons `web-login` = `AccountEnvelope` (`data` = `Present::akun`, tanpa bearer). Route yang terdaftar cocok persis dengan OpenAPI root saat ini (`OpenApiContractTest` lulus).

## Perubahan

| Celah M06 | Implementasi |
| --- | --- |
| Registrasi atomik termasuk bearer | `AuthController::registerWithToken`: registrasi + `createToken` dalam satu `DB::transaction`. Gagal di penerbitan token → semua rekaman rollback (utama dan tambahan) |
| Rumah existing tanpa auto-merge | `Registration::primary` menolak **setiap** `alamat_key` yang sudah ada, termasuk rumah kosong/arsip buatan pengurus. Sebelumnya rumah tanpa penghunian aktif di-merge |
| Race email/alamat | Advisory lock transaksi `rt05:registrasi` + `Registration::guarded` memetakan `UniqueConstraintViolationException` (`akun_email`, `rumah_alamat_key`, `penghunian_satu_aktif`, lain-lain) ke konflik generik, bukan 500 |
| Token rotasi vs registrasi | Registrasi membaca token aktif `FOR SHARE`. Rotasi diserialkan advisory lock `rt05:token-registrasi` + `FOR UPDATE`. `ensureActive` (GET admin token pertama) dan `rotateIfDue` di bawah lock yang sama |
| Limiter kegagalan token | `TokenAttempts`: 10 kegagalan/jam per hash IP (`RT05_TOKEN_FAILURES_PER_HOUR`), dicek sebelum verifikasi; utama dan tambahan. Registrasi sukses tidak mengurangi kuota |
| Rotasi mingguan | `rt05:token-rotate --if-due` tiap jam, `withoutOverlapping()->onOneServer()`; output tidak pernah memuat kode |
| CMS cookie + CSRF | `statefulApi()` (Sanctum, origin `SANCTUM_STATEFUL_DOMAINS`, default host `APP_URL`). Group `sesi-api` (`routes/browser.php`): `GET /sanctum/csrf-cookie`, `GET /reset-password`, `POST /api/v1/auth/web-login`. `RequireCsrfToken` dipakai di group dan Sanctum. Cookie sesi default `rt05_api_session`; Secure default bila `APP_URL` HTTPS |
| web-login | Hanya pengurus aktif; selain itu 422 generik sama dengan mobile-login. `session()->regenerate()` (anti fixation), audit `akun.login_web` |
| Logout bearer/cookie | Bearer: hapus token saat ini. Cookie: logout guard web, invalidate, regenerate CSRF. `logout-all`: semua bearer + `versi_sesi`++ + sesi saat ini |
| Pencabutan sesi browser | `BrowserSession` + `EnsureAkun`: versi berbeda, idle > `SESSION_LIFETIME`, bukan pengurus, tidak aktif, atau user sesi tanpa sesi berjalan (fail closed) → sesi diakhiri, 401. Versi naik pada reset/ganti password, logout-all, ubah status admin (nonaktif juga hapus bearer), approve/reject, grant/revoke operator. Lapisan tambahan yang sudah ada: Sanctum `AuthenticateSession` (hash password) |
| Ganti password | Bearer saat ini tetap; bearer lain dicabut; semua sesi browser lama berakhir; sesi cookie pemohon di-regenerate dan diikat ke generasi baru; link reset yang masih terbuka dihapus |
| Reset password | Forgot: 202 generik untuk email terdaftar/tidak/nonaktif (hash dummy untuk menyamakan biaya). Token dibuat dalam transaksi. `ResetPasswordNotification`: `ShouldQueueAfterCommit`, `ShouldBeEncrypted`, antrean `default`. Email: tombol tautan fragment HTTPS + kode 64 karakter untuk ditempel di Android. Di luar local/testing tidak terkirim bila `MAIL_MAILER=log` (isi email masuk log) atau `APP_URL` bukan HTTPS; peringatan log tanpa token. Reset: baris `password_reset_tokens` dikunci `FOR UPDATE` (dua request token sama → satu sukses), expiry 60 menit, sekali pakai, akun nonaktif ditolak, semua bearer+sesi dicabut, audit tanpa token |
| Form browser | `GET /reset-password`: Blade tanpa aset eksternal, warna token website, `no-store`, `Referrer-Policy: no-referrer`, `X-Frame-Options: DENY`, CSP nonce (`default-src 'none'`). JS membaca fragment lalu `history.replaceState` agar token hilang dari address bar; `fetch` JSON ke `/api/v1/auth/reset-password` dengan `X-CSRF-TOKEN`; tahan submit ganda; error per field/419/offline |
| Bootstrap operator | `rt05:pengurus`: password dari env `RT05_ADMIN_PASSWORD` (divalidasi), prompt tersembunyi (interaktif), atau acak tanpa ditampilkan + email reset (non-interaktif). Password **tidak pernah dicetak** (sebelumnya dicetak sekali). Grant hanya akun aktif; revoke menolak pengurus aktif terakhir; idempoten; advisory lock + `FOR UPDATE`; semua diaudit (`kanal: cli`, sumber password tanpa nilai) |

File baru: `app/Domain/Identity/TokenAttempts.php`, `app/Http/Controllers/BrowserController.php`, `app/Http/Middleware/RequireCsrfToken.php`, `app/Notifications/ResetPasswordNotification.php`, `app/Support/BrowserSession.php`, `database/migrations/2026_10_12_000100_akun_versi_sesi.php` (aditif), `resources/views/reset-password.blade.php`, `routes/browser.php`, enam `tests/Feature/M06*Test.php`, `tests/Support/{Browser,CommittedData,Concurrent}.php`, `tests/Support/race-worker.php`.

File diubah: `.env.example`, `README.md`, `app/Console/Commands/PengurusAccount.php`, `app/Domain/Identity/{Registration,RegistrationToken}.php`, `app/Http/Controllers/{AuthController,Admin/ResidentsController}.php`, `app/Http/Middleware/EnsureAkun.php`, `app/Models/Akun.php`, `app/Providers/AppServiceProvider.php` (hapus deep link `rt05takeda://` lama), `bootstrap/app.php`, `config/{rt05,sanctum,session}.php`, `routes/console.php`.

## Tes (62 baru; total 162)

| Berkas | Isi |
| --- | --- |
| `M06ConcurrencyTest` (6) | **Proses PHP independen** (`race-worker.php`, koneksi Postgres sendiri), dilepas bersamaan oleh barrier advisory lock dan diverifikasi lewat `pg_locks`. Kasus: 6 alamat sama ditulis berbeda → tepat 1×201 + 5×409, tanpa rumah/keluarga/akun yatim; 6 email sama (kapital/spasi) → 1×201 + 5×422; rotasi di tengah registrasi yang ditahan 2 detik → tidak ada registrasi token lama setelah dicabut, minimal satu sukses dan satu ditolak; 6× GET token pertama → satu token; 4 rotasi → satu aktif; 4 reset token sama → 1×200 + 3×422 |
| `M06BrowserHttpTest` (2) | **Server `php -S` terpisah, `APP_ENV=local`** (tidak ada bypass unit test), klien Guzzle + cookie jar: csrf-cookie → web-login tanpa token 419, `Sec-Fetch-Site` saja 419, token benar 200; `/me` 200; origin asing 401; logout tanpa token 419 (sesi tetap); dengan token 204 → 401. Form reset: POST tanpa CSRF 419, dengan token halaman lolos CSRF (422 token fiktif) |
| `M06SessionTest` (19) | Cookie terpisah (`rt05_api_session` HttpOnly/Lax, `rt05_api_xsrf` terbaca JS, bukan `XSRF-TOKEN`); matriks CSRF termasuk cookie XSRF website; ID sesi berganti saat login; login generik untuk warga/nonaktif/salah; origin tanpa header/asing 401; bearer tanpa CSRF; logout per sesi; logout-all dari HP/browser; reset; status nonaktif dan reaktivasi; revoke operator; peran diubah langsung; ganti password dari HP/browser (rebind, cookie lama ditolak); idle 119/121 menit (TTL store 24 jam agar membuktikan cek aplikasi); bearer 30 hari; akun menunggu terbatas |
| `M06RegistrationTest` (14) | Kontrak `MobileAuthEnvelope`, semua rekaman + bearer berfungsi; kegagalan token perangkat rollback semua tabel (utama/tambahan); rumah kosong/arsip 409 tanpa penghunian baru; pelanggaran unik dari penulis lain → generik + rollback; leading zero `004217` vs `4217`; token dicabut/kedaluwarsa generik; limiter per IP + utama/tambahan + reset setelah 61 menit; jadwal `5 * * * *` `--if-due` 6/7 hari + catch-up kedaluwarsa; GET token admin no-store, sekali buat, audit tanpa kode |
| `M06PasswordResetTest` (13) | Respons identik untuk email terdaftar/tidak/nonaktif; after_commit/terenkripsi/antrean default; rollback tanpa email (transport array nyata) + kontrol positif; isi email (HTTPS fragment, tanpa `?`, kode); render mail lengkap; sukses sekali → cabut akses + audit tanpa token, pakai ulang 422; salah/kedaluwarsa 61 menit/nonaktif; ganti password membatalkan link; tidak ada token di log (`MessageLogged`); mailer log/HTTP tidak mengirim di staging; header form; CSRF form; kode Android |
| `M06OperatorTest` (8) | Env password tidak tercetak + audit; tanpa password → email reset; password lemah/duplikat/email/aksi salah; prompt tersembunyi; grant mencabut token/sesi, idempoten, akun nonaktif ditolak; revoke pengurus terakhir ditolak; approve/reject menaikkan versi sesi; API tidak memberi admin |

**Uji mutasi** (kode dikembalikan dan diverifikasi identik dengan `diff` setelah tiap percobaan):

- Tanpa advisory lock registrasi, race alamat menghasilkan 409 `CONFLICT` campuran (2/2 run gagal). Race terbukti nyata, dan constraint mencegah 500.
- Tanpa `FOR SHARE` token, tes rotasi gagal 3/3.
- Tanpa lock baris reset, tes reset concurrent gagal 2/2.
- Tanpa cek idle, tes idle gagal.
- Tanpa cek `versi_sesi`, tes logout-all dan reaktivasi gagal.
- Tanpa override `hasValidOrigin`, tes CSRF in-process dan HTTP nyata gagal.
- Tanpa `ShouldQueueAfterCommit`, tes antrean dan rollback gagal.

Temuan selama uji mutasi: `Mail::fake()` mengabaikan email notifikasi mentah, sehingga tes rollback awal tidak membuktikan apa pun. Tes itu diganti ke transport array nyata.

## Perintah dan hasil

Lokal tidak memiliki Docker/Postgres. Untuk tes digunakan Postgres **17.10** sementara (binari npm `@embedded-postgres/windows-x64@17.10.0-beta.17`) di scratchpad sesi, `127.0.0.1:54329`. Role/database direplikasi dari `infra/docker/postgres/init/01-rt05-roles.sql` + `infra/sql/grant-runtime-role.sql` lewat PDO. Tidak ada Neon; `migrate:fresh` hanya oleh `tests/bootstrap.php` pada `rt05_test` lokal.

| Perintah (dari `rest-api/` kecuali disebut) | Hasil |
| --- | --- |
| `php artisan test` sebelum perubahan | 100/100 lulus, 785 assertion |
| `php artisan test` akhir (2× berturut, lalu sekali setelah tes approve/reject) | 161/161 lulus (2×, ±28 detik); akhir **162/162 lulus, 1275 assertion**. Setelah suite, tabel identitas/audit/outbox `rt05_test` kosong (tidak ada sisa data commit) |
| `vendor/bin/phpunit --filter=…` per kelas M06 dan run mutasi | Lihat bagian Tes |
| `php vendor/bin/pint --test` | passed |
| `node infra/scripts/check-repo.mjs` (root) | `OK: 615 berkas dilacak …`, exit 0 |

## Kebutuhan CI (`.github/workflows/api.yml`, milik root)

Tidak perlu perubahan workflow. Tes memakai service Postgres 17, `DATABASE_URL`, dan `DATABASE_URL_UNPOOLED` yang sudah ada.

- **Proses worker** mewarisi `DATABASE_URL` runtime. `DATABASE_URL_UNPOOLED` dikosongkan untuk worker, jadi worker tidak punya koneksi migrasi.
- **Server `php -S`** dijalankan dari `public/` dengan router Laravel, di port acak loopback.
- **Pembersihan** memakai `pgsql_migrations`. Trigger append-only `audit_logs` dimatikan dan dinyalakan lagi di dalam satu transaksi pembersihan, dan hanya pada `*_test`.
- **Ekstensi PHP**: tidak ada yang baru. Guzzle dan Symfony Process sudah ada di vendor.
- **Waktu suite** bertambah ±11 detik.

Yang belum terbukti: run CI GitHub di Linux. Lokal adalah Windows, dan `php -S` di sana single-thread; race dibuktikan oleh worker, bukan oleh server.

## Untuk root

1. OpenAPI: hapus query params GET `/reset-password`. Ganti deskripsi csrf-cookie (`rt05_api_xsrf`, header `X-XSRF-TOKEN`; form reset memakai `X-CSRF-TOKEN` dari halaman). Opsional: catat 429 limiter token pada register/additional-register.
2. ERD/DATABASE: `akun.versi_sesi integer NOT NULL DEFAULT 1 CHECK (>= 1)`.
3. `docs/DEPLOYMENT.md:114` masih menyebut "password sementara tampil sekali". Sekarang password tidak pernah dicetak: gunakan `RT05_ADMIN_PASSWORD=… php artisan rt05:pengurus buat <email> --no-interaction` atau mode email reset. `:129` `rt05:token-rotate` tetap valid (rotasi segera).
4. Compose: `SESSION_COOKIE`/`SANCTUM_STATEFUL_DOMAINS` sudah sesuai. `RT05_XSRF_COOKIE` opsional (default `rt05_api_xsrf`). Migration `versi_sesi` perlu dijalankan pada deploy staging.
5. CMS (M14): `GET /sanctum/csrf-cookie`, lalu kirim cookie `rt05_api_xsrf` sebagai `X-XSRF-TOKEN`. Jangan memakai `Referrer-Policy: no-referrer` pada halaman CMS, karena GET dari CMS dikenali lewat Referer/Origin.

## Blocker dan keterbatasan

- **SMTP sampai inbox tidak terbukti.** Pemilik belum memberi konfigurasi SMTP. Mailer array/fake hanya membuktikan isi dan antrean. Staging dengan `MAIL_MAILER=log` sengaja tidak mengirim email reset.
- Belum ada run CI GitHub, deploy staging, maupun uji browser sungguhan (Chrome) terhadap staging. Bukti HTTP di sini memakai Guzzle sebagai peramban.
- Cek-lalu-hit limiter token tidak atomik: burst paralel bisa sedikit melewati 10. Ini tetap dibatasi throttle registrasi 5/10 menit per IP.
- Hal yang sudah ada sebelumnya dan tidak diubah:
  - waktu mobile-login/web-login untuk email tak dikenal lebih cepat (tanpa `Hash::check`);
  - limiter `lupa-password` 3/15 menit dipakai bersama oleh forgot dan reset;
  - `updateAccount` memeriksa status `menunggu` sebelum lock. Ini area M07.
- `kode_rumah` yang bentrok dengan `storeHouse` admin secara concurrent menghasilkan 409 `CONFLICT` generik. Registrasi sendiri diserialkan.
- Insiden credential, production, dan data warga nyata tetap tertahan (M16). M07 tidak disentuh.
