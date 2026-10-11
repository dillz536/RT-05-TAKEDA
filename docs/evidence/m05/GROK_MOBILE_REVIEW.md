# Review independen M05 — Grok

11 Oktober 2026. Baca-saja pada `codex/m05-mobile-foundation` HEAD `13de24e230b44dd78b382260013108b5daf1d84a` (draft PR 17), diff `3a8be79..13de24e` untuk `mobile/`. Dibaca: [M05_PLAN.md](M05_PLAN.md) dan [CLAUDE_FOUNDATION_RESULT.md](CLAUDE_FOUNDATION_RESULT.md) termasuk addendum Codex. Tidak ada edit `mobile/**`, `flutter test`, analyze, format, Gradle, CI, emulator, atau VPS.

## Verdict

**Kode fungsional M05: PASS.** Tidak ada blocker pada sesi, router, konfigurasi, fixture, rahasia, backup, merek, atau perubahan bisnis M06. Gate M05 belum ditutup di sini: CI, APK, dan request emulator tetap menunggu bukti root.

## Butir yang diperiksa

- Sesi: `restore` menghapus token hanya pada 401 (`mobile/lib/core/session.dart:103-107`). Offline, timeout, 503, dan 403 mempertahankan token dengan `akun` null (`:108`). `signedIn` butuh token dan akun (`:54`), jadi data tidak ditampilkan sebagai sah. Epoch mengabaikan restore/401 basi (`:82`, `:99-104`, `:137-141`). `signOut` dan `expire` menghapus storage (`:129-141`). Interceptor hanya `expire` bila Bearer sama dengan token request (`mobile/lib/core/api.dart:73-77`). Tes di `mobile/test/session_test.dart:51-67` dan `:97-160` menutup kasus itu.
- Router: tamu hanya login/daftar/lupa sandi, pending terkunci, aktif ke beranda, kedaluwarsa ke login, health/sesi belum siap ke splash (`mobile/lib/app/router.dart:32-40`). Shell tidak membangun tab bila sesi tidak sah (`mobile/lib/app/shell.dart:26-28`). Tes guard dan alur ada di `mobile/test/router_test.dart:27-66` dan `:134-172`.
- Konfigurasi: flavor persis dev/staging/prod, HTTPS untuk non-dev, HTTP dev hanya `10.0.2.2` dan `127.0.0.1`, port 1–65535, dan bentuk mentah menolak `https:host`, path `/../`, backslash, `?`/`#` kosong, serta `@` (`mobile/lib/app/config.dart:40-76`). Debug Android hanya cleartext ke dua host itu (`mobile/android/app/src/debug/res/xml/network_security_config.xml:4-7`). Rilis menolak cleartext (`mobile/android/app/src/main/res/xml/network_security_config.xml:4`). Tes: `mobile/test/config_test.dart:14-94`.
- Health memakai Dio terpisah tanpa token ke `/health/live` (`mobile/lib/core/health.dart:55-74`). Adapter palsu di aplikasi bernilai null (`:78-79`). Implementasi palsu hanya di `mobile/test/support/`.
- `android:allowBackup="false"` di `mobile/android/app/src/main/AndroidManifest.xml:4`.
- SVG gapura identik dengan `website/public/images/logo/` (hash Git sama untuk kedua berkas). Font terbundel berlisensi OFL; `google_fonts` tidak ada di `mobile/pubspec.yaml`. Logo in-app adalah `SvgPicture` (`mobile/lib/core/widgets.dart:201`).
- Skala teks 1,3 dan 2,0 pada login (lebar 360 dan 320) serta kelima tab shell warga dan pengurus, dengan assertion tidak ada exception (`mobile/test/text_scale_test.dart:12-47`).
- Endpoint login, reset, iuran, reverse, dan ganti sandi sudah ada di `3a8be79`. Diff `providers.dart` dan `format.dart` hanya tata baris. Tidak ada klien Neon. `git grep` pada `mobile/lib` tidak menemukan DSN atau kunci.

Penghitungan sumber: 77 tes (app 13, config 9, health 8, session 14, router 14, brand 11, text scale 8). Addendum Codex menyebut 77/77, analyzer bersih, dan format 0. Saya tidak mengulang perintah itu. Self-test guard `node infra/scripts/check-mobile-build-config.mjs --self-test` pada HEAD ini exit 0. `node infra/scripts/check-repo.mjs` setelah berkas ini ada: OK: 523 berkas dilacak; tanpa file terlarang, pola secret, .git bersarang, atau tautan relatif rusak; vercel.json setara.

## Bukan blocker M05

- CI, checksum APK, dan health emulator/HP belum dibuktikan oleh review ini.
- Keluar di halaman pending tetap `signOut` lokal (`mobile/lib/features/auth/auth_pages.dart:396`). Logout server akun tetap `POST /auth/logout` (`mobile/lib/features/warga/warga_pages.dart:1193`). Itu batas M06 yang sudah ada.
- Label navigasi dibatasi 1,3× dan chip status 1,5×. Halaman detail tidak masuk tes skala.
- APK debug bukan rilis bertanda tangan. Domain `takeda.morriz.tech` adalah konfigurasi nonsensitif M16, bukan bukti deploy.
