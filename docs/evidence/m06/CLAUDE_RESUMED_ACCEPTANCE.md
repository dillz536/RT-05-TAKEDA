# M06 — penerimaan backend/database staging (Claude)

Tanggal: 12 Oktober 2026. Task `task_a48b08a965dc`, dispatch `ctx_b78db9af3f87`.

**Sumber:** `codex/m06-account-access` HEAD `26a0b7e22cfe96941266cb9c194072512eca707e`. `rest-api/` dan `infra/` identik dengan `15885cb` (`git diff 15885cb HEAD -- rest-api infra` kosong).

**Batas tindakan Claude:**

- Tidak ada commit, push, branch switch, atau redeploy.
- Rollout dijalankan Grok (job 2130943). Runtime smoke dijalankan root lewat SSH.
- Seluruh data uji sintetis (`example.invalid`). Tidak ada production, Neon langsung, atau data warga.

## Ringkasan

| Area | Hasil | Bukti |
| --- | --- | --- |
| PHPUnit lokal (PostgreSQL 17.10 sementara) | **184/184, 1679 assertion**; Pint pass | §1 |
| Rollout staging `26a0b7e` | Semua container RT05 `Up`; semua yang punya healthcheck `healthy` (gateway tanpa healthcheck); route M06 aktif | §2 |
| Bootstrap pengurus sintetis | exit 0 lewat SSH stdin, tanpa password di argv | §3 |
| `verify.mjs` (helper Codex) | **52/52 PASS** | §4 |
| Probe sesi/rotasi/cookie (Claude) | **29/30**; satu gagal karena pacing probe sendiri, rerun terisolasi PASS | §5 |
| Mekanika reset (token dibuat di server) | **17/17 PASS** | §6 |
| Kebocoran token reset di log | Tidak ditemukan | §6 |
| **Email reset sampai inbox** | 12 Oktober: BLOCKED (§7). **13 Oktober: ronde A (tautan, Chrome) dan B (kode, Android) diterima di inbox Gmail nyata; `verify A` 4/4 dan `verify B` 4/4 PASS** | §9 |

Gate inbox sisi Claude selesai (§9). Keputusan akhir gate M06 dan Git tetap milik root. M07 tidak dibuka.

## 1. Validasi lokal

| Perintah (dari `rest-api/`) | Hasil |
| --- | --- |
| `php artisan test` (`rt05_test`, 127.0.0.1:54329) | 184/184 lulus, 1679 assertion, 92 detik |
| `php vendor/bin/pint --test` | pass |
| `node infra/scripts/check-repo.mjs` (root) | OK (lihat akhir laporan) |

## 2. Staging sebelum dan sesudah rollout

**Sebelum (±13.0x UTC):**

- `/health/ready` 200 `ready`.
- `/sanctum/csrf-cookie` dan `/reset-password` mengembalikan 404 HTML website, artinya belum M06.

**Sesudah rollout:**

- Watcher mencatat `/sanctum/csrf-cookie` menjadi 204 pada 13:21:15 UTC.
- `docker ps` lewat SSH: sembilan container `rt05-staging-*` menjalankan image `rt05-api|rt05-web:26a0b7e22cfe…` dan semuanya `Up`. Semua yang punya healthcheck berstatus `healthy`; `gateway` (caddy:2-alpine) tidak punya healthcheck.

| Probe read-only | Hasil |
| --- | --- |
| `/health/ready`, `/health/live` | 200 `ready` (database ok) dan 200 `ok` |
| `GET /sanctum/csrf-cookie` | 204. `rt05_api_xsrf`: secure, samesite=lax, tanpa httponly. `rt05_api_session`: secure, httponly, samesite=lax. Keduanya Max-Age 7200 (idle 120 menit). |
| `GET /reset-password` lewat gateway | 200. `Cache-Control: no-store, private`, `Referrer-Policy: no-referrer` (tidak ditimpa gateway), CSP `default-src 'none'` dengan nonce dan `frame-ancestors 'none'`, `X-Frame-Options: DENY`, `nosniff` |
| `/auth-assets/` (2 woff2, `rt05-mark.svg`) | 200, content-type benar |
| `GET /api/v1/` | 200 `RT05 TAKEDA API` v1 |

