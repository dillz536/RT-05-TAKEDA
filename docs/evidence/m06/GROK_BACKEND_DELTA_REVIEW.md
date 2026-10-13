# Tinjauan delta backend M06

Tanggal 12 Oktober 2026. Lingkup terbatas pada koreksi empat temuan di [GROK_BACKEND_REVIEW.md](GROK_BACKEND_REVIEW.md), dibanding [CLAUDE_REVIEW_FIXES.md](CLAUDE_REVIEW_FIXES.md) dan [CLAUDE_TOKEN_RESERVATION_FIX.md](CLAUDE_TOKEN_RESERVATION_FIX.md).

`git rev-parse HEAD` = `15885cbf2d08b3e0f2b66b32899e9e6857ff63d1` (`fix(m06): close review gaps in account access and token limits`, 12 Oktober 2026 16:16 +0700). Commit itu anak `8d0b3df8f73860b66277fed1494f196d47525d4a`. Berkas pada tabel SHA-256 bersih terhadap HEAD ini. Tinjauan membaca sumber lingkup dan tes regresi yang mengunci perilaku itu, lalu menghitung SHA-256. PHPUnit, Pint, Redis, Postgres, gateway, SMTP, staging, dan emulator tidak dijalankan. Angka 184 tes dan 1679 assertion berasal dari laporan Claude dan dari pesan koordinasi tentang CI, bukan dari perintah tinjauan ini.

## SHA-256

| Berkas | SHA-256 |
| --- | --- |
| [PengurusAccount.php](../../../rest-api/app/Console/Commands/PengurusAccount.php) | `981068885d2ab278f0b98f52233b28fdb07f2db482af5db9b2b625b4d2914af4` |
| [PasswordReset.php](../../../rest-api/app/Domain/Identity/PasswordReset.php) | `a91fd049e1fdaaecd5fb7f265e15c5e4912af57d007678d2a82038b159eac05d` |
| [PasswordPolicy.php](../../../rest-api/app/Domain/Identity/PasswordPolicy.php) | `4d80701bd67ff65b7f18df3251d620b767528721eedb9cf3187ca4dbb684df04` |
| [AuthController.php](../../../rest-api/app/Http/Controllers/AuthController.php) | `e7926c8b9a8f3b49c6d2c7e5fd3f92a0948798d421ab85599e9a18ca005741e0` |
| [ResetPasswordNotification.php](../../../rest-api/app/Notifications/ResetPasswordNotification.php) | `4519ea6ed451aa1dd1d1668df689e031a4801fe58d528990d3576620197f1996` |
| [TokenAttempts.php](../../../rest-api/app/Domain/Identity/TokenAttempts.php) | `df23acf683570dec552bfc9377620a9bebb5bff666111efcd92849918b4a0c3c` |
| [api.php](../../../rest-api/routes/api.php) | `21b1392a6b11783be122e1160d966f614417b3863682688065afe27ff774ae5a` |
| [AppServiceProvider.php](../../../rest-api/app/Providers/AppServiceProvider.php) | `115eb4aaeb4f7fd399e18c9643da5f782ad1131f003e79941d6d00c8761a2060` |
| [gateway.Caddyfile](../../../infra/docker/gateway.Caddyfile) | `7852930bd52b82b148d38025ba943dc8adb96df99a3e6522639de3434715b79c` |
| [BrowserController.php](../../../rest-api/app/Http/Controllers/BrowserController.php) | `834449a336d3df4abdaa52be05325dca4275fa1c08b70c90dafa0faca024acb8` |
| [cache.php](../../../rest-api/config/cache.php) | `58263f1433d4f1daaadfc90bca0d6a434684593b6fb0d85eb98bde2be7a0e75d` |
| [reset-password.blade.php](../../../rest-api/resources/views/reset-password.blade.php) | `530b24a15d0251741bf926cf75c9c35e3965da06c1f6af431fdda61aa0545052` |
| [M06ReviewFixesTest.php](../../../rest-api/tests/Feature/M06ReviewFixesTest.php) | `663f77e2620489a31908d83142d9c37fe200c85f57dc0d6148762b4cfdcaeafb` |
| [M06ConcurrencyTest.php](../../../rest-api/tests/Feature/M06ConcurrencyTest.php) | `f81f075cf0567c066f474dbb9c66ef7d135162d16dc87176cef0f70b6aa9af0d` |
| [race-worker.php](../../../rest-api/tests/Support/race-worker.php) | `334c2ce5e7556a5489b2d21a2868d57a057f04cedd4f82aabcb698edf05f3822` |
| [Concurrent.php](../../../rest-api/tests/Support/Concurrent.php) | `38bdd7e78776db24cf9be625d7e1dd5e5355d1ff6e7ec79bc0a1a1fdf58d1c58` |
| [README.md](../../../rest-api/README.md) | `5b148e36e6426f2ed9c7a74661c250cacd1035b85f912261130e6578d2daf92b` |

