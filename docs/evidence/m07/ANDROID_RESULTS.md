# M07 — penerimaan Android (staging, sintetis)

**Status: PASSED untuk alur minimal yang diminta.** 13 Oktober 2026, 06:22–06:35 UTC
(status bar screenshot menampilkan WIB, UTC+7).

| Item | Nilai |
| --- | --- |
| Source API | `979634fb7ae847f6c743f0ae7e11ec575b1fe722`, live 77/77 ([LIVE_RESULTS.md](LIVE_RESULTS.md)) |
| APK | CI run `38353725721`, `rt05-takeda-staging-debug.apk`, SHA-256 `6210638aadaa6bcb791ad414d9fa4849e02135f684c250bb1c731eee8d322297` (diunduh dan dipasang root; tidak dipasang ulang di sini). Package `tech.morriz.takeda`, versionName 0.1.0, lastUpdateTime 2026-10-13 13:12:16 WIB |
| Perangkat | `emulator-5554` Pixel 8a milik root, override 960x2133, density 480 (320 dp lebar), font_scale 1,0 (2,0 hanya untuk alur 409) |
| Backend | `https://takeda-staging.morriz.tech` (footer login: staging, health ok) |
| Interaksi | Tap/scroll/ketik ADB nyata pada UI; dump uiautomator segar sebelum setiap pembacaan. Kredensial diketik dari fixture privat tanpa dicetak. Tanpa overlay/edit gambar. |

Aktor (kunci [LIVE_RESULTS.md](LIVE_RESULTS.md)): `a1` akun utama aktif, `a3` tambahan disetujui,
`a2` tambahan menunggu, admin = admin uji M06. `p1` (diarsipkan) tidak dipakai. Fixture M06 lain
tidak disentuh.

## Alur dan bukti

| Waktu UTC | Aksi di UI | Hasil / gema server |
| --- | --- | --- |
| 06:22:51 | Login `a1` lewat form Masuk (HTTPS) | Beranda: nama anggota sintetis, rumah S7-1, iuran Oktober 2026 Rp75.000 belum dibayar |
| 06:24 | Akun → kartu Keluarga | 4 anggota, tombol `Ubah data keluarga` dan `Tambah anggota` tampil. API admin: versi 10, 4 anggota. [Sebelum](screenshots/m07-android-a1-family-before.png) |
| 06:25 | font_scale 2,0; `Tambah anggota`; isi nama `Sintetis M07 Android`, hubungan `Anak`, WhatsApp sintetis `0000 0000 0707` (bukan kontak) | Draft di form, belum dikirim |
| 06:26:24 | Pembaruan eksternal lewat API admin: verifikasi keluarga fixture M07 baru (K1) | 200, `terverifikasi`, versi 10 → 11 |
| 06:26:24–29 | Kirim draft dari UI (versi lama) | 409 tampil di form: “Data keluarga sudah berubah. Muat ulang lalu coba lagi. Teks yang Anda ketik tetap ada. Periksa data terbaru, lalu kirim lagi.” plus “Data terbaru: …”. Nama, hubungan, dan WhatsApp tetap terisi. [Draft tetap](screenshots/m07-android-a1-409-draft-kept-scale20.png), [pesan utuh setelah scroll](screenshots/m07-android-a1-409-full-message-scale20.png) |
| 06:27:28 | Kirim ulang dari tombol yang sama | Kembali ke keluarga: anggota baru tampil, status Terverifikasi. API: versi 12, 5 anggota, **tepat satu** `Sintetis M07 Android` dengan WhatsApp (tanpa duplikat). [Keluarga diperbarui](screenshots/m07-android-a1-family-updated-scale20.png) |
| 06:28 | font_scale kembali 1,0; Akun → `Keluar` (perangkat ini saja) | Kembali ke Masuk |
| 06:28:50 | Login `a3` → Akun | Keluarga yang sama termasuk anggota baru; **tanpa** tombol ubah/tambah, catatan “Perubahan data keluarga dilakukan oleh akun utama.” [Baca saja](screenshots/m07-android-a3-family-read-only.png) |
| 06:29 | `Keluar` (perangkat ini) | Lihat catatan dialog di bawah |
| 06:30:21 | Login `a2` | Layar terbatas “Menunggu persetujuan”, status permohonan Ditinjau, hanya `Periksa lagi` dan `Keluar`; tanpa tab/feed warga. [Pending](screenshots/m07-android-a2-pending-limited.png). Lalu `Keluar` |
| 06:30:48 | Login admin → Warga, cari `Sintetis M07` | Daftar rumah tersaring 4 rumah M07 (bukan daftar penuh). [Rumah M07](screenshots/m07-android-admin-houses-filtered.png) |
| 06:33:09 | Daftar keluarga → `Keluarga baru`: kepala `Sintetis M07 Android Pengurus`, hubungkan rumah `M07-E0C6B8-3` (dicari), jenis Kontrak, `Simpan keluarga` | Daftar keluarga memuat keluarga baru (1 anggota, M07-E0C6B8-3, Perlu verifikasi). API: tepat satu keluarga dengan kepala itu, versi 2, rumah M07-E0C6B8-3. [Keluarga M07 tersaring](screenshots/m07-android-admin-family-created-filtered.png) |
| 06:34 | Detail rumah `M07-E0C6B8-3` | Keluarga baru sebagai penghuni; riwayat hunian: penghuni sebelumnya (berakhir 13 Oktober 2026) dan keluarga sekarang (masih menghuni). [Riwayat hunian](screenshots/m07-android-admin-house-h3-history.png) |
| 06:34:30 | Detail rumah `M07-E0C6B8-2` | “Pemilik tercatat: Pemilik Sintetis M07 E0C6B8” terpisah dari kepala keluarga penghuni “Kepala Dua Sintetis M07 E0C6B8”. [Pemilik vs kepala](screenshots/m07-android-admin-house-h2-owner-vs-head.png) |