**Restart tambahan.** Root menjalankan runtime smoke (PASS; detail di log privat root `runtime-smoke-26a0b7e.log`), yang me-restart worker-default (log `Worker STOPPED` 13:44:16). Sesudah itu, `redis` (antrean) dan `worker-default` start lagi pada 13:47:41 dan 13:47:51 UTC. Grok mengonfirmasi bahwa ini smoke kedua yang ia jalankan sendiri ("smoke done", PASS, attempts=1 inbox=0).

- Semua tetap `healthy`, `RestartCount` 0, volume tidak disentuh.
- Probe §5 berjalan 13:45:53–±13:47:30 dan seluruh langkah bearer/cookie lulus.
- Disarankan koordinasi satu pelaksana smoke per rollout.

## 3. Bootstrap pengurus sintetis

- **13:45:17 UTC:** `rt05:pengurus buat` dijalankan lewat SSH key `morriz_vps_ed25519`. Kunci publik dipasang root lewat konsol root yang diotorisasi; percobaan pertama Claude, sebelum kunci dipasang, ditolak `publickey,password`.
- **Jalur nilai:** email dan password dikirim melalui stdin SSH, lalu menjadi env yang diteruskan `docker exec -e NAMA` (nama saja). Tidak ada password di argv, history, atau output; output alat diredaksi.
- **Hasil:** `Akun pengurus dibuat`, `BOOTSTRAP_EXIT=0`. Cek sesudahnya: admin `pengurus/aktif/utama`, total pengurus aktif 2 (satu existing sebelumnya).
- **Cek sebelumnya:** tidak ada akun fixture yang sudah ada, sehingga bootstrap konsol lama yang dibatalkan tidak meninggalkan akun.

## 4. `verify.mjs` (helper privat Codex), 13:45:25–13:45:43 UTC

Hasil **52/52 PASS**, `sourceCommit 26a0b7e…`. Hasil lengkap ada di `~/.rt05/m06-stage-check/results.json` (privat).

**Registrasi:**

- Token registrasi berupa string enam digit.
- Akun utama 201, langsung `aktif/warga/utama`; keluarga `belum_verifikasi`.
- Alamat rumah yang sama ditolak 409 tanpa auto-merge.

**Akun tambahan:**

- Daftar 202 dengan status `menunggu`; household 403; status permohonan sendiri `diajukan`.
- Pengurus approve 200. Bearer pending melihat status `aktif` pada refresh. Login ulang masuk ke keluarga yang benar. PATCH household 403 (read-only).
- Pengurus reject 200: bearer lama 401. Login ulang terbatas menampilkan alasan; status tetap `menunggu`; household 403.

**Forgot dan logout:**

- Forgot 202 generik dan identik untuk email dikenal maupun tidak dikenal.
- Logout 204, lalu `me` 401.

**Browser (Playwright):**

- `csrf-cookie` 204. Cookie sesi secure, HttpOnly, Lax. Cookie XSRF terpisah: dapat dibaca JS, secure, Lax.
- Web-login tanpa CSRF 419; dengan CSRF 200; `me` 200.
- Logout tanpa CSRF 419; dengan CSRF 204; sesi dicabut (401).
- Halaman reset: 200 dengan `no-store`/`no-referrer`, logo dan font asli termuat, tanpa page error.

## 5. Probe lanjutan Claude, 13:45:53 UTC (30 cek, 29 PASS)

Skrip privat: `probe-extended.mjs` di scratchpad sesi. Output hanya nama cek dan status HTTP.

| Cek | Hasil |
| --- | --- |
| Rotasi manual pengurus | 200, kode enam digit baru ≠ lama |
| Warga memanggil rotate | 403 |
| Kode lama sesudah rotasi | 422 `REGISTRATION_TOKEN_INVALID` |
| Dua bearer perangkat independen | PASS |
| Ganti sandi dengan sandi saat ini salah | 422 |
| Ganti sandi | 200. Bearer perangkat ini tetap 200; bearer lain 401. Sandi lama 422; sandi baru 200. |
| Logout-all dengan sandi salah | 422 |
| Logout-all | 204; bearer pemanggil dan bearer lain 401 |
| Sandi fixture dipulihkan | 200 |
| Cookie pengurus, mutasi tanpa CSRF | 419 |
| Bearer logout-all mencabut sesi cookie browser (`versi_sesi`) | `me` cookie 401 |
| Akun ditolak | `me` 200; household 403; ganti sandi 403; logout 204 |
| **Web-login warga ditolak generik** | **FAIL di probe: 429**, lihat di bawah |

