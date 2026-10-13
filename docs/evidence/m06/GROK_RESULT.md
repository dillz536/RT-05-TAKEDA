# M06 — hasil Flutter (Grok)

Tanggal: 12 Oktober 2026. Branch `codex/m06-account-access`. Tidak ada commit, push, atau pindah branch. Sesi ini hanya mengubah `mobile/` dan berkas ini. `rest-api/`, OpenAPI, dan `docs/PROGRESS.md` tidak diubah.

## Perilaku

Form masuk memakai email dan kata sandi aplikasi. Tidak ada tombol OAuth. Token registrasi tetap string enam digit, termasuk awalan nol. Kata sandi baru minimal 8 karakter dan berisi huruf serta angka. Pengulangan sandi adalah kolom terpisah. Alamat rumah dan, untuk akun tambahan, hubungan keluarga wajib diisi. Baris anggota yang terisi sebagian ditolak di klien. Error server `anggota.N.nama` dan `anggota.N.hubungan_keluarga` tampil pada kolomnya.

Submit dikunci selama request. Tombol keyboard pada sandi masuk tidak mengirim dua kali. Setelah `await`, halaman yang sudah lepas tidak memanggil `signIn`. Akun utama yang keluarganya belum `terverifikasi` menampilkan "Akun utama sudah aktif. Keluarga belum diverifikasi pengurus." Penolakan akun tambahan tetap di layar menunggu yang sudah ada.

Lupa sandi menampilkan kalimat server "Jika email terdaftar, tautan reset kata sandi telah dikirim." dan menjelaskan bahwa kalimat itu tidak menyatakan email ada. Kode dapat disalin, lalu reset mengirim email, token string, password, dan password_confirmation.

Keluar dan keluar-semua memanggil server lebih dulu. Token lokal dihapus hanya setelah server menjawab, termasuk 401. Offline, timeout, dan 5xx menampilkan bahwa server belum mengonfirmasi pencabutan; token di perangkat tetap. Ganti sandi tidak mengeluarkan perangkat ini. `session.dart` dan `api.dart` tidak diubah. Tidak ada layar administrasi M07. Tidak ada berkas Stitch mobile di repo; warna gapura, navy, kuning, dan sky serta font yang sudah terpasang dipertahankan.

## Perintah

SDK: Flutter 3.47.7, Dart 3.13.5, `C:\Users\USER\dev\flutter`. Perintah dijalankan dari `mobile/` kecuali check-repo.

| Perintah | Exit |
| --- | --- |
| `C:\Users\USER\dev\flutter\bin\dart.bat format lib test` | 0 |
| `C:\Users\USER\dev\flutter\bin\flutter.bat analyze` | 0, No issues found |
| `C:\Users\USER\dev\flutter\bin\flutter.bat test` | 0, 98 tes lulus |
| `flutter.bat build apk --debug --dart-define=FLAVOR=staging --dart-define=API_BASE_URL=https://takeda-staging.morriz.tech` | tidak selesai |

Build debug staging berhenti di Gradle: "Preparing Install NDK (Side by side) 28.2.13676358". Berkas unduhan `android-ndk-r28c-windows.zip` tetap 0 byte dan folder NDK hanya 171 byte setelah lebih dari 15 menit, sementara CPU proses Java nyaris tidak bergerak. Proses build dihentikan. APK tidak dihasilkan. Docker dan WSL tidak dipasang. `flutter doctor` juga mencatat cmdline-tools tidak ada dan status lisensi Android tidak diketahui; emulator `sdk gphone16k` terlihat, tetapi skenario registrasi/login tidak dijalankan di emulator.

## Batas

- Respons 403 untuk akun nonaktif atau diarsipkan menghapus token di server. Klien tetap menyimpan token lokal, mengikuti M05. Pembersihan lokal memakai "Keluar dari perangkat ini" di splash dan tidak mengaku server sudah mencabut token.
- Inbox SMTP, health staging, dan penerimaan emulator ada pada gate root, bukan bukti sesi ini.
- Tidak ada kredensial atau data warga dalam tes. Fixture tes tetap sintetis di `mobile/test/`.

## Berkas

- `mobile/lib/features/auth/account_access.dart`
- `mobile/lib/features/auth/auth_pages.dart`
- `mobile/lib/features/warga/warga_pages.dart`
- `mobile/lib/core/widgets.dart`
- `mobile/test/auth_access_test.dart`

`node infra/scripts/check-repo.mjs` dari root repo: exit 0. Keluaran: `OK: 617 berkas dilacak; tanpa file terlarang, pola secret, .git bersarang, atau tautan relatif rusak; vercel.json setara.`
