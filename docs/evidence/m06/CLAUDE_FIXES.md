# M06 — perbaikan lanjutan backend `rest-api/` (Claude)

Tanggal: 12 Oktober 2026. Task `task_fb43b380b205`, dispatch `ctx_b1ac9183547a`. Branch `codex/m06-account-access`, pohon kerja bersama. Tidak ada commit, push, atau pindah branch. Lingkup: `rest-api/` dan laporan ini. OpenAPI, ERD, gateway, `mobile/`, dan dokumen bersama tidak disentuh. Laporan pertama: [CLAUDE_RESULT.md](CLAUDE_RESULT.md).

Catatan mailbox root yang belum diproses sebelum `worker_done` pertama sudah ditangani di sini: `msg_713909677ba1`, `msg_1fead281825c`, `msg_7c5907d1cb72`, `msg_350796c64212`, `msg_54ccaa20c322`, dan `msg_79f0de0191aa` (diterima selama task ini).

**Hasil: 171/171 PHPUnit lulus (1461 assertion) pada PostgreSQL 17.10 lokal sementara (`rt05_test`), Pint passed, `check-repo` exit 0.** Setelah suite, tabel identitas/audit/outbox/token di `rt05_test` kosong.

## 1. Transport log ditolak di semua environment

Sebelumnya `ResetPasswordNotification::shouldSend` melewati semua pengaman pada `local`/`testing`. `.env` lokal yang terhubung Neon dev memakai `APP_ENV=local` dan `MAIL_MAILER=log`, jadi token reset asli akan tertulis ke log.

Sekarang:

- `shouldSend` (dijalankan di job antrean sebelum transport dipanggil) menolak di **semua** environment bila mailer memakai transport `log`. Ini termasuk mailer `failover`/`roundrobin` yang memuat `log`; `failover` bawaan Laravel adalah smtp lalu log. Mailer yang tidak dikenal juga ditolak (fail closed).
- Peringatan log tidak memuat token atau email. Respons lupa password tetap 202 generik, dan baris token tetap commit.
- Pengecualian HTTPS hanya untuk `local`/`testing` (URL sintetis), sesuai catatan root.
- Payload antrean tetap `ShouldBeEncrypted`, sehingga `failed_jobs`/Redis tidak menyimpan token polos.

Tes:

- `test_log_transport_is_refused_in_every_environment_and_http_url_outside_local`: matriks local/testing/staging/production × log/failover/roundrobin-log/tidak-dikenal/smtp, dan HTTP vs HTTPS.
- `test_local_development_with_log_mailer_never_writes_the_reset_token_to_logs` meniru `.env` lokal: `APP_ENV=local`, `MAIL_MAILER=log`, `APP_URL` http, dengan transport log asli dan pendengar `MessageLogged`.
  - Kontrol positif: email lain lewat mailer log memang tertulis ke log yang dipantau.
  - Setelah `forgot-password`, tidak ada baris log yang memuat hex 64 karakter (format token broker), `reset-password#`, atau email.
  - Peringatan dari job tercatat, dan baris token tetap commit.
- **Mutasi** (pengecualian `local` dikembalikan): kedua tes gagal. Tes lokal gagal karena log berisi `From: … To: uji54@contoh.test Subject: Reset kata sandi RT05 TAKEDA …`, yaitu isi email lengkap dengan token.

Wording lupa password diganti sesuai `msg_79f0de0191aa`. Konstanta `AuthController::FORGOT_MESSAGE` berisi "Jika email terdaftar, permintaan reset kata sandi telah diterima dan diantrekan.", identik untuk semua hasil dan sama persis dengan `forgotQueuedMessage` Flutter. Teks lama "telah dikirim" tidak tersisa di `rest-api/`.

## 2. Batas 72 byte bcrypt → 422, bukan 500

Perilaku yang diamati (PHP 8.4.13 CLI, Laravel `BcryptHasher` vendor):

| Input | `password_hash` PHP | `Hash::make` tanpa limit (sebelum) | `Hash::make` limit 72 (sekarang) |
| --- | --- | --- | --- |
| 73 byte ASCII | **dipotong diam-diam** (verify dengan prefix 72 byte + sufiks lain = true) | dipotong diam-diam | `InvalidArgumentException` |
| Berisi NUL | `ValueError: Bcrypt password must not contain null character` | `RuntimeException` → **500** | `RuntimeException` |

Catatan root menyebut ">72 byte → 500"; yang diamati, >72 byte justru terpotong diam-diam, dan 500 hanya muncul dari NUL. Keduanya kini ditutup:

