# M06 — koreksi teks kata sandi pada skala besar

Tanggal 12 Oktober 2026. Basis `15885cbf2d08b3e0f2b66b32899e9e6857ff63d1`. Perubahan di [auth_pages.dart](../../../mobile/lib/features/auth/auth_pages.dart), [auth_access_test.dart](../../../mobile/test/auth_access_test.dart), dan berkas ini. Kontrak, backend, warna, Git, APK, dan unduhan perkakas tidak disentuh.

## Perilaku

Kolom kata sandi daftar dan reset memakai `passwordRuleDecoration`.

- Label daftar menjadi `Kata sandi`. Label reset menjadi `Kata sandi baru`. Keduanya `Text` dengan `maxLines: 3`, jadi kata label tidak dipotong elipsis.
- Aturan `Minimal 8 karakter, berisi huruf dan angka.` pindah ke `helperText` dengan `helperMaxLines: 8`.
- Pesan error kolom itu memakai `errorMaxLines: 16`, termasuk pesan batas 72 byte. Validator tidak diubah.

## Tes

`reset skala 2.0 menampilkan aturan dan pesan sandi utuh` memakai lebar 320 dan skala teks 2.0. Pada layar lupa sandi, `RenderParagraph.didExceedMaxLines` false untuk label, helper, pesan sandi pendek, dan pesan 72 byte. `takeException()` kosong. Tidak ada permintaan reset yang dikirim. Pesan `msg_eccdb5f2e396` meminta pemeriksaan teks terlukis, bukan hanya properti `errorMaxLines`; tes ini memakai `didExceedMaxLines`.

## Perintah

- `dart format` pada kedua berkas Dart: 1 berkas berubah.
- `flutter analyze` → `No issues found! (ran in 27.2s)`
- `flutter test` → `00:13 +115: All tests passed!`
- `node infra/scripts/check-repo.mjs` → `OK: 648 berkas dilacak; tanpa file terlarang, pola secret, .git bersarang, atau tautan relatif rusak; vercel.json setara.`

## Batas

Layar ganti kata sandi ditutup pada follow-up di bawah. Gambar `reset-scale20-final.png` tidak ada di pohon repositori; perbaikan mengikuti laporan elipsis itu.

## Follow-up ganti kata sandi

`passwordRuleHelper` dan `passwordRuleDecoration` berada di [account_access.dart](../../../mobile/lib/features/auth/account_access.dart). [auth_pages.dart](../../../mobile/lib/features/auth/auth_pages.dart) dan [warga_pages.dart](../../../mobile/lib/features/warga/warga_pages.dart) memakai helper yang sama. Kolom kata sandi baru pada ganti sandi berlabel `Kata sandi baru`. Validator, auth, peran, dan API tidak diubah. Kolom sandi saat ini dan pengulangan tetap seperti semula.

`ganti sandi skala 2.0 menampilkan aturan dan pesan utuh` memakai lebar 320 dan skala teks 2.0. `RenderParagraph.didExceedMaxLines` false untuk label, helper, pesan sandi lemah, dan pesan 72 byte. `takeException()` kosong. Validasi lokal gagal, jadi tidak ada `PATCH` kata sandi.

- `flutter analyze` → `No issues found! (ran in 8.5s)`
- `flutter test` → `00:12 +116: All tests passed!`
- `node infra/scripts/check-repo.mjs` → `OK: 648 berkas dilacak; tanpa file terlarang, pola secret, .git bersarang, atau tautan relatif rusak; vercel.json setara.`

UI kata sandi daftar, reset, dan ganti sandi siap dibekukan. CI dan emulator ulang ada pada root.

## Koreksi baris error

Tes `reset skala 2.0 menampilkan mismatch pengulangan utuh` di [auth_access_test.dart](../../../mobile/test/auth_access_test.dart) dijalankan lebih dulu, saat pengulangan masih memakai batas satu baris. Lebar 320 dan skala teks 2.0. Pesan `Pengulangan kata sandi tidak sama.` terlukis dengan `RenderParagraph.didExceedMaxLines` true (`Expected: false`, `Actual: <true>`). Tidak ada pengecualian RenderFlex. Setelah dekorasi diubah, tes terisolasi yang sama lulus: `00:01 +1: All tests passed!`

Setiap `InputDecoration` yang menampilkan error pada layar masuk, daftar, dan lupa sandi di [auth_pages.dart](../../../mobile/lib/features/auth/auth_pages.dart) memakai `errorMaxLines: 16`. Itu meliputi email dan sandi masuk, cabang `_field` selain aturan sandi, status hunian, nama serta hubungan anggota, email lupa sandi, kode reset, dan pengulangan. Di [warga_pages.dart](../../../mobile/lib/features/warga/warga_pages.dart), kolom sandi saat ini dan pengulangan pada ganti sandi juga `errorMaxLines: 16`. Kolom kata sandi baru tetap `passwordRuleDecoration`. Validator, Theme, dan API tidak diubah. Formulir laporan serta aspirasi tidak ikut.

Tes yang sama memeriksa teks terlukis utuh: `didExceedMaxLines` false, panggilan API hanya `forgot`, dan `takeException()` kosong.

- `dart format` pada tiga berkas Dart: 0 berubah.
- `flutter analyze` → `No issues found! (ran in 7.8s)`
- `flutter test` → `00:12 +117: All tests passed!`
- `node infra/scripts/check-repo.mjs` → `OK: 649 berkas dilacak; tanpa file terlarang, pola secret, .git bersarang, atau tautan relatif rusak; vercel.json setara.`

APK dan Git tidak dijalankan. CI serta APK final ada pada root.
