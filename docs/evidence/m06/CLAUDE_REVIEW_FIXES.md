# M06 — koreksi review backend (Claude)

Tanggal: 12 Oktober 2026. Task `task_1c5604b7434d`, dispatch `ctx_ee3d9ac0a662`. Basis: `8d0b3df` dan [GROK_BACKEND_REVIEW.md](GROK_BACKEND_REVIEW.md).

Lingkup: `rest-api/` dan laporan ini. Tidak ada commit, push, atau pindah branch. `mobile/`, gateway, OpenAPI, dokumen bersama, dan staging tidak disentuh. Temuan gateway `Referrer-Policy` dikerjakan root.

**Hasil: 179/179 PHPUnit lulus (1577 assertion) pada PostgreSQL 17.10 lokal sementara (`rt05_test`). Pint passed. `check-repo` exit 0.** Delapan tes baru; setiap koreksi diverifikasi dengan mutasi. Setelah suite, tabel identitas/audit/token kosong dan tabel cache sementara tes sudah dihapus.

## 1. Bootstrap operator tanpa password eksplisit (Sedang)

Sebelumnya, `rt05:pengurus buat --no-interaction` tanpa `RT05_ADMIN_PASSWORD` selalu membuat pengurus dengan password acak dan mencetak "Email reset diantrekan". Bila mailer `log` (default compose), `shouldSend` kemudian menolak email, sehingga akun tidak dapat masuk.

Perubahan:

- **Cek sebelum transaksi** di `PengurusAccount::create`. Bila sumber password `email_reset` dan email pasti tidak terkirim, perintah keluar 1 dengan "Akun tidak dibuat: email reset tidak dapat dikirim (alasan)" serta petunjuk memakai `RT05_ADMIN_PASSWORD`, prompt interaktif, atau SMTP. Tidak ada akun, audit, token reset, atau email. Alasan yang ditolak:
  - `ResetPasswordNotification::blockedReason()`, sumber yang sama dengan `shouldSend`: transport log, termasuk di dalam failover/roundrobin; mailer tidak dikenal; `APP_URL` bukan HTTPS di luar local/testing.
  - Mailer array di luar environment `testing`.
- **Env dan prompt tersembunyi tidak berubah.** Keduanya tetap berhasil walau mailer `log`, karena tidak memerlukan email.
- **Kirim sesudah commit.** Akun dan token reset dibuat di dalam transaksi. Email dikirim/diantrekan sesudahnya lewat `App\Domain\Identity\PasswordReset::notify`. Bila SMTP gagal (atau Redis mati saat enqueue), akun yang sudah commit tetap ada. Perintah keluar 1 dengan "Akun tetap tersimpan; setelah SMTP/antrean pulih, minta reset lewat Lupa kata sandi". Log hanya memuat kelas exception, tanpa token atau email. Password dan token tidak pernah dicetak.
- **Lupa password memakai jalur yang sama** (`AuthController::forgotPassword`): token di transaksi, email sesudah commit. SMTP gagal pada antrean sync tidak lagi menjadi 500; respons tetap 202 generik dan baris token tetap ada. Throttle broker (60 detik) dipertahankan lewat `recentlyCreatedToken`.

Tes (`M06ReviewFixesTest`):

- Blok: testing/log, production/failover (smtp+log), staging/roundrobin berisi log, local/mailer tidak dikenal, staging/smtp + `APP_URL` http, staging/array. Semuanya exit 1 dengan alasan yang tepat; tidak ada akun, token reset, audit `akun.pengurus_dibuat_operator`, atau email.
- Jalur eksplisit dengan mailer `log`: env (exit 0, login berhasil, password tidak tercetak) dan prompt tersembunyi.
- Jalur array di testing: exit 0, tepat satu email di transport array, token tidak tercetak.
- SMTP valid tetapi tidak dapat dihubungi (`127.0.0.1:9`):
  - Operator exit 1; akun pengurus, audit, dan token reset tetap tersimpan.
  - Lupa password tetap 202 dan token tersimpan.
  - Log berisi peringatan tanpa token (hex 64) dan tanpa email.

Mutasi:

| Mutasi | Hasil tes |
| --- | --- |
| Cek awal dimatikan | Gagal: akun dibuat untuk testing/log |
| `notify` tidak menangkap exception | Gagal: `Connection could not be established` lolos |
| `PengurusAccount` versi `8d0b3df` | Gagal pada kedua tes. Pada tes SMTP, exception keluar dari perintah setelah commit |