**Analisis FAIL.** `POST /api/v1/auth/web-login` memakai `throttle:login` (5/menit per IP+email; `routes/browser.php`). Probe baru saja melakukan 5 login mobile untuk email primary dalam ±52 detik, sehingga permintaan ke-6 terkena limiter. Itu perilaku benar, dan kesalahannya ada pada pacing probe.

Rerun terisolasi (`probe-weblogin.mjs`) setelah jendela throttle berlalu: warga utama, warga tambahan yang disetujui, dan email tak dikenal semuanya mendapat **422 `VALIDATION_FAILED` "Periksa isian."** yang identik. Tidak ada enumerasi akun.

**Token registrasi untuk Grok.** Kode aktif sesudah rotasi ditulis ke fixture privat `C:/Users/USER/.rt05/m06-stage-check/fixtures.json` (`registrationToken`, `…FetchedAt`, `…ValidUntil 2026-10-20T13:46:01Z`), lalu admin logout. Kode tidak dikirim lewat mailbox atau laporan.

**Rate limit.** Registrasi 5/10 menit per IP: PC ini memakai 5 kali pada 13:45–13:46 UTC.

## 6. Mekanika reset dengan token yang dibuat server, 13:49:09 UTC (17/17 PASS)

**Ini bukan bukti SMTP/inbox.** Token dibuat dengan `PasswordReset::createToken` di container API, lewat skrip PHP yang dikirim di stdin SSH. Token kembali ke proses lokal saja dan tidak dicetak.

Satu-satunya manipulasi data: `created_at` baris `password_reset_tokens` milik akun primary sintetis.

- Dimundurkan 2 menit sebelum setiap pembuatan token, untuk melewati throttle broker 60 detik.
- Dimundurkan 61 menit untuk menguji expiry.

| Cek | Hasil |
| --- | --- |
| Token salah | 422 |
| Reset API | 200; bearer lama dicabut (401) |
| Token dipakai ulang | 422 |
| Sandi lama / sandi baru | 422 / 200 |
| Token berumur 61 menit (expire 60) | 422 |
| Form browser lewat tautan `/reset-password#token=…&email=…` | Halaman 200. Fragment dihapus dari address bar. Form terisi dari fragment. POST 200. Status "berhasil" tampil. Tanpa page error. |
| Sandi fixture dipulihkan lewat form browser | Login 200 |

**Log.** Diperiksa `docker logs --since 13:44Z` untuk gateway, API, dan worker-default, ditambah `/app/storage/logs` (kosong; `LOG_CHANNEL=stderr`). Hasilnya:

- 0 baris berisi `reset-password?`, hex 64 karakter, `token=`, atau `"token":`.
- Gateway dan API tidak mencetak access log, jadi pemeriksaan ini terbatas pada output yang ada.

Worker mencatat satu WARNING untuk forgot pukul 13:45:46: `Email reset tidak dikirim: mailer bertransport log atau tidak dikenal; token tidak boleh masuk log. Konfigurasikan SMTP.` Guard bekerja: forgot tetap 202, tidak ada token di log, dan tidak ada email.

Cakupan yang hanya dibuktikan PHPUnit (tidak diulang live): pencabutan sesi cookie oleh reset, dan race dua reset dengan token yang sama.

## 7. Pengirim email (SMTP), BLOCKED

Pemeriksaan read-only; hanya nama key dan flag, tanpa nilai:

- **DNS `morriz.tech`:** tidak ada MX/SPF.
- **Env staging:** `MAIL_MAILER=log` di api/scheduler/semua worker; `MAIL_HOST` kosong. Sama dengan `infra/env/.env.staging` lokal (`MAIL_MAILER=log`, `MAIL_FROM_ADDRESS` saja).
- **Coolify** (`coolify-db`, `instance_settings` dan `email_notification_settings`): SMTP dan Resend sama-sama disabled, tanpa host, password, atau key.
- **Scan nama env semua container VPS:** satu-satunya credential pengirim existing adalah `RESEND_API_KEY=set` milik project Coolify `morrizstore-public` (`morrizstore-production-api`, host `api.morrizstore.morriztech.cloud`, dan `morrizstore-production-worker`). Tidak ada `MAIL_HOST`/SMTP lain.
- **DNS publik `morriztech.cloud`:** tidak ada record domain Resend (`resend._domainkey`, `send` MX/SPF, DMARC). Domain pengirim terverifikasi tidak terlihat; status sebenarnya hanya ada di dashboard Resend pemilik.

