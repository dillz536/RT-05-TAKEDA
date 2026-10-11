# mobile/ — aplikasi Flutter RT05 TAKEDA (warga & pengurus)

Satu aplikasi Android; navigasi mengikuti peran dari API. Semua data dan aturan bisnis dari `rest-api/` lewat `/api/v1`. Aplikasi tidak terhubung langsung ke Neon/Redis dan tidak menghitung saldo/tunggakan sendiri.

Flutter **3.47.7** (Dart 3.13.5). Paket: flutter_riverpod 3, go_router 18, dio 5, flutter_secure_storage 11 (token di keystore OS), flutter_svg 2, flutter_localizations (Material bahasa Indonesia), image_picker, url_launcher, intl.

## Fondasi (M05)

| Bagian | Isi |
| --- | --- |
| Konfigurasi | `lib/app/config.dart`: `--dart-define=FLAVOR` wajib persis `dev`/`staging`/`prod`; `API_BASE_URL` origin root tanpa kredensial/query/fragment/path. staging/prod wajib HTTPS; dev boleh HTTP hanya ke `10.0.2.2`/`127.0.0.1`. Tidak valid → layar "Konfigurasi aplikasi tidak valid" tanpa request jaringan. Badge DEV/STAGING; production tanpa badge. Versi aplikasi hanya dari `pubspec.yaml` |
| Bootstrap | `GET /health/live` nyata (tanpa token, connect/receive 8 detik + batas total, wajib JSON `status: ok`) paralel dengan pemulihan sesi. Gagal → splash + "Coba lagi". Nonproduction menampilkan host + `request_id` health untuk dicocokkan dengan log API |
| Sesi | Token di secure storage. Hanya 401 (atau logout) menghapus token; offline/timeout/5xx/403 mempertahankan token dan menampilkan status tidak tersedia tanpa membuka data akun. Hasil async lama tidak menimpa logout/login baru |
| Router | go_router: `/splash`, `/login`, `/register` (`?jenis=tambahan`), `/forgot`, `/pending`, `/home`, `/notifications`, dengan redirect sesi (`sessionRedirect`). Halaman detail fitur tetap `Navigator.push` |
| Brand | Logo `assets/brand/` = salinan byte-identik `website/public/images/logo/` (dirender flutter_svg). Font Public Sans + Bricolage Grotesque TTF terbundel di `assets/fonts/` (OFL), tanpa unduhan runtime. Token warna = `:root` CSS website |
| Ikon launcher | Adaptive icon (API 26+) + PNG legacy dihasilkan `python tool/launcher_icon.py` dari logo resmi; jangan edit hasilnya manual |

Sumber font: Public Sans v2.001 (`uswds/public-sans` commit c7923167), Bricolage Grotesque (`ateliertriay/bricolage` commit 84745e5b, sumber yang sama dengan Google Fonts).

## Layar

| Peran | Tab | Isi |
| --- | --- | --- |
| Semua | Akses | Splash/cek sesi, login, daftar akun utama (rumah + keluarga), daftar akun tambahan, menunggu persetujuan, lupa/reset kata sandi |
| Warga | Beranda | Iuran bulan ini + tunggakan, aksi cepat, agenda terdekat, pengumuman |
| Warga | Layanan | Pengaduan & aspirasi (tab terpisah), filter status, laporan saya, form + foto kamera/galeri + sembunyikan identitas, detail + riwayat |
| Warga | Agenda | Daftar agenda + tambah ke Google Calendar, pengumuman |
| Warga | Iuran | Tagihan rumah ≥12 bulan, transparansi per kode rumah (tanpa nama), saldo & mutasi kas |
| Warga | Akun | Profil, data keluarga (edit akun utama), ganti kata sandi, keluar |
| Pengurus | Ringkasan | Saldo kas, status iuran bulan ini, permohonan/laporan baru, catat iuran, token |
| Pengurus | Warga | Rumah & keluarga (verifikasi, iuran per rumah), permohonan akun tambahan (setujui/tolak) |
| Pengurus | Layanan | Tindak lanjut status pengaduan/aspirasi (versi, buka ulang beralasan) |
| Pengurus | Keuangan | Catat pembayaran offline multi-bulan (pratinjau total, Idempotency-Key), pembalikan, kas manual, transparansi |
| Pengurus | Lainnya | Agenda, pengumuman, token registrasi, akun |
| Semua | Notifikasi | Inbox dari app bar, tandai dibaca, buka laporan terkait (dimuat ulang dari API) |

Push FCM belum aktif (butuh project Firebase); inbox notifikasi berjalan tanpa push.

## Menjalankan di emulator (hemat SSD)

APK di-build di GitHub Actions (`.github/workflows/mobile.yml`) sehingga cache Gradle tidak memenuhi disk PC.

1. Jalankan API lokal: `cd rest-api && php artisan serve --port=8077` (memakai Neon dev dari `.env`).
2. Unduh artifact `rt05-takeda-debug-<flavor>` dari run workflow terbaru (mis. `gh run download -n rt05-takeda-debug-staging`; nama mengikuti `.github/workflows/mobile.yml`).
3. Nyalakan emulator: `%LOCALAPPDATA%\Android\Sdk\emulator\emulator -avd Pixel_8a`.
4. Pasang: `%LOCALAPPDATA%\Android\Sdk\platform-tools\adb install -r rt05-takeda-<flavor>-debug.apk`.

APK debug default (`FLAVOR=dev`) memanggil `http://10.0.2.2:8077` (alamat PC dari emulator). Untuk staging: workflow manual dengan `flavor=staging` (origin HTTPS). Build release hanya mengizinkan HTTPS.

## Pengembangan

```powershell
$env:Path = "C:\Users\USER\dev\flutter\bin;$env:Path"
flutter pub get
dart format lib test        # lebar 160 dari analysis_options.yaml
flutter analyze
flutter test
```

Tes memakai storage token memori dan transport HTTP palsu dari `test/support/` (tidak pernah ke Neon atau server nyata). Tidak ada adapter palsu di kode aplikasi.

Signing key rilis, `key.properties`, dan `google-services.json` tidak pernah di-commit.