## Vonis

Empat temuan [GROK_BACKEND_REVIEW.md](GROK_BACKEND_REVIEW.md) tertutup pada sumber yang dibaca.

### Bootstrap gagal cepat, dan kegagalan email tidak menggulung akun

`PengurusAccount::passwordSource` memakai env, prompt tersembunyi, atau `Str::password(32)` pada jalur `email_reset`. Bila sumbernya `email_reset` dan `resetUndeliverable()` terisi, perintah keluar gagal sebelum `DB::transaction`, dengan pesan akun tidak dibuat. `resetUndeliverable` memakai `ResetPasswordNotification::blockedReason()` yang sama dengan `shouldSend`: transport `log` termasuk di dalam failover/roundrobin, mailer tidak dikenal atau sarang lebih dari 5 (gagal tertutup), dan `APP_URL` non-HTTPS di luar local/testing. Mailer `array` ditolak di luar environment `testing`.

Akun, audit, dan `PasswordReset::createToken` berada di dalam transaksi dengan advisory lock `rt05:peran-pengurus`. `PasswordReset::notify` dipanggil sesudah commit. Notifikasi yang mengembalikan false membuat perintah keluar gagal, akun tetap ada, dan pesan mengarahkan ke Lupa kata sandi. `notify` menangkap `Throwable`, mencatat kelas exception, dan mengembalikan false. Password tidak dicetak. `forgotPassword` membuat token di dalam transaksi, memanggil `notify` sesudahnya, dan tetap membalas 202.

`M06ReviewFixesTest` mengunci kasus blok (log, failover berisi log, roundrobin berisi log, mailer tidak dikenal, HTTPS, array di staging), env/prompt dengan mailer log, array di testing, serta SMTP `127.0.0.1:9` yang menyisakan akun dan tetap 202 pada lupa password. Tes itu dibaca, tidak dijalankan.

### Kata sandi saat ini harus persis

`PasswordPolicy::matches` menerima string, panjang paling banyak 72 byte, tanpa NUL, lalu `Hash::check`. Aturan kekuatan tidak diterapkan pada konfirmasi. `PasswordPolicy::current` dipakai `logoutAll` (field `password`) dan `changePassword` (field `current_password`). `mobileLogin` dan `webLogin` memakai `matches`. Tidak ada pemakaian `current_password:sanctum` yang tersisa pada kode PHP.

`test_current_password_confirmation_rejects_72_byte_prefix_with_suffix_and_nul` menolak 72 byte ditambah `Z` dan 72 byte ditambah NUL pada kedua endpoint, lalu menerima nilai persis. `test_old_weak_password_can_still_be_confirmed` menerima kata sandi lama `lemah`.

### Limiter reset terpisah dari lupa password

`POST auth/forgot-password` memakai `throttle:lupa-password` (3 per 15 menit per IP dan email). `POST auth/reset-password` memakai `throttle:reset-password` (10 per 15 menit per IP dan email). Keduanya memakai penutup email `strtolower(trim(...))`. `test_reset_submissions_have_their_own_limiter_bucket` menghabiskan bucket lupa password, tetap mengizinkan submit reset, lalu menolak percobaan reset ke-11 dengan 429.

### Reservasi penghitung baru dan penghitung nol di bawah lock

`TokenAttempts` tidak memanggil `RateLimiter`. Satu nilai `{hits, reset_at}` dibaca, diubah, dan ditulis di dalam `Cache::lock` pada `Cache::store(config('cache.limiter'))`. `reserve` berjalan sebelum verifikasi. Bila `hits` sudah mencapai batas, respons 429 memakai `Retry-After` sisa jendela (minimal 1). `release` mengurangi sampai minimal 0 dengan `reset_at` yang sama. Lock 10 detik, tunggu 5 detik; batas waktu menjadi 429 `Retry-After: 5`. Release yang tidak mendapat lock membiarkan slot terpakai. Nilai hilang, kedaluwarsa, atau bentuknya tidak dikenal memulai jendela `[0, now+3600]` tanpa memperpanjang jendela berikutnya. `registerWithToken` mengembalikan slot kecuali `DomainConflict` berkode `REGISTRATION_TOKEN_INVALID`.