**Opsi untuk pemilik** (keduanya tanpa layanan berbayar baru; dikirim ke koordinator, msg_a51a7ebe9e76):

1. **Resend existing.** Buat API key sending-only **terpisah** untuk RT05 staging; jangan memakai ulang key production MorrizStore. Pemilik mengisi `/root/.rt05/.env.staging` secara privat:

   ```dotenv
   MAIL_MAILER=smtp
   MAIL_HOST=smtp.resend.com
   MAIL_PORT=587
   MAIL_SCHEME=smtp
   MAIL_USERNAME=resend
   MAIL_PASSWORD=<key>
   ```

   `MAIL_FROM_ADDRESS` diisi alamat pada domain terverifikasi Resend. Untuk uji bisa memakai `onboarding@resend.dev`, tetapi Resend hanya mengirimkannya ke email pemilik akun Resend, sehingga inbox uji harus alamat itu.
2. **Gmail App Password** sesuai [SMTP_OPERATOR.md](SMTP_OPERATOR.md).

**Langkah sesudah pengirim tersedia:**

1. Recreate api dan worker (rollout), agar env baru terbaca.
2. Registrasi akun sintetis dengan alamat inbox uji, lalu forgot.
3. Konfirmasi email tiba di inbox (readback connector Gmail dimungkinkan), lalu tautan dan kode dipakai sekali.

Agent tidak menyalin key mana pun. Penolakan otomatis secret-store sebelumnya tetap dihormati.

## 8. Penerimaan inbox: kontrak helper (task `task_db5ecaaf6046`, menunggu "SMTP ready")

Status: **disiapkan, belum dijalankan.** Belum ada akun inbox, permintaan forgot, atau inspeksi tambahan sejak §7.

**Helper privat:** `C:/Users/USER/.rt05/m06-stage-check/inbox-flow.mjs`. State privat bersama ada di `inbox-flow.json` pada folder yang sama. Output hanya berisi nama langkah, status HTTP, dan timestamp; tidak ada password, token, bearer, atau alamat inbox.

**Isi email** (`ResetPasswordNotification`, antrean after-commit, terenkripsi):

- subjek "Reset kata sandi RT05 TAKEDA";
- tombol "Atur kata sandi baru" menuju `https://takeda-staging.morriz.tech/reset-password#token=…&email=…` (fragment, bukan query);
- kode untuk tempel di Android;
- masa berlaku 60 menit, sekali pakai.

| Langkah | Pelaksana | Isi |
| --- | --- | --- |
| 0. Pengirim | root | Mengisi env privat staging lewat jalur normal pemilik, lalu recreate `api` dan `worker-default` |
| 1. `precheck` | Claude | SSH, hanya nama/flag: `MAIL_MAILER=smtp` serta `MAIL_HOST`/`MAIL_PASSWORD`/`MAIL_FROM_ADDRESS` = set di api dan worker-default, plus waktu start container |
| 2. `prepare` | Claude | Memakai ulang atau mendaftarkan akun utama sintetis untuk `fixtures.inbox_test` (nomor rumah 77, token registrasi dari fixture). Merencanakan password ronde A/B secara acak di state. Menyimpan satu bearer "sesi lama". |
| 3. `forgot A` | Claude | Forgot 202, lalu memantau worker-default untuk `SendQueuedNotifications … DONE`. Ini hanya bukti transport, **bukan** bukti inbox. |
| 4. Inbox A | root | Konfirmasi email tiba di inbox (waktu terima, subjek, pengirim tanpa alamat privat). Simpan kode ke `inbox-flow.json` `rounds.A.token`. Buka **tautan** di Chrome, isi `rounds.A.password`, lalu tangkapan layar aman (tanpa kode/alamat). |
| 5. `verify A` | Claude | Bearer lama 401, password sebelumnya 422, password A 200, dan pemakaian ulang kode email via API 422 |
| 6. `forgot B` → Inbox B | Claude → root | Sama seperti ronde A; root menyimpan kode ke `rounds.B.token` |
| 7. Android B | Grok | Form "Lupa kata sandi" aplikasi: tempel kode, isi `rounds.B.password`; tangkapan layar aman |
| 8. `verify B` | Claude | Sama seperti `verify A` |