- `App\Domain\Identity\PasswordPolicy::rules()`: `string`, maksimal 72 byte UTF-8 (`strlen`), tanpa NUL, dan `Password::min(8)->letters()->numbers()` (min 8 karakter Unicode tetap seperti semula). Aturan ini dipakai bersama oleh `register`, `additional-register`, `reset-password`, `PATCH auth/password`, dan `rt05:pengurus`. Pesan 422 di `error.fields.password`:
  - "Kata sandi maksimal 72 byte. Huruf dan angka biasa dihitung 1 byte; huruf beraksen atau emoji dihitung lebih."
  - "Kata sandi berisi karakter yang tidak didukung." (NUL)
- `config/hashing.php` (baru, default framework + `bcrypt.limit = 72`): hasher menolak, bukan memotong, bila suatu jalur lolos validasi.
- Login: validasi tetap `max:200`. **Perubahan perilaku:** input lebih dari 72 byte tidak pernah cocok (422 generik "Email atau kata sandi salah."). Kata sandi tersimpan kini selalu ≤ 72 byte. Tanpa cek ini, bcrypt menerima kata sandi benar + sufiks apa pun. Akun lama dengan kata sandi > 72 byte (hanya mungkin dari data sintetis pra-perbaikan) memakai reset password.
- Sinkronisasi Flutter dikirim ke root (`msg_c11226ec269c`): `utf8.encode(v).length <= 72`, tanpa NUL. Grok melaporkan paritas sudah dibuat (`msg_3e971ae89d12`).

Tes `M06FollowUpTest`:

- Batas di register dan tambahan: 72 byte ASCII 201/202; 73 byte 422. 36×é+1 (71 byte) lolos; 37×é+1 (73 byte, hanya 37 karakter) 422. 17 emoji+2 (70 byte) lolos; 18 emoji+2 (74 byte, 20 karakter) 422. NUL 422.
- Reset dan ganti kata sandi menolak 73 byte, beraksen 73 byte, dan NUL; kata sandi lama tetap berlaku. 72 byte diterima.
- Login dengan 72 byte + sufiks, 150 byte, dan NUL → 422 generik; tepat 72 byte → 200. Kontrol membuktikan `password_verify` PHP sendiri menerima sufiks.
- Operator: env 73 byte → exit 2 dan tidak ada akun; 72 byte → sukses.
- Perilaku PHP dan limit hasher.

**Mutasi:**

| Mutasi | Hasil tes |
| --- | --- |
| Tanpa aturan byte | 500 (NUL dan `InvalidArgumentException`) |
| Tanpa aturan byte dan tanpa limit hasher | 201, yaitu pemotongan diam-diam |
| Login tanpa cek byte | 200 untuk sufiks |

## 3. Penolakan akun tambahan tetap dapat dibaca

`Registration::reject` tidak lagi menetapkan `nonaktif`. Permohonan menjadi `ditolak` dengan alasan, sedangkan akun tetap `jenis=tambahan`, `status=menunggu`, dan `warga_id=null`. `revokeAllAccess()` mencabut bearer lama dan menaikkan `versi_sesi`. Login ulang (`mobile-login` mengizinkan `menunggu`) menerbitkan bearer terbatas di grup `akun:menunggu`: `me`, `additional-account-status` (status `ditolak` + `catatan`), dan `logout`. Layanan warga (`household`, `complaints`, `aspirations`) dan `logout-all` → 403. Approve setelah ditolak → 409 `REQUEST_ALREADY_PROCESSED`. Pengurus tetap melihat histori. Tidak ada endpoint administrasi M07 baru.

Regresi `nonaktif`/`diarsipkan` (keduanya, termasuk pengurus diarsipkan):

- `mobile-login` → 422 generik.
- Bearer yang terbit sebelumnya → 403/401 di `me`, `additional-account-status`, `household`, `logout`, dan `admin/dashboard`; token dicabut.
- `forgot-password` tidak mengirim email.

`M06OperatorTest` diperbarui ke status `menunggu` setelah reject. **Mutasi** (reject kembali `nonaktif`): tes gagal pada status akun.

## 4. Rotasi token deterministik (catatan root `msg_713909677ba1`, `msg_54ccaa20c322`)

Root benar: tes lama memulai rotasi 300 ms setelah registrasi yang ditahan 2 detik. Registrasi yang mengantre pada advisory lock sebelum rotasi bisa semuanya lolos, sehingga hasil 201 + 422 bergantung waktu.

Perubahan kode:

- `RegistrationToken::lockRotation` kini mengambil advisory lock rotasi, lalu **advisory lock registrasi yang sama** dengan `Registration::lockAndVerify`. Registrasi tidak pernah mengambil lock rotasi, jadi tidak ada siklus atau deadlock. Antrean lock PostgreSQL FIFO menjadikan urutannya deterministik:
  - registrasi yang sedang berjalan selesai dulu dengan token lama;
  - rotasi commit;
  - registrasi yang mengantre setelah rotasi melihat token lama sudah dicabut → 422 `REGISTRATION_TOKEN_INVALID`.