Mutasi baru (semua data M07): 1 anggota oleh `a1` (setelah satu 409), 1 verifikasi keluarga K1 lewat
API admin untuk memaksa konflik versi, 1 keluarga + hunian oleh admin di UI.

## Akhir sesi

- font_scale 1,0; ukuran 960x2133, density 480 tetap; aplikasi berjalan (PID 4056) dengan
  MainActivity di depan; 0 ANR. Emulator tidak di-restart, tidak ada uninstall atau clear cache.
- Sesi admin dibiarkan masuk di emulator untuk inspeksi root. Tab `Lainnya` admin tidak
  menampilkan `Keluar` pada area yang terlihat; tidak dicari lebih jauh agar tetap terbatas.
- Token login API helper (admin) ditutup dengan logout perangkat itu sendiri.

## Catatan untuk root

1. **Dialog “Keluar dari semua perangkat” sempat terbuka (06:29)** karena tap meleset dari helper
   saya, bukan bug aplikasi. Kolom kata sandi kosong; ditutup dengan `Batal`, tidak ada
   logout-all. Setelah itu `Keluar` perangkat ini dipilih dengan label persisnya.
2. **Draft keluarga baru admin hilang sekali** karena saya menekan Back setelah keyboard sudah
   tertutup (navigasi keluar form). Tidak ada keluarga yang tersimpan (daftar tetap 10);
   alur diulang dan berhasil sekali.
3. Pengamatan UI kecil (bukan pemblokir): setelah `Simpan keluarga`, kartu rumah
   `M07-E0C6B8-3` di daftar rumah tab Warga masih menampilkan “Belum ada keluarga aktif” sampai
   detail dibuka; detail rumah sudah benar. Kemungkinan daftar rumah tidak dimuat ulang setelah
   keluarga dibuat dari layar Keluarga.
4. Pengamatan visual kecil pada font 2,0: badge notifikasi di app bar menempel/terpotong di
   tepi kanan layar ([keluarga diperbarui](screenshots/m07-android-a1-family-updated-scale20.png)).
   Teks inti dan tombol tetap terbaca utuh.

## Keterbatasan

Tidak mengulang ke-77 kasus API di UI. Tidak menguji login `p1` yang diarsipkan, rilis APK
bertanda tangan, perangkat fisik, atau production. Helper ADB/API privat berada di
`C:/Users/USER/.rt05/m07-stage-check/android/` (di luar Git).
