# Penerimaan M07 — pendataan rumah, keluarga dan akun

13 Oktober2026. **PASSED** pada source/staging `979634f`, sesudah M06/PR21.
Seluruh data uji sintetis; penerimaan untuk staging dan emulator Android.

| Syarat | Bukti diterima |
| --- | --- |
| Histori membership/link additive, tanggal lama NULL, pointer kompatibel | 220tes backend/2125assertion; migrasi staging DONE, backfill0/0 idempotent ([deploy](DEPLOY_RESULT.md)) |
| Satu keluarga aktif per rumah dan primary per keluarga | Constraint PostgreSQL; dua permintaan primary nyata menghasilkan200+409, tepat satu pemenang |
| Utama edit sendiri, tambahan read-only, pending terbatas; IDOR ditolak | Live API77/327 dan Android ketiga peran; keluarga lain404, mutasi tambahan403 |
| Hak lama setelah transfer ditolak | Primary lama403, primary baru200; pemeriksaan ulang di dalam lock keluarga |
| Rumah/keluarga/anggota CRUD/verifikasi/arsip dan pencabutan akun | Live API, histori tetap dan sesi401; Android pengurus membuat keluarga/hunian |
| Pemilik terpisah dari kepala/penghuni kontrak | Live API dan layar detail rumah Android |
| Perpindahan anggota/hunian atomik dan histori tetap | Live API menolak rumah penuh tanpa state parsial; membership/link/occupancy history diperiksa |
| Satu unit tagihan per rumah, tunggakan tidak ikut pindah, rumah kosong tanpa auto-waiver | Live API memeriksa tagihan rumah lama dan rumah multiakun |
| Versi hilang422/basi409, isian tetap dan kirim ulang aman | Live API; Android konflik nyata, isian utuh, satu anggota sesudah resubmit |
| Teks/error320px/skala2 dan akses retry | Flutter152tes/rendered text; pesan409 lengkap di Android320dp/font2 |
| Kontrak/API/ERD, CI, APK/runtime sesuai source | Seluruh9CI SUCCESS; SHA/checksum APK cocok, readiness/heartbeat4/4, DML rollback0/DDL denied |
| Dokumentasi manusia di Capstone existing | Native panduan31gambar/4tabel/PDF37halaman, laporan5halaman; readback/render ([Drive](DRIVE_PUBLICATION.md)) |

[API live](LIVE_RESULTS.md), [Android](ANDROID_RESULTS.md) dan [laporan akhir](REPORT.md).
Kartu rumah sempat memerlukan reload setelah keluarga dibuat; badge notifikasi
terpotong pada skala2. Tidak mengklaim seluruh77kasus diulang melalui UI.
Production, perangkat fisik, signing/UAT dan insiden credential historis tetap
di luar penerimaan ini. M08 tidak dibuka otomatis.