**Expiry dan race** tidak diulang dengan email nyata. Bukti mekaniknya ada di §6 (17/17) dan PHPUnit.

**Gate:** "provider PASS" hanya bila root mengonfirmasi penerimaan di inbox. Bila login provider tidak tersedia atau terjadi penolakan auto-review, blocker dicatat, M06 tetap belum passed, dan M07 tetap terkunci.

### Blocker konkret pengirim (dari root, 12 Oktober 2026)

**Hasil pemeriksaan root:**

- **Chrome:** pemilih akun Google yang terakhir dipakai untuk login Resend tidak memuat akun yang cocok dengan `fixtures.inbox_test`.
- **Resend:** `GET https://api.resend.com/domains` (read-only) dengan `RESEND_API_KEY` existing milik `morrizstore-production-api` menghasilkan **HTTP 401**. Key itu tetap di memori proses server; tidak disalin, dikonfigurasi, atau dipakai mengirim. Tidak ada key lain yang dicoba.
- **Akibatnya:** domain atau pengirim terverifikasi tidak dapat ditetapkan, dan key RT05 khusus tidak dibuat.

**Tindakan yang tidak terjadi:**

- Env staging tidak diubah; tetap `MAIL_MAILER=log`.
- Helper inbox tidak dijalankan: tidak ada akun inbox, forgot, atau email.

**Status:** inbox **BLOCKED** dan provider tidak PASS. M06 belum passed dan M07 terkunci.

**Yang dibutuhkan dari pemilik:** login ke akun pengirim yang benar (Resend dengan domain terverifikasi, atau Gmail dengan App Password), atau menetapkan alamat inbox uji yang sesuai pengirim itu. Setelah itu root mengisi env, recreate `api` dan `worker-default`, lalu helper §8 dijalankan dari langkah 1.

### Usulan teks untuk `SMTP_OPERATOR.md` (milik root, tidak diedit Claude)

> **Opsi pengirim existing yang ditemukan (12 Oktober 2026).** Pemeriksaan VPS hanya nama/flag: Coolify instance/team SMTP dan Resend disabled, `morriz.tech` tanpa MX. Satu-satunya credential pengirim existing adalah `RESEND_API_KEY` pada project Coolify MorrizStore production. Key itu **tidak** dipakai ulang.
>
> Bila memakai akun Resend yang sama, buat API key sending-only khusus RT05 staging. Lalu isi env privat:
>
> - `MAIL_MAILER=smtp`
> - `MAIL_HOST=smtp.resend.com`
> - `MAIL_PORT=587`
> - `MAIL_SCHEME=smtp` (STARTTLS)
> - `MAIL_USERNAME=resend`
> - `MAIL_PASSWORD=<key>`
> - `MAIL_FROM_ADDRESS`: alamat pada domain yang terverifikasi di Resend. `onboarding@resend.dev` hanya dikirim ke email pemilik akun Resend.
>
> Recreate `api` dan `worker-default` agar env terbaca.
>
> **Penerimaan:** jalankan `inbox-flow.mjs` dengan langkah `precheck`, `prepare`, `forgot A`, konfirmasi inbox oleh root, `verify A`, lalu ronde B untuk Android. Simpan timestamp terima dan tangkapan layar tanpa alamat/kode. Respons forgot 202 dan baris `DONE` worker tidak membuktikan inbox.

## 9. Penerimaan inbox nyata, 13 Oktober 2026 (task `task_e6babca81a7e`, dispatch `ctx_0ec47366d23f`)

Sumber HEAD `2dfe4ba`; runtime staging tetap `26a0b7e22cfe96941266cb9c194072512eca707e`. Semua waktu UTC. Tidak ada alamat penerima, kode, password, bearer, atau key di laporan ini; nilainya hanya ada di berkas privat `~/.rt05/m06-stage-check/` dan `/root/.rt05/` (mode 600).

### Pengirim

Root login ke Resend, mendapati domain `mail.morriztech.cloud` berstatus Verified, lalu membuat key khusus `rt05-takeda-staging-m06-20261013` (akses Sending, dibatasi ke domain itu). Key MorrizStore tidak dipakai. Root mendelegasikan perubahan env dan recreate kepada Claude untuk task ini.