## 2. Konfirmasi kata sandi saat ini (Rendah)

`PATCH /auth/password` (`current_password`) dan `POST /auth/logout-all` (`password`) sebelumnya memakai `current_password:sanctum`, yang langsung `Hash::check`. Karena bcrypt PHP memotong input di 72 byte, kata sandi 72 byte yang benar ditambah sufiks apa pun lolos.

Sekarang keduanya memakai `PasswordPolicy::current($request->user())`, yang memanggil `PasswordPolicy::matches`. Ini aturan yang sama dengan login dan web-login: string, ≤ 72 byte, tanpa NUL, lalu `Hash::check`. Pesan memakai `validation.current_password`. Aturan kekuatan (min 8/huruf/angka) **tidak** diterapkan pada kata sandi saat ini, jadi akun lama dengan kata sandi lemah tetap dapat mengonfirmasi.

Tes:

- Akun dengan kata sandi tepat 72 byte. Kontrol menunjukkan `password_verify` PHP menerima sufiks.
- Ganti kata sandi dan logout-all dengan 72 byte + `Z` atau 72 byte + NUL → 422 pada field yang benar. Kedua bearer tetap ada dan kata sandi tidak berubah.
- Nilai persis → 200, dan bearer lain dicabut.
- Kata sandi lama lemah `lemah` (hash langsung, sebelum `PasswordPolicy`) tetap diterima pada ganti kata sandi dan logout-all.

Mutasi: kembali ke `current_password:sanctum` membuat tes gagal (200 alih-alih 422).

## 3. Limiter (Rendah)

### Burst kegagalan token

Sebelumnya, `TokenAttempts::ensureAllowed` hanya membaca penghitung, dan hit dicatat sesudah verifikasi gagal (cek-lalu-hit). Burst paralel dari satu IP sama-sama lolos cek.

Sekarang `TokenAttempts::reserve` menaikkan penghitung secara atomik (`RateLimiter::increment`) **sebelum** verifikasi, dan menolak 429 bila hasilnya melewati batas. `release` (`decrement`) mengembalikan kuota bila percobaan tidak gagal karena token, yaitu sukses atau konflik lain. Total kegagalan token per jendela karenanya ≤ batas. Kontrak 10/jam per IP dan `RT05_TOKEN_FAILURES_PER_HOUR` tidak berubah.

Tes proses independen (`M06ConcurrencyTest::test_parallel_wrong_token_burst_from_one_ip_cannot_exceed_the_failure_limit`):

- Worker memakai cache store `database` bersama, dengan tabel tes sementara `rt05_race_cache` yang dihapus di `finally`. Increment-nya terkunci baris, setara `INCRBY` Redis.
- Batas 3, satu kegagalan berurutan, lalu 10 request paralel token salah dari IP yang sama lewat barrier.
- Hasil: tepat 2 × 422 `REGISTRATION_TOKEN_INVALID` + 8 × 429. Tidak ada akun.

**Mutasi** (`TokenAttempts` dan `registerWithToken` versi `8d0b3df`): 10 × 422 dalam 2/2 run. Ada 11 kegagalan untuk batas 3.

### Batas nyata dan keterbatasan

- **Atomisitas mengikuti cache store.** Staging/production memakai Redis (`CACHE_STORE=redis`; `INCRBY` atomik). File store lokal tidak atomik antar proses (`FileStore::increment` membaca lalu menulis), dan array store per proses.
- **Satu race kecil ada di framework.** `RateLimiter::increment` Laravel 13 melakukan `add(key, 0)`, `increment`, lalu `put(key, amount)` bila `!added && hits == amount`. Pada hit pertama yang benar-benar bersamaan, `put` itu dapat menimpa satu hitungan. Paling banyak satu percobaan ekstra per jendela, dan hanya saat kunci belum ada. Tes burst mengunci kunci lebih dulu agar hanya menguji increment. Tidak diubah (kode vendor).
- **Middleware throttle Laravel juga cek-lalu-hit.** `ThrottleRequests` (mis. `throttle:registrasi` 5/10 menit) tidak atomik terhadap burst. Pada mutasi di atas, kesepuluh request lolos throttle registrasi. Batas atas kegagalan token kini dijamin oleh `TokenAttempts`, bukan throttle umum. Throttle middleware lain (login, lupa password, laporan) tetap perilaku framework dan dicatat sebagai keterbatasan; tidak ada layanan baru yang ditambahkan.
- **Hit pada percobaan yang sudah 429 tidak dikembalikan.** Ini tidak memperpanjang jendela, karena timer dibuat sekali per jendela. Bila kunci kedaluwarsa tepat di antara reserve dan release, penghitung dapat turun ke −1, sehingga paling banyak satu percobaan ekstra pada jendela berikutnya.

