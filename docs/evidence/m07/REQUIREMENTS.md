# Penerimaan M07 — pendataan rumah, keluarga dan akun

Basis `bc57b4f` (PR21/M06 passed); branch `codex/m07-resident-data`.
Status **in_progress**. Hasil lokal tidak menggantikan demonstrasi staging/Android.
Semua data uji sintetis; tidak ada seed/factory/tes di production atau Neon production.

| Syarat | Bukti lokal saat checkpoint | Bukti live yang wajib |
| --- | --- | --- |
| Histori keanggotaan dan tautan akun additive, tanggal lama tidak dikarang, pointer kompatibel | Migration/backfill/rollback test Claude | Job migrator staging, backfill NULL dan idempotent, data lama tetap |
| Satu keluarga aktif per rumah dan satu primary; benturan penulis bersamaan ditolak | PostgreSQL constraint dan proses independen | Dua permintaan primary bersamaan, satu menang dan lainnya409 |
| Utama mengubah sendiri; tambahan disetujui membaca sendiri; pending/ditolak terbatas | Access/IDOR/versi tests | HTTP dan Android utama/tambahan/pending; keluarga lain404, mutasi tambahan403 |
| Hak yang berubah bersamaan tidak bisa dipakai lewat relasi lama | Review Claude aktif | Transfer primary, hak edit lama ditolak dan hak baru berlaku |
| Rumah/keluarga/anggota CRUD/verifikasi/arsip; akun nonaktif/arsip mencabut akses | Admin tests; layar Grok dilengkapi | Demonstrasi layar pengurus dan sesi lama401; arsip tidak menghapus histori |
| Pemilik terpisah dari kepala/penghuni kontrak; alamat/code stabil | Admin owner/address tests | Ubah pemilik/kontrak, tidak mengubah kepala; warga tidak menerima pemilik privat |
| Mulai/akhir/pindah hunian dan anggota atomik, histori tetap | Admin/concurrency tests | Pindah ke rumah/keluarga baru; rumah penuh ditolak tanpa data setengah jadi |
| Banyak akun tetap satu unit tagihan; pindah tidak memindahkan tunggakan atau auto-waiver | Dues/occupancy tests | Rumah uji multiakun satu tagihan per periode; rumah lama/tagihan lama tetap |
| Versi hilang422/basi409, isian mobile tidak hilang, bisa ditinjau ulang | Backend tests; mobile follow-up aktif | Dua perubahan versi; UI409 tetap menampilkan isian dan pesan terbaca |
| Teks/error mobile terbaca pada320px dan skala2, offline/retry masuk akal | Tes rendering diminta Grok | Periksa layar nyata/error dengan Android dan capture aman |
| Kontrak/API/ERD, CI dan artefak Android cocok dengan source yang diterima | Kontrak source siap; CI belum | SHA review, CI hijau, APK checksum, readiness/heartbeat/DML-DDL isolation |
| Dokumentasi manusia di folder Capstone existing, userflow/usecase/sequence/ERD nyata | Kimi draft M07 diminta | Native Doc readback dan PDF render, status/gambar sesuai hasil final |

Root mencatat source SHA, perintah/hasil, fixture nonsecret, tangkapan layar aman,
serta keterbatasan pada LIVE_RESULTS/REPORT sebelum menerima gate. M08–M16
tidak dibuka sebagai bagian penerimaan ini; insiden credential historis tetap open.