| Langkah (skrip `/root/.rt05/m06-smtp-apply.sh`) | Hasil |
| --- | --- |
| phase1: key dari stdin ke `/root/.rt05/rt05-smtp.key` | mode 600; format key divalidasi tanpa dicetak |
| phase1: asersi rilis | `staging-current-sha` = `26a0b7e…`; image api/worker-default `rt05-api:26a0b7e…` ada lokal; compose dari `/opt/rt05-releases/26a0b7e…/infra` (`compose.production.yml` + `compose.vps.yml`), project `rt05-staging`, `RT05_IMAGE_TAG` eksplisit |
| phase1: env kandidat | Hanya baris `MAIL_*` diganti: `MAIL_MAILER=smtp`, `MAIL_HOST=smtp.resend.com`, `MAIL_PORT=587`, `MAIL_SCHEME=smtp`, `MAIL_USERNAME=resend`, `MAIL_PASSWORD` dari berkas key, `MAIL_FROM_ADDRESS=takeda@mail.morriztech.cloud`. `MAIL_FROM_NAME` tetap "RT05 TAKEDA" dari compose. Baris non-MAIL identik (15 → 20 baris). |
| phase1: isolasi | Env hasil compose untuk api dan worker-default dibandingkan dengan container berjalan: `APP_KEY`, `DATABASE_URL`, `DB_CONNECTION`, `REDIS_*`, `APP_ENV`, `APP_URL`, `QUEUE_CONNECTION`, `CACHE_STORE`, `SESSION_DRIVER` = same; selisih non-MAIL: none |
| phase2, 02:43:29 | Backup `/root/.rt05/.env.staging.bak-m06-smtp` (600), swap atomik, `compose up -d --no-build --no-deps --force-recreate --pull never api worker-default`. Tanpa build, migrasi, atau restart lain. |
| Sesudah phase2 | api `healthy`; api dan worker-default `running` di `rt05-api:26a0b7e…`, start 02:43:31; `MAIL_MAILER=smtp`. Tujuh container lain (ID dan StartedAt) dan volume tidak berubah. |

Rollback: `bash /root/.rt05/m06-smtp-apply.sh restore` (backup kembali, lalu recreate dua service yang sama).

### Kronologi

| Waktu | Kejadian | Status |
| --- | --- | --- |
| 02:44:11 | `precheck` PASS (penerima awal `fixtures.inbox_test`) | — |
| 02:44:26 | `prepare`: registrasi 201, login 200 | — |
| 02:44:31 → 02:44:41 | `forgot A` ke penerima awal: 202; worker-default `ResetPasswordNotification` RUNNING 02:44:36 → DONE 02:44:41 | **Transport saja.** Root tidak punya akses ke inbox ini, sehingga ronde ini tidak dihitung sebagai bukti inbox. Akun lama tetap ada, password tidak direset. |
| ~02:52 | Root memilih inbox Gmail pemilik yang terhubung sebagai penerima uji sintetis. Claude menyimpan snapshot fixture/state lama secara privat, mengganti `inbox_test`, membuat akun inbox baru (password acak, nomor rumah sintetis unik `inbox_nomor`), dan memulai state baru. Lima akun fixture lain tidak berubah. | — |
| 02:52:59 / 02:53:14 | `precheck` PASS; `prepare`: registrasi 201, login 200 | — |
| 02:53:15 → 02:53:25 | `forgot A`: 202; worker DONE 02:53:25 | Transport |
| 02:53:25 | Root: email A tiba di INBOX Gmail; pengirim dan penerima cocok; tautan dan kode 64-hex diambil dari email asli | **Inbox A** |
| sebelum 03:01 | Root membuka tautan email di Chrome (fragment hilang dari alamat, menjadi `/reset-password`), mengisi `rounds.A.password`, submit sekali → "Kata sandi berhasil direset. Silakan masuk kembali." | Reset via tautan |
| 03:01:35 | **`verify A` 4/4 PASS** (rincian di bawah) | — |
| 03:01:38 → 03:01:46 | `forgot B` dari helper: 202; worker DONE 03:01:46; root mengonfirmasi tiba di inbox 03:01:47 (DKIM/SPF PASS) | **Digantikan.** Form Android baru menampilkan isian kode setelah "Kirim kode reset", sehingga kode ini terotasi. |
| 03:09:56.910 → 03:10:09 | Grok mengirim forgot sekali dari form aplikasi Android; worker RUNNING 03:10:03 → DONE 03:10:09 | Transport |
| 03:10:10 | Root: email B tiba di INBOX Gmail; kode berbeda dari A dan dari B awal | **Inbox B** |
| 03:20:24.104 | Grok submit form Android sekali dengan kode email dan `rounds.B.password` → "Kata sandi berhasil direset" | Reset via kode |
| 03:22:55.348 | Login aplikasi dengan password baru mencapai Beranda (Grok) | — |
| 03:29:00 | **`verify B` 4/4 PASS** | — |