### Lupa password dan reset

Sebelumnya `POST /auth/reset-password` memakai bucket `lupa-password` (3/15 menit per IP+email) yang sama dengan permintaan lupa password. Sekarang ada limiter sendiri, `reset-password`: 10/15 menit per IP + email ternormalisasi. Lupa password tetap 3/15 menit. Token reset 64 hex, sehingga batas reset melindungi biaya bcrypt, bukan entropi. Root menyetujui angka ini (`msg_8d651c688f73`) dan memperbarui API.md/CONTRACT/OpenAPI.

Tes `test_reset_submissions_have_their_own_limiter_bucket`:

1. 3 × lupa → 202; yang keempat → 429.
2. Reset: 2 token salah → 422, lalu token berlaku → 200.
3. Bucket reset tetap terbatas: setelah total 10 submit, yang ke-11 → 429.

Mutasi (route kembali ke `throttle:lupa-password`): gagal, karena reset pertama sudah 429.

## Perintah dan hasil

| Perintah (dari `rest-api/` kecuali disebut) | Hasil |
| --- | --- |
| `php artisan test` setelah perubahan kode, sebelum tes baru | 171/171 lulus |
| `vendor/bin/phpunit tests/Feature/M06ReviewFixesTest.php` | 7/7 lulus |
| `vendor/bin/phpunit --filter=test_parallel_wrong_token_burst …M06ConcurrencyTest.php` | lulus; mutasi 2× gagal (10 × 422) |
| Mutasi bagian 1–3 | semua gagal sesuai harapan; berkas dipulihkan dan diverifikasi `cmp` |
| `php vendor/bin/pint` lalu `--test` | passed |
| `php artisan test` akhir | **179/179 lulus, 1577 assertion** (±35 detik) |
| `node infra/scripts/check-repo.mjs` (root) | `OK: 635 berkas dilacak …` (setelah laporan ini), exit 0 |

Postgres 17.10 sementara di scratchpad sesi (`127.0.0.1:54329`), database `rt05_test`; tidak ada Neon. Tabel `rt05_race_cache` hanya dibuat oleh tes burst di `rt05_test` lewat koneksi migrasi lalu dihapus.

## Berkas

Baru:

- `app/Domain/Identity/PasswordReset.php`
- `tests/Feature/M06ReviewFixesTest.php`

Diubah:

- `README.md`
- `app/Console/Commands/PengurusAccount.php`
- `app/Domain/Identity/{PasswordPolicy,TokenAttempts}.php`
- `app/Http/Controllers/AuthController.php`
- `app/Notifications/ResetPasswordNotification.php` (`blockedReason`, `mailerUses`)
- `app/Providers/AppServiceProvider.php`
- `routes/api.php`
- `tests/Feature/M06ConcurrencyTest.php`
- `tests/Support/Concurrent.php` (env per worker)

## Untuk root

1. API.md/OpenAPI:
   - limiter `reset-password` 10/15 menit per IP + email (terpisah dari lupa 3/15);
   - token failure 10/jam per IP direservasi sebelum verifikasi;
   - 422 `current_password`/`password` untuk input > 72 byte atau NUL pada ganti kata sandi dan logout-all.
2. Runbook/DEPLOYMENT: `rt05:pengurus buat --no-interaction` tanpa `RT05_ADMIN_PASSWORD` kini gagal (exit 1) selama `MAIL_MAILER=log`. Untuk bootstrap staging sebelum SMTP ada, pakai `RT05_ADMIN_PASSWORD=… php artisan rt05:pengurus buat <email> --no-interaction`. Nilainya diberikan lewat env, tidak dicetak.
3. Gateway `Referrer-Policy` sudah dikerjakan root; verifikasi browser lewat gateway menunggu SHA final.

## Keterbatasan

- Tidak ada CI GitHub, staging, atau SMTP sampai inbox pada tugas ini. Penerima uji diberikan secara privat ke root, tetapi pengirim belum dikonfigurasi, jadi delivery belum terbukti.
- Atomisitas limiter di staging bergantung pada Redis. Tes memakai cache store database Postgres sebagai pengganti increment atomik; tidak ada Redis lokal.
- M07, production, data warga nyata, dan insiden credential tetap di luar lingkup.