`config('cache.limiter')` tidak diisi di [cache.php](../../../rest-api/config/cache.php), jadi store yang dipakai adalah store default. Store Redis mengunci lewat `lock_connection` default `queue`. Tes lintas proses memakai store database bersama (`rt05_race_cache` dan `rt05_race_cache_locks`). Assertion yang dibaca: penghitung baru 3 diterima dari 10; reserve+release kembali ke 0 lalu tepat 3; reservasi yang ditahan pada `WritingKey` sebelum `put` membuat total diterima dari 20 tetap 3; burst HTTP penghitung baru dan sesudah kembali ke nol masing-masing 3 kali 422 dan 7 kali 429. Tes jendela in-process menolak reservasi ketiga dengan `Retry-After` sekitar 40 menit.

### Header Referrer sebagai default

[gateway.Caddyfile](../../../infra/docker/gateway.Caddyfile) baris 18 memakai `?Referrer-Policy strict-origin-when-cross-origin`. Prefiks `?` pada direktif `header` Caddy menyetel field hanya bila respons belum memiliki field itu, sehingga `no-referrer` dari upstream tetap. Satu-satunya `Referrer-Policy` di Caddyfile repositori ada di berkas ini. `BrowserController::resetPasswordForm` mengirim `Referrer-Policy: no-referrer`. Meta `referrer` pada halaman reset tetap `no-referrer`, dan token tetap di fragment. Gateway tidak dijalankan.

## Batas yang tersisa

- Batas keras reservasi pada laporan Claude dibuktikan di store database Postgres bersama. Jalur Redis memakai lock `SET NX` pada koneksi queue dan nilai pada koneksi cache. Redis tidak dihidupkan di tinjauan ini.
- Store file dan array tidak menjadi batas keras. Komentar `TokenAttempts` menyatakan hal yang sama.
- Pengusiran kunci penghitung pada instance cache Redis dapat memulai jendela baru. Lock berada pada instance queue.
- Lock berlaku 10 detik. Proses yang mati sambil memegang lock membuat reservasi lain menunggu sampai 5 detik lalu ditolak. Operasi di bawah lock yang melewati 10 detik membuat lock kedaluwarsa.
- Nilai cache yang bukan array `{hits, reset_at}` pada kunci yang sama, misalnya sisa integer limiter lama, dihitung sebagai jendela baru satu kali.
- Throttle `login`, `registrasi`, `lupa-password`, dan `reset-password` tetap memakai `RateLimiter` Laravel.
- Baris Token RT di [README.md](../../../rest-api/README.md) pada HEAD ini menyebut read-modify-write di bawah `Cache::lock`. Kalimat "increment cache" di laporan reservasi Claude berbeda dari baris commit ini.
- Inbox SMTP, staging, emulator, production, M07, dan rotasi credential berada di luar tinjauan ini. Pemulihan Redis pada CI tidak mengubah batas bahwa tes reservasi paralel di repositori memakai store database.

## Pesan koordinasi

`msg_02f40d832c44` menyatakan root sudah meng-commit sumber koreksi dan HEAD baru inilah yang ditinjau. Kode backend serta gateway sama dengan pohon saat dispatch. Baris reservasi README ikut commit.

`msg_945e811735d7` melaporkan bukti root: API run `38228867337` sukses (184 tes, 1679 assertion; Pint, OpenAPI, runtime DDL-denied, direct smoke, dan pemulihan Redis lulus), infra run `38228867353` sukses, hygiene run `38228867368` sukses, verifikasi Flutter 114 lulus dengan analyzer dan format bersih. APK run `38228867355` masih dibangun saat pesan itu dikirim. Log GitHub tidak dibuka di tinjauan ini.

## Perintah

- `git rev-parse HEAD` → `15885cbf2d08b3e0f2b66b32899e9e6857ff63d1`; ancestor `8d0b3df8f73860b66277fed1494f196d47525d4a` benar.
- `Get-FileHash -Algorithm SHA256` pada tujuh belas berkas di tabel.
- `node infra/scripts/check-repo.mjs` → `OK: 640 berkas dilacak; tanpa file terlarang, pola secret, .git bersarang, atau tautan relatif rusak; vercel.json setara.`