### Hasil `verify`

| Cek | A | B |
| --- | --- | --- |
| Bearer sesi sebelum reset (`/auth/me`) | 401 | 401 (sesi login sesudah A) |
| Password sebelumnya | 422 | 422 (password A) |
| Password baru yang direncanakan | 200 | 200 |
| Pakai ulang kode email via API | 422 | 422 |

Tangkapan layar root/Grok (tanpa alamat, kode, atau fragment): `docs/evidence/m06/screenshots/reset-inbox-browser-success-26a0b7e.png` (A, Chrome) dan `reset-b-beranda-26a0b7e.png` (B, Beranda).

### Invarian sesudah ronde B (03:29, read-only)

- api dan worker-default: `rt05-api:26a0b7e…`, `running`, `healthy`, restart 0, start 02:43:31 (tidak berubah sejak recreate).
- `staging-current-sha` = `26a0b7e…`. `.env.staging`, backup, dan key mode 600; tidak ada sisa env kandidat.
- Volume dan ID tujuh container lain tidak berubah. StartedAt `worker-exports` dan `worker-media` maju dari 02:21 ke 03:21: ini daur ulang per jam akibat `--max-time=3600` dengan restart `unless-stopped`, bukan tindakan task ini.
- Log sejak 02:43, worker-default: `ResetPasswordNotification … DONE` ×4 (A awal, A, B awal, B Android), FAIL 0, exception/error 0. api: exception/error 0. Kedua container: string 64-hex 0 dan `reset-password#`/`token=` 0 (token reset tidak bocor ke log).

### Perubahan helper privat

- Deteksi transport diperbaiki. Laravel mencatat job sebagai `App\Notifications\ResetPasswordNotification`, bukan `SendQueuedNotifications`, sehingga `forgot A` pertama sempat ditandai FAIL keliru. Langkah read-only `transport <A|B>` ditambahkan dan mengonfirmasi DONE tanpa forgot tambahan.
- Registrasi memakai `f.inbox_nomor ?? '77'`.

Dengan ini §8 langkah 0–8 selesai dengan email nyata. Expiry dan race tetap dibuktikan oleh §6 dan PHPUnit, tidak diulang. Usulan untuk `SMTP_OPERATOR.md` (§8): baris worker yang dicari adalah `ResetPasswordNotification … DONE`, dan pengirim staging sekarang `takeda@mail.morriztech.cloud` via Resend.

## Usulan kontrak/backend (tidak diterapkan)

1. **Throttle `login` dipakai bersama** oleh login mobile dan web-login (per IP+email). Sesuai desain anti brute-force; OpenAPI cukup menyebut bahwa kedua endpoint berbagi kuota.
2. **Pelaksana runtime smoke.** Satu pelaksana saja per rollout untuk menghindari dua restart Redis antrean. Ini catatan operasional, bukan perubahan kode.

## Berkas

- `docs/evidence/m06/CLAUDE_RESUMED_ACCEPTANCE.md` (laporan ini).
- **Privat, di luar Git:**
  - `~/.rt05/m06-stage-check/fixtures.json`: diperbarui dengan `registrationToken`, ID sintetis dari `verify.mjs`, dan `results.json`.
  - `~/.rt05/m06-stage-check/inbox-flow.mjs`, `inbox-flow.json`, snapshot `fixtures.before-recipient-switch-*.json` dan `inbox-flow.legacy-original-recipient-*.json` (§9).
  - VPS `/root/.rt05/`: `rt05-smtp.key`, `.env.staging.bak-m06-smtp`, `m06-smtp-apply.sh`, `m06-snap-*.txt` (semua mode 600, tanpa nilai di Git).
  - Skrip probe di scratchpad sesi Claude: `probe-extended.mjs`, `probe-weblogin.mjs`, `probe-reset.mjs`, `bootstrap_admin.py`, `exists.py`, beserta JSON hasilnya.
- `rest-api/` tidak diubah.