- `FOR SHARE`/`FOR UPDATE` tetap dipertahankan sebagai lapisan kedua.
- Kode baru tidak pernah sama dengan kode token aktif yang dicabut atau token terakhir (termasuk yang sudah kedaluwarsa). Kode yang bentrok dibuang dan dibangkitkan ulang (maksimal 50 percobaan, lalu exception dan rollback). Seam tes `generateCodesUsing()`.

Tes tanpa jeda waktu. `race-worker.php` mendapat label `application_name` dan gerbang: penulisan akun pertama di dalam transaksi berhenti pada lock yang dipegang PHPUnit. `Concurrent` mendapat `spawnWorker`, `holdGate`/`releaseGate`, `awaitAdvisoryWait` (`pg_locks`, kunci `hashtext` atau gerbang), dan `awaitRowLockWait` (`pg_stat_activity` `Lock/transactionid|tuple`). Bila worker selesai sebelum handshake, tes langsung gagal.

`test_rotation_queues_behind_inflight_registration_and_late_registrar_sees_revoked_token`:

1. Registrasi awal memverifikasi token lama, lalu teramati menunggu di gerbang.
2. Rotasi dimulai dan teramati menunggu advisory lock `rt05:registrasi`. Token lama masih `aktif`.
3. Registrasi telat dimulai dan teramati mengantre. `pg_locks` menunjukkan urutan rotasi → telat.
4. Gerbang dilepas: awal 201, rotasi kode 6 digit ≠ lama, telat 422 `REGISTRATION_TOKEN_INVALID`.
5. Penanda selesai: token lama 422, kode baru 201. Satu token aktif, satu permohonan dengan token lama, tanpa rekaman yatim.

`test_same_reset_token_used_concurrently_succeeds_once`: reset pertama mengunci baris token, lalu berhenti di gerbang. Tiga lainnya teramati menunggu lock baris. Hasil: tepat 1×200 dan 3×422; kata sandi = milik reset pertama.

`test_rotation_never_reissues_the_previous_code`: generator mengembalikan kode lama dua kali → dibuang. Pada token terakhir yang kedaluwarsa, kodenya juga tidak dipakai ulang.

Bukti:

- `M06ConcurrencyTest` 5× berturut-turut: 6/6 lulus (±7 detik).
- Mutasi:

| Mutasi | Hasil tes |
| --- | --- |
| Rotasi tanpa advisory lock registrasi | Gagal: rotasi menunggu lock baris, bukan antrean registrasi; handshake timeout |
| Tanpa lock registrasi dan `FOR SHARE` | Gagal: rotasi selesai saat registrasi awal masih di tengah transaksi |
| Reset tanpa `lockForUpdate` | Gagal: request lain tidak menunggu |
| Tanpa pengecualian kode lama | Gagal: kode lama terbit ulang |

Semua berkas dipulihkan dan diverifikasi identik dengan `cmp`.

## 5. Halaman reset: logo gapura resmi dan font asli

Aset disalin **byte-identik** (sha256 diverifikasi) dari sumber kanonik website ke `rest-api/public/auth-assets/` dengan nama stabil:

| Berkas | Sumber | sha256 |
| --- | --- | --- |
| `rt05-mark.svg` | `website/public/images/logo/rt05-mark.svg` | `61376072…bcfb98` |
| `public-sans-latin-wght.woff2` | `website/preview/build/assets/public-sans-latin-wght-normal-DdeTHZLK.woff2` (manifest: `@fontsource-variable/public-sans/files/public-sans-latin-wght-normal.woff2`) | `5ed4d31c…de90d68` |
| `bricolage-grotesque-latin-wght.woff2` | `website/preview/build/assets/bricolage-grotesque-latin-wght-normal-DLoelf7F.woff2` (manifest: `@fontsource-variable/bricolage-grotesque/…latin-wght-normal.woff2`) | `a97804dc…de4ea1c` |
| `OFL-public-sans.txt`, `OFL-bricolage-grotesque.txt` | `LICENSE` paket fontsource (SIL OFL 1.1, pemegang hak cipta asli) | — |

Halaman:

- `@font-face` memakai `Public Sans Variable` (100–900) dan `Bricolage Grotesque Variable` (200–800), dengan `unicode-range` latin dan `font-display: swap`, ditambah preload.
- Palet dari `website/resources/css/app.css` (navy `#123e65`, ink, muted, sky, yellow) dengan aksen kuning.
- Brand sama dengan navbar website: logo 44 px, "RT 05 Takeda", dan "Taman Kedaung · RW 07".
- CSP ditambah `img-src 'self'; font-src 'self'`; selebihnya tetap `default-src 'none'` dengan nonce. Tidak ada aset eksternal.
- Aksesibilitas: setiap input `aria-describedby` ke hint/error; `aria-invalid` dan fokus ke field error pertama; error konfirmasi pada field-nya sendiri; hint menjelaskan batas 72 byte.
- Gateway: root sudah meneruskan `/auth-assets/*` ke API (`infra/docker/gateway.Caddyfile:22`). FrankenPHP melayani `public/`, dan `.dockerignore` tidak mengecualikan aset ini.

