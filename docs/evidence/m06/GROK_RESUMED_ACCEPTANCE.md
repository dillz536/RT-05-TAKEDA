# M06 — kesiapan mobile, build, dan staging

12 Oktober 2026. Checkout `codex/m06-account-access`, HEAD `26a0b7e22cfe96941266cb9c194072512eca707e`. Tugas ini tidak commit, push, atau pindah branch. M07 tidak dibuka. M06 belum lulus.

## Sumber

`git diff --stat 7d42d56..26a0b7e` hanya mengubah [PROGRESS.md](../../../docs/PROGRESS.md), [LIVE_RESULTS.md](LIVE_RESULTS.md), dan satu screenshot. Byte `mobile/`, `rest-api/`, dan `infra/` sama dengan `7d42d56`. Suite lokal tidak diulang. CI pada HEAD ini dijalankan GitHub dan lulus.

## CI pada HEAD

| Workflow | Hasil | Run |
| --- | --- | --- |
| API verification | success | [38240095826](https://github.com/morrispes5/RT-05-TAKEDA/actions/runs/38240095826) |
| Mobile verification | success; job plan, verify, APK dev, dan APK staging | [38240095840](https://github.com/morrispes5/RT-05-TAKEDA/actions/runs/38240095840) |
| Infra images | success | [38240095828](https://github.com/morrispes5/RT-05-TAKEDA/actions/runs/38240095828) |
| Repository checks | success | [38240095841](https://github.com/morrispes5/RT-05-TAKEDA/actions/runs/38240095841) |

Bukti layar reset skala 2,0 pada APK `7d42d56` tetap berlaku untuk sumber mobile yang sama. Artefak APK `26a0b7e` dibangun ulang oleh CI; checksum unduhan baru tidak dihitung karena sumber Dart tidak berubah.

## Staging sebelum image ini

`GET https://takeda-staging.morriz.tech/health/ready` pada 12 Oktober 2026 13:09:51 GMT menjawab HTTP 200. Body `{"status":"ready","checks":{"database":"ok"}}`. `X-Request-Id` `20abc8c6-624d-475b-ae9a-156de5746fdc`. `Referrer-Policy` dari Caddy `strict-origin-when-cross-origin`. Pemeriksaan itu merekam runtime sebelum image SHA `26a0b7e`.

## Deploy SHA 26a0b7e

Script [deploy-staging-immutable.sh](../../../infra/scripts/deploy-staging-immutable.sh) SHA256 `ad80d976f8b04f3a9d6969d9e8f70995c55b645170202cdcea7a340bbd75a096`. Koordinator menjalankan script di konsol Hostinger, bukan di PC ini. `sha256sum` konsol cocok dengan hash itu.

Footer konsol: `Selesai staging SHA 26a0b7e22cfe96941266cb9c194072512eca707e`. Job nohup `2130943` Done. Tag image `26a0b7e` pada API, worker, scheduler, dan web. Redis dipertahankan. Tidak ada port host yang dipublikasikan. Log privat `/root/rt05-m06-26a0b7e-deploy.log` tidak dicetak. Env, volume, dan layanan lain tidak diubah oleh perintah ini. Smoke runtime pada rilis yang sama dicatat di bagian berikut. Tidak ada deploy ulang.

## Smoke publik sesudah deploy

12 Oktober 2026, 13:25–13:27 GMT. Tidak ada mutasi CSRF dan tidak ada login. Nilai cookie tidak dicatat.

| Probe | Hasil |
| --- | --- |
| `GET /health/ready` | 200 `{"status":"ready","checks":{"database":"ok"}}`. `X-Request-Id` `e65870f0-faf8-4439-a89b-277de87035c6` |
| `GET /health/live` | 200 `{"status":"ok"}` |
| `GET /profil` | 200 HTML. Cookie `XSRF-TOKEN` Secure SameSite=lax; `rt-05-takeda-session` HttpOnly Secure SameSite=lax. `Referrer-Policy` `strict-origin-when-cross-origin` |
| `GET /reset-password` | 200 HTML. `Referrer-Policy` `no-referrer`. `X-Frame-Options` `DENY`. CSP `default-src 'none'`. Cookie `rt05_api_xsrf` Secure SameSite=lax; `rt05_api_session` HttpOnly Secure SameSite=lax. `X-Request-Id` `a622ae2a-3530-4dbc-879d-365396b44d29` |
| `GET /sanctum/csrf-cookie` | 204. Cookie API sama. `Cache-Control` `no-store, private`. `X-Request-Id` `df7399db-d02d-43c5-a31e-1eb777fc63cc` |
| `GET /api/v1/` | 200 `RT05 TAKEDA API` `v1`. `request_id` `ee3b4e08-b48d-4083-b796-76136ea60e9a` |
| `GET /auth-assets/rt05-mark.svg` | 200 `image/svg+xml` |
| `GET /auth-assets/public-sans-latin-wght.woff2` | 200 `font/woff2`, 26832 byte |
| `GET /auth-assets/bricolage-grotesque-latin-wght.woff2` | 200 `font/woff2`, 41344 byte |

Halaman reset memakai `rt05-mark.svg` dan dua font di atas. Path `/auth-assets/logo-gapura.svg` tidak dirujuk halaman.

## Smoke runtime

12 Oktober 2026, pada VPS, tanpa deploy ulang. Perintah tepat `bash /opt/rt05-releases/26a0b7e22cfe96941266cb9c194072512eca707e/infra/scripts/staging-runtime-smoke.sh`. Berkas skrip di rilis itu SHA256 `d74df7482c514c61812bd8153ba05ed5dbccce266d888b1f722f1997134aa93f`, sama dengan salinan repo. Exit 0. Baris akhir `PASS staging-runtime-smoke`.

Event `01a1305e-6dbe-71f3-bec4-ff4af89c01c9`. Sebelum worker hidup lagi: `state=published attempts=1 inbox=0 error_code=none`. Sesudah restart AOF antrean: `state=completed attempts=1 inbox=0 error_code=none`. Tidak ada replay. Baris skrip `sentinel AOF bertahan` tercetak; cache tidak dihentikan. Hanya `worker-default` yang berhenti lalu hidup lagi. Redis antrean di-restart. Redis cache tetap sehat saat worker mulai.

Heartbeat: `worker-default` ok age=1s, `worker-media` ok age=12s, `worker-exports` ok age=12s, `scheduler` ok age=55s. Image sesudah smoke tetap `rt05-api:26a0b7e22cfe96941266cb9c194072512eca707e` `sha256:d297ee440c9cb844fe2c0acd6ad1a7d958a5feeff80b5e3cf13508a0b6810bd5` dan `rt05-web:26a0b7e22cfe96941266cb9c194072512eca707e` `sha256:fe0faf29ee7a685d5af541909b15c098f0fab8237286ecd952a2f986733a63a5`. Volume yang dipertahankan: `rt05-staging_redis-data` 2026-10-11T00:26:59Z, `rt05-staging_api-storage` 2026-10-11T00:27:01Z, `rt05-staging_apk-downloads` 2026-10-11T00:27:19Z. DbSmoke pooled dan direct sama-sama rollback, sisa baris 0. Kontainer migrasi sekali jalan `rt05-staging-migrate-run-8d44c31cc00d` dibuat oleh skrip. Env tidak dicetak.

## Perangkat Android

`emulator-5554`, paket `tech.morriz.takeda`. APK terpasang memakai sumber Dart yang sama dengan `7d42d56` dan `26a0b7e`, flavor staging, host `takeda-staging.morriz.tech`. Skala font dikembalikan ke `1.0`. Mode pesawat dimatikan. Tidak ada kata sandi, token, atau submit registrasi. Form kosong ditahan validator lokal, jadi tidak ada `POST /auth/mobile-login`.

| Pemeriksaan | Hasil |
| --- | --- |
| Health di layar masuk | `health ok`. Request `a2ee39a5-8396-4231-ac34-45bdde2f9f75`, lalu `df86184c-7dfe-4d1e-b36f-800cd67e0885` pada skala 2,0, lalu `1a984609-f23b-4e8f-a1d5-94b3b7be6732` sesudah Coba lagi |
| Masuk kosong, skala 1,3 dan 2,0 | `Masukkan email yang valid.` dan `Masukkan kata sandi` tampil utuh |
| Tombol daftar, skala 2,0 | `Daftar akun utama rumah` dan `Daftar akun tambahan keluarga` membungkus dua baris, utuh |
| Daftar utama, skala 2,0 | Subjudul aktif/belum diverifikasi utuh. Helper sandi dua baris. Kolom `Ulangi kata sandi` terlihat |
| Daftar tambahan, skala 2,0 | Subjudul menunggu persetujuan pengurus utuh. Tidak membuat permohonan |
| Offline | Pesawat serta wifi dan data mati. Pesan `Tidak dapat terhubung ke server. Periksa koneksi internet.` `health gagal (offline)`. PID `28639` |
| Coba lagi | PID tetap `28639`. Kembali ke masuk dan `health ok`. Tidak ada sesi tersimpan |
| Log | 400 baris terakhir logcat tanpa `FATAL`, `AndroidRuntime`, `UnhandledException`, `RenderFlex`, atau `flutter` |

Gambar:

- [Masuk skala 1,3](screenshots/login-scale13-26a0b7e.png)
- [Masuk kosong skala 1,3](screenshots/login-empty-scale13-26a0b7e.png)
- [Masuk skala 2,0](screenshots/login-scale20-26a0b7e.png)
- [Masuk kosong skala 2,0](screenshots/login-empty-scale20-26a0b7e.png)
- [Daftar utama skala 2,0](screenshots/register-utama-scale20-26a0b7e.png)
- [Daftar tambahan skala 2,0](screenshots/register-tambahan-scale20-26a0b7e.png)
- [Tamu offline](screenshots/guest-offline-26a0b7e.png)
- [Tamu sesudah Coba lagi](screenshots/guest-retry-26a0b7e.png)

Pemeriksaan tamu di atas tidak diulang.

## Penerimaan akun pada emulator yang sama

Akun sintetis perangkat, bukan akun fixture Claude dan bukan warga asli. Token registrasi dibaca dari fixture privat dan tidak masuk laporan atau gambar. Kata sandi tidak dicatat. APK tidak dihapus.

| Langkah | Hasil |
| --- | --- |
| Daftar utama `device.utama.m06@example.test`, nama `UjiPerangkat` | Beranda terbuka. Rumah `UJI-GG1`, Blok Uji, JalanUjiPerangkat No. GG1 |
| Tab Akun | Chip `Warga` dan `Akun utama`. Keluarga `Belum diverifikasi`. Kalimat `Akun utama sudah aktif. Keluarga belum diverifikasi pengurus.` |
| Keluar | Kembali ke Masuk. Kolom email dan sandi kosong |
| Daftar tambahan `device.tambahan.m06@example.test`, hubungan `Anak`, alamat kunci sama | Kirim pertama tertahan lokal: `Pengulangan kata sandi tidak sama.` Kedua kolom diisi ulang, panjang sama, lalu satu permohonan. Layar `Menunggu persetujuan`, status `Ditinjau`, tombol `Periksa lagi` dan `Keluar` |
| Masuk lagi sebagai akun utama | Beranda `Selamat datang, UjiPerangkat` |
| Sesi offline | Mode pesawat, proses dihentikan, aplikasi dibuka lagi. Pesan `Tidak dapat terhubung ke server. Periksa koneksi internet.` Tombol `Keluar dari perangkat ini`. Pita `health gagal (offline)`. Tidak ada nama atau email |
| Coba lagi | PID tetap `2715`. Jaringan dinyalakan. Beranda akun utama kembali. Font `1.0`. Mode pesawat mati |

Gambar akun:

- [Akun keluarga belum diverifikasi](screenshots/account-unverified-26a0b7e.png)
- [Keluar ke halaman masuk](screenshots/logout-login-26a0b7e.png)
- [Tambahan menunggu persetujuan](screenshots/pending-additional-26a0b7e.png)
- [Masuk lagi ke beranda](screenshots/login-return-26a0b7e.png)
- [Sesi offline](screenshots/session-offline-26a0b7e.png)
- [Beranda sesudah Coba lagi](screenshots/session-retry-26a0b7e.png)

Nomor rumah tampil `GG1` karena `adb input text` menggandakan huruf G kapital; yang diketik `G1`. Jalan tersimpan `JalanUjiPerangkat` tanpa spasi, jadi prefiks `jalan` tidak dilepas. Panjang kolom nama tambahan sebelum kirim 11. Layar pending tidak menampilkan nama, jadi nama tersimpan tidak dibaca ulang. Kartu `Demo: kerja bakti` dan `Demo: pengumuman` sudah ada di staging. Bukan fakta RT baru.

Emulator pada akhir bagian itu masih memegang sesi akun utama. Pemeriksaan ganti sandi ada di bagian berikut.

## Ganti sandi, keluar semua, dan sesi dicabut

12 Oktober 2026. Tidak ada deploy ulang, restart kontainer, atau akun baru. Akun `accounts.mobile` pada fixture privat ditolak satu kali: `Email atau kata sandi salah.` Sandi fixture itu tidak diubah dan tidak dicoba lagi.

Sisa pemeriksaan memakai akun perangkat yang sudah terdaftar, `device.utama.m06@example.test`. Sandi sementara tidak dicatat. Sandi semula dipulihkan satu kali lewat `PATCH /api/v1/auth/password`, jawaban HTTP 200, lalu dibuktikan dengan masuk.

| Langkah | Hasil |
| --- | --- |
| Ganti sandi di aplikasi | Form tertutup. Hierarki kembali ke Akun, lalu Beranda, tanpa masuk ulang. Perangkat ini tetap masuk |
| Sandi lama | Ditolak. `Email atau kata sandi salah.` Kolom dikosongkan sebelum gambar |
| Sandi baru | Masuk berhasil. Beranda `UjiPerangkat` |
| Token tersimpan | Pemulihan sandi dari klien lain mencabut bearer perangkat. Proses dihentikan lalu dibuka lagi, PID `6259` menjadi `12743`. Banner `Sesi berakhir. Silakan masuk kembali.` Tidak ada nama, email, atau data akun. Tombol keluar perangkat tidak tampil |
| Masuk dengan sandi semula | Beranda |
| Keluar dari semua perangkat | Dialog sandi dikonfirmasi. Kembali ke Masuk kosong. Percobaan dialog pertama dibatalkan tanpa kirim karena kolom belum terfokus |
| Masuk sesudah keluar semua | Beranda `UjiPerangkat`, rumah `UJI-GG1` |

Gambar:

- [Sandi lama ditolak](screenshots/password-old-rejected-26a0b7e.png)
- [Beranda sesudah sandi baru](screenshots/login-after-change-26a0b7e.png)
- [Sesi berakhir](screenshots/session-revoked-26a0b7e.png)
- [Beranda sesudah sandi dipulihkan](screenshots/login-after-restore-26a0b7e.png)
- [Tamu sesudah keluar semua](screenshots/logout-all-guest-26a0b7e.png)
- [Beranda sesudah masuk lagi](screenshots/login-after-logout-all-26a0b7e.png)

Font `1.0`. Mode pesawat mati. Emulator tetap terpasang. Sesi akun perangkat berada di Beranda pada akhir bagian 12 Oktober itu.

## Reset inbox di Android, putaran B

13 Oktober 2026. Runtime tetap `26a0b7e`. HEAD `2dfe4ba` hanya dokumen; byte `mobile/`, `rest-api/`, dan `infra/` sama. Tidak ada pasang ulang, build, atau deploy.

Emulator `emulator-5554`, paket `tech.morriz.takeda`, PID `12743`. Font `1.0`. Mode pesawat mati. Wifi hidup. Sesi akun perangkat pada emulator dikeluarkan satu kali supaya halaman tamu terbuka. Sandi akun itu tidak diubah. Tidak ada keluar dari semua perangkat.

Satu ketukan `Kirim kode reset` pada `2026-10-13T03:09:56.910Z`. Tidak ada permintaan forgot dari helper. Kode yang sudah tersimpan sebelumnya tidak diketik. Form langkah kedua tetap terbuka. Kode pengganti dan sandi yang direncanakan diisi di kolom aplikasi. Panjang kode 64. Panjang kedua kolom sandi 22. `Simpan kata sandi baru` dikirim sekali pada `2026-10-13T03:20:24.104Z`. Aplikasi menampilkan `Kata sandi berhasil direset. Silakan masuk kembali.` lalu kembali ke Masuk dengan kolom kosong.

Masuk dengan sandi baru pada `2026-10-13T03:22:55.348Z` sampai Beranda. Hierarki tidak memuat `@`. Gambar [Beranda sesudah reset](screenshots/reset-b-beranda-26a0b7e.png) menampilkan `Kepala Sintetis Inbox M06` dan rumah `UJIM06-887623`, tanpa kode atau sandi.

Pada saat masuk dan saat bagian ini ditulis, state putaran B masih `verifiedAt` kosong. Pemeriksaan verifier belum dicatat di laporan ini. M06 belum lulus. M07 tidak dibuka.

## Batas

Reset Android putaran B memakai kode yang masuk ke inbox dan sandi yang sudah direncanakan. Verifikasi server putaran B belum tercatat. Masuk `accounts.mobile` tetap gagal satu kali pada pemeriksaan 12 Oktober, dan sandi fixture itu tidak diubah. M06 belum lulus. M07 tidak dibuka. Stitch tetap pada koordinator. Production dan data warga tidak disentuh. Grok tidak membuat commit.

`node infra/scripts/check-repo.mjs` → `OK: 685 berkas dilacak; tanpa file terlarang, pola secret, .git bersarang, atau tautan relatif rusak; vercel.json setara.`

## Gate coordinator13 Oktober

Sesudah laporan perangkat ini, Claude verifier B4/4 PASS pada03:29:00.769UTC.
Coordinator meninjau screenshot aman dan menerima gate M06 passed; bukti final
ada pada [INBOX_ACCEPTANCE.md](INBOX_ACCEPTANCE.md). Status pending di atas
merupakan keadaan saat Grok menyelesaikan tugas perangkat, sebelum verifier.
M07 menjadi tahap berikutnya; tidak diklaim selesai oleh laporan ini.
