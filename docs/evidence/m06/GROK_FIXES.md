# M06 — perbaikan Flutter lanjutan (Grok)

Tanggal: 12 Oktober 2026. Branch `codex/m06-account-access`. Tidak ada commit, push, atau pindah branch. Sesi ini mengubah `mobile/` dan berkas ini. `rest-api/`, OpenAPI, dan `docs/PROGRESS.md` tidak diubah. APK tidak dibangun.

## Perilaku

Logout dan logout-semua mencatat token serta epoch sebelum request. Penghapusan token, akun, dan cache sesi terjadi di dalam antrean storage lewat `signOutIfCurrent`. Respons yang selesai setelah `signIn` baru tidak menghapus sesi itu, termasuk bila string token-nya sama tetapi epoch-nya baru. Epoch yang masih sama tetap menghapus token. `signOut` splash tetap tanpa syarat. Offline, timeout, 5xx, dan 403 tetap tidak menghapus token lokal.

401 membawa epoch request. Respons 401 milik epoch lama tidak mengakhiri sesi yang sudah masuk lagi, termasuk token string yang sama. 401 untuk epoch yang masih aktif tetap menghapus token.

`updateAkun` hanya menulis bila epoch permintaan masih berlaku. Penyegaran status akun tambahan memakai epoch sebelum `GET /auth/me`. Ganti kata sandi tidak menulis akun. Bila sesi sudah berganti sebelum respons selesai, halaman tidak menampilkan sukses dan tidak menutup layar sesi baru. Perangkat ini tetap masuk setelah ganti sandi yang epoch-nya masih sama.

Lupa sandi menampilkan pesan server bila pesan itu tidak mengklaim email terkirim. `AuthController` masih mengembalikan "Jika email terdaftar, tautan reset kata sandi telah dikirim." Kalimat itu diganti "Jika email terdaftar, permintaan reset kata sandi telah diterima dan diantrekan." Tambahan bahwa pesan tidak menyatakan email ada tetap ada.

Kata sandi baru pada daftar, daftar tambahan, reset, dan ganti sandi mengikuti `PasswordPolicy`: minimal 8 karakter Unicode (`runes`, bukan unit UTF-16), huruf dan angka, maksimal 72 byte UTF-8, tanpa NUL. Pesan byte dan NUL sama dengan server. Login tidak memakai batas 72 byte. Error field server tetap tampil pada kolomnya. Tidak ada layar administrasi M07.

## Perintah

SDK: Flutter 3.47.7, Dart 3.13.5, `C:\Users\USER\dev\flutter`. Perintah dari `mobile/` kecuali check-repo.

| Perintah | Exit |
| --- | --- |
| `C:\Users\USER\dev\flutter\bin\dart.bat format lib test` | 0, 0 berkas berubah pada jalan terakhir |
| `C:\Users\USER\dev\flutter\bin\flutter.bat analyze` | 0, No issues found (9,8s) |
| `C:\Users\USER\dev\flutter\bin\flutter.bat test` | 0, 107 tes lulus |

## Batas

- Kalimat API lupa sandi masih mengklaim terkirim. Klien tidak menampilkannya. Perubahan kalimat di `AuthController` milik backend.
- APK staging, emulator, SMTP, dan health staging tidak dijalankan. Unduhan NDK sebelumnya macet di 0 byte.
- Tidak ada kredensial atau data warga. Fixture tes tetap sintetis.

## Berkas

- `mobile/lib/core/session.dart`
- `mobile/lib/core/api.dart`
- `mobile/lib/features/auth/account_access.dart`
- `mobile/lib/features/auth/auth_pages.dart`
- `mobile/lib/features/warga/warga_pages.dart`
- `mobile/test/session_test.dart`
- `mobile/test/auth_access_test.dart`