Tes:

- In-process: logo, brand, `@font-face`, token palet, CSP `img-src`/`font-src`, relasi label/`aria-describedby`/error, salinan byte-identik terhadap sumber website (dicek bila `website/` ada di checkout), dan isi lisensi OFL.
- HTTP nyata (`php -S`): semua URL `/auth-assets/` yang dirujuk halaman (tepat tiga) → 200 dengan byte sama, tanpa `src`/`href` eksternal.

QA browser lokal: Playwright Chromium dari `website/node_modules`, server loopback memakai database `rt05_test`, bukan Neon. Skrip dan screenshot ada di scratchpad sesi, tidak di repo.

- Lebar 320, 360, 390, 768, 1280: overflow horizontal 0.
- `document.fonts`: kedua font berstatus `loaded`; `h1` memakai Bricolage. Logo `naturalWidth` 64.
- Tidak ada pelanggaran CSP.
- Fragment `#token=…&email=…` mengisi form lalu dihapus dari URL.
- axe-core 4.13.0 (wcag2a/2aa/21aa + best-practice, injeksi memakai `bypassCSP` karena CSP halaman memblokir skrip suntikan): **0 pelanggaran** pada 320 dan 1280.
- Alur error: konfirmasi beda → error di field dengan fokus; token fiktif → 422, error di field token dengan fokus.

## Perintah dan hasil

| Perintah (dari `rest-api/` kecuali disebut) | Hasil |
| --- | --- |
| `php artisan test` awal (baseline) | 162/162 lulus |
| `vendor/bin/phpunit tests/Feature/M06ConcurrencyTest.php` ×5 | 6/6 lulus setiap kali |
| Mutasi (bagian 1–4) | semua mutasi menggagalkan tes yang dimaksud; kode dipulihkan (`cmp` identik) |
| `php vendor/bin/pint` lalu `--test` | passed |
| `php artisan test` akhir (2×: sebelum dan sesudah perubahan wording lupa password) | 171/171 lulus (1460 assertion), lalu **171/171 lulus, 1461 assertion** (±31 detik) |
| `node infra/scripts/check-repo.mjs` (root, setelah laporan ini ditulis) | `OK: 628 berkas dilacak …`, exit 0 |

PostgreSQL 17.10 sementara di scratchpad sesi (`127.0.0.1:54329`) dihidupkan ulang dari checkpoint task sebelumnya. Tidak ada Neon; tidak ada credential yang dibuat atau diubah.

## Berkas

Baru:

- `app/Domain/Identity/PasswordPolicy.php`
- `config/hashing.php`
- `public/auth-assets/` (lima berkas)
- `tests/Feature/M06FollowUpTest.php`

Diubah:

- `.env.example` (komentar transport log), `README.md`
- `app/Console/Commands/PengurusAccount.php`
- `app/Domain/Identity/{Registration,RegistrationToken}.php`
- `app/Http/Controllers/{AuthController,BrowserController}.php`
- `app/Notifications/ResetPasswordNotification.php`
- `resources/views/reset-password.blade.php`
- `tests/Feature/{M06BrowserHttpTest,M06ConcurrencyTest,M06OperatorTest,M06PasswordResetTest}.php`
- `tests/Support/{Concurrent.php,race-worker.php}`

## Untuk root

1. OpenAPI `NewPassword` dan `API.md:66` sudah sesuai. Opsional: contoh pesan 202 lupa password = `AuthController::FORGOT_MESSAGE`, dan catatan bahwa login dengan input > 72 byte selalu 422 generik.
2. Staging (`msg_79f0de0191aa`): `MAIL_MAILER` diset tetapi tanpa host SMTP. Bila nilainya `log`/`failover`, email reset sengaja tidak dikirim dan peringatan muncul di log worker. Gate SMTP sampai inbox tetap **blocked**; mock/array bukan bukti delivery.
3. Deploy staging membawa `public/auth-assets/` di image API; tidak ada migration baru dibanding laporan pertama (`versi_sesi` sudah ada).

## Keterbatasan

- Belum ada run CI GitHub (Linux) maupun deploy staging. Semua hasil di atas dari Windows lokal.
- QA browser memakai Chromium headless lokal, bukan perangkat nyata atau staging. Screenshot tidak dimasukkan ke repo.
- Antrean FIFO advisory lock adalah perilaku lock manager PostgreSQL yang dibuktikan di sini pada 17.10; CI memakai Postgres 17 yang sama.
- M07, production, data warga nyata, dan insiden credential tetap di luar lingkup.
