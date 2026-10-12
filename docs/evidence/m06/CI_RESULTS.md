# M06 — bukti CI dan release

Source `8d0b3df8f73860b66277fed1494f196d47525d4a`,
[draft PR21](https://github.com/morrispes5/RT-05-TAKEDA/pull/21),12Oktober2026.
PR masih draft karena acceptance inbox dan integrasi nyata belum lengkap.
M07 tetap terkunci; CI sukses sendiri tidak menutup milestone.

| Workflow | Hasil aktual | Bukti |
| --- | --- | --- |
| API verification | SUCCESS;171tes/1461assertions PostgreSQL17 Linux;Pint/OpenAPI,Redis worker recovery,runtime DML/DDL-denied,direct smoke lulus | [38221562071](https://github.com/morrispes5/RT-05-TAKEDA/actions/runs/38221562071) |
| Mobile verification | SUCCESS;analyze/format/107tes;build APK debug dev/staging lulus | [38221562015](https://github.com/morrispes5/RT-05-TAKEDA/actions/runs/38221562015) |
| Infra images | SUCCESS;build image API/web dan guard isolasi lulus | [38221562059](https://github.com/morrispes5/RT-05-TAKEDA/actions/runs/38221562059) |
| Repository checks | SUCCESS | [38221562006](https://github.com/morrispes5/RT-05-TAKEDA/actions/runs/38221562006) |
| Vercel preview/comments | Checks PASS;publikasi album main dibuktikan terpisah | [PR21](https://github.com/morrispes5/RT-05-TAKEDA/pull/21),ALBUM_LIVE.json |

Checkpoint initial: review Claude selesai; backend Grok masih berjalan saat itu.
Claude menemukan F1 medium-laten: interceptor request logout lama bisa memasang
bearer sesi baru. Koreksi dipersiapkan untuk pemilik mobile sebelum acceptance;
107tes awal tidak menutup race tersebut. CLAUDE_MOBILE_REVIEW.md memuat repro.
Tidak ada perubahan lintas kepemilikan atau merge M06.
Script deploy immutable SHA sudah diambil di konsol staging; SHA256 identik
`ad80d976f8b04f3a9d6969d9e8f70995c55b645170202cdcea7a340bbd75a096`.
Belum dieksekusi. Staging yang diinspeksi masih SHA `6a8d4f8` sehat.

APK staging diunduh dari run tersebut;SHA256 cocok:
`c3ee07008ff5b47a2dd4c2400457d7773f7f3c353dbb48b2515dd6eedd60b49d`.
173423964byte,sertifikat Android Debug,belum penerimaan emulator kode final
karena F1 perlu diperbaiki. Tidak ada signing key release atau APK masuk Git.

Gate terbuka: koreksi/review mobile,APK final/emulator,rollout staging/browser/health
dan SMTP sampai inbox. Env staging hanya menunjukkan mailer bukan smtp dan
from;key host/port/username/password tidak ditemukan. Nilai tidak dicetak.
Insiden credential tetap open;production/data warga nyata mengikuti M16.

## Koreksi sesudah review

Review silang kedua agent sudah selesai. Temuan dan perbaikan tercatat dalam
CLAUDE_MOBILE_REVIEW.md, GROK_BACKEND_REVIEW.md, GROK_REVIEW_FIXES.md dan
CLAUDE_REVIEW_FIXES.md. Backend final lokal184tes/1679assertions dan Pint pass;
Flutter114tes/analyze clean. Reservasi kuota token memakai lock bersama,
bukan increment vendor yang dapat menimpa penghitung fresh/zero.
Angka lokal ini belum bukti CI pada commit koreksi. Kedua APK initial8d
tetap artefak historis; APK penerimaan harus dibangun dari koreksi final.

## Source koreksi 15885cb

Source `15885cbf2d08b3e0f2b66b32899e9e6857ff63d1`:

| Workflow | Hasil aktual | Bukti |
| --- | --- | --- |
| API verification | SUCCESS184tes/1679assertions pada PostgreSQL17 Linux;Pint/OpenAPI,Redis recovery,role runtime DDLdenied/direct smoke lulus | [38228867337](https://github.com/morrispes5/RT-05-TAKEDA/actions/runs/38228867337) |
| Mobile verification | SUCCESS114tes/analyze/format;dua APK debug dev/staging lulus | [38228867355](https://github.com/morrispes5/RT-05-TAKEDA/actions/runs/38228867355) |
| Infra images | SUCCESS | [38228867353](https://github.com/morrispes5/RT-05-TAKEDA/actions/runs/38228867353) |
| Repository checks | SUCCESS | [38228867368](https://github.com/morrispes5/RT-05-TAKEDA/actions/runs/38228867368) |

APK staging diunduh, SHA256 cocok dengan checksum artefak:
`ff5f766a9da2cc3c31e814edcbcfc1c11830c61f437fbcf6fa1a6eee14d1a9af`;
173425932byte;AndroidDebugcertSHA256
`4330026fb322f123f0d9ab0ee2dfe723232009cb0a8fca2d043898c9b2dd9c92`.
APK dipasang setelah package guest emulator diganti karena sertifikat debug
CI berbeda dari M05. Tidak ada signing key release atau APK masuk Git.

Review delta Claude mobile dan Grok backend pada source15885cb selesai tanpa
blocker kode pada lingkup reviewed; fingerprint/perbedaan lingkungan tersedia
di CLAUDE_MOBILE_DELTA_REVIEW.md dan GROK_BACKEND_DELTA_REVIEW.md.
Pemeriksaan emulator berikutnya menemukan pesan aturan password terpotong
pada skala2,0. Koreksi UI terbatas diserahkan ke Grok; APK15885cb belum menjadi
bukti tampilan final sesudah koreksi itu.

Sesi Hostinger sebelumnya kedaluwarsa. Chrome yang diperiksa menampilkan
login Hostinger; pemilik diminta membuka kembali Konsol VPS. Script deploy
belum dieksekusi. Health existing200/ready terverifikasi09:21UTC, bukan
bukti rollout M06. SMTP pengirim belum pernah disiapkan menurut pemilik;
production/data warga nyata dan implementasi M07 tetap tertahan.
