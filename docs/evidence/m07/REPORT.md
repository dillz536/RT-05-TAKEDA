# M07 — penerimaan coordinator

13 Oktober2026. **PASSED** pada source/staging
`979634fb7ae847f6c743f0ae7e11ec575b1fe722`, PR #22.
Penerimaan ini untuk staging sintetis dan emulator Android, bukan UAT mitra,
perangkat fisik, signing rilis atau production M15/M16.

| Bukti | Hasil |
| --- | --- |
| Backend PostgreSQL + CI | 220 tes / 2125 assertion, termasuk proses independen, backfill/rollback dan penolakan hak yang sudah berubah |
| Flutter + CI | 152 tes, formatter/analyzer; teks dirender pada320px/skala2; dua APK debug SUCCESS |
| Seluruh CI source | 9 SUCCESS, source979634f; API38353725706, infra38353725776, mobile38353725721, hygiene/Vercel |
| [Deploy](DEPLOY_RESULT.md) | SHA image sesuai; migration additive DONE, backfill0/0 idempotent, readiness/4heartbeat, DML rollback0/DDL denied, env/volume/unrelated tetap |
| [API live](LIVE_RESULTS.md) | **77/77 kasus,327/327 assertion**, 06:14:12–06:15:10UTC, tanpa429/resume; primary200+409, izin/IDOR, histori, satu tagihan per rumah |
| [Android](ANDROID_RESULTS.md) | APK checksum cocok; UI nyata tambah anggota,409 draf tetap/resubmit sekali, tambahan read-only, pending terbatas, pengurus membuat keluarga/hunian; lebar320dp/skala2 |
| [Drive](DRIVE_PUBLICATION.md) | Panduan/laporan native existing; userflow, usecase, sequence, ERD dan screenshot nyata; readback/PDF diverifikasi coordinator |

APK staging SHA256
`6210638aadaa6bcb791ad414d9fa4849e02135f684c250bb1c731eee8d322297`.
Tidak ada data warga asli, secret, NIK wajib, payment gateway atau hard-delete
keuangan. Source rest-api/mobile/website tidak berubah sesudah SHA yang diuji.

Catatan UI kecil tercatat: kartu rumah sempat basi setelah keluarga dibuat
sampai detail/navigasi memuat ulang; badge notifikasi terpotong pada skala2.
Teks/error inti dan fungsi pendataan tetap bekerja. Penerimaan tidak mengklaim
semua kasus API diulang di UI. Insiden rotasi credential historis tetap open
untuk M16; referensi Stitch dengan artefak unduhan lama dibatasi sesuai
[UI_REFERENCE.md](UI_REFERENCE.md).

M08 bukan scope lanjut otomatis dari tugas pemilik ini. Bukti M06 tetap
[INBOX_ACCEPTANCE](../m06/INBOX_ACCEPTANCE.md); M06/PR21 sudah merged.
