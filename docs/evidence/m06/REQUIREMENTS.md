# Matriks kebutuhan dan penerimaan M06–M07

12 Oktober 2026. Acuan utama: PRD repo identik SHA-256 dengan `PRD (2).md`
di folder induk (`63c5888fa6005d3fbe893ddef66f7172a16800621dc21045bd3652a97d17fbac`).
Keputusan pemilik terbaru mengikat: akun utama langsung aktif, keluarga belum
diverifikasi; tambahan menunggu pengurus; tanpa OAuth dan tanpa auto-merge rumah.
`passed` pada matriks hanya berlaku terhadap bukti yang disebut, bukan seluruh
milestone. Status akan diperbarui setelah tes dan integrasi selesai.

| Kebutuhan | Sumber | Implementasi | Tes | Bukti | Status |
| --- | --- | --- | --- | --- | --- |
| Registrasi utama atomik, termasuk bearer perangkat | FR03, BR03/05/06, keputusan pemilik | Registration + AuthController; form Flutter | rollback paksa, email/alamat duplicate, proses concurrent | CLAUDE_RESULT/CI dan emulator (menunggu) | in_progress |
| Rumah existing tidak digabung; keluarga belum_verifikasi | PRD §7, BUSINESS_RULES identitas | normalized Address + constraint; pesan form | normalisasi/rumah kosong existing; peran tak dapat disisipkan | CI (menunggu) | in_progress |
| Token string enam digit, lihat/salin admin, rotasi | FR04, BR04 | RegistrationToken, scheduler; UI admin existing | invalid/expiry/leading-zero, manual/mingguan, concurrent/revoked | CI/Android (menunggu) | in_progress |
| Akun tambahan pending dan alasan penolakan | FR05, BR05 | existing Registration additional/status; layar pending | layanan ditolak; reason terlihat tanpa akses warga | CI/Android (menunggu) | in_progress |
| Login/logout/password/logout-all, token aman dan expiry | SECURITY, API, FR22 | Sanctum + secure storage + SessionController | current/all revoke, expired, offline/race storage | Flutter/CI/emulator (menunggu) | in_progress |
| CMS cookie+CSRF dan pencabutan sesi browser | API/SECURITY/ARCHITECTURE | web-login, csrf-cookie, session generation | missing/correct CSRF, foreign origin, role/status/password revoke | CI/browser (menunggu) | in_progress |
| Reset antrean dengan tautan HTTPS/kode Android | ROADMAP M06, API, keputusan SMTP pemilik | queued notification + /reset-password + form Flutter | generic, rollback, expiry/reuse, session revoke, inbox | mock/CI bukan delivery; inbox belum tersedia | in_progress |
| Bootstrap/grant/revoke operator diaudit | BR07, BUSINESS_RULES | rt05:pengurus; tanpa password bawaan | role/status/session revocation + audit | CI (menunggu) | in_progress |
| Kelola rumah/pemilik/keluarga/warga/verifikasi/arsip | FR06/22, M07 | gap endpoint existing diinventaris; implementasi baru ditahan | IDOR, status/repeated decisions | M07 belum dibuka | planned |
| Own family primary edit; additional read-only | BUSINESS_RULES keluarga | endpoint existing; tutup celah pada M07 | keluarga lain404, tambahan403, version409 | audit M07 sesudah gate M06 | planned |
| Membership/account-link histori dan backfill konservatif | ERD/DATABASE, FR06 | migration additive M07, tanggal historis tidak ditebak | preserve existing data, concurrency two primary | menunggu M06 | planned |
| Mulai/akhir/pindah hunian atomik; tagihan rumah asal | BR05/10, DATABASE | action M07, endpoint existing dipertahankan | rollback/race, tagihan tidak pindah, arsip histori | menunggu M06 | planned |
| Stitch sesuai brand dan screenshot aplikasi nyata | MOBILE, baseline frontend | proyek Stitch RT05; terjemahan Flutter M06 | loading/error/pending/scale1,3/2,0 | Stitch terpisah dari bukti emulator | in_progress |
| Album tambahan dari foto Drive yang diperiksa | instruksi pemilik, FR01 | album.json + enam foto dalam18WebP, cover/alt/caption | build21rute;105responsif/48Axe+editor;check-live21HTML/32aset/mobilePASS | ALBUM_REVIEW, ALBUM_LIVE.json, PR20 merged9dd047f | passed |
| Laporan, diagram, contact sheet pada satu folder Drive | rencana pemilik | folder Capstone/Tugas dan Project terverifikasi saat discovery | readback isi/parents | https://drive.google.com/drive/folders/1_WweESw5u0iHaDlw6LUfFAuUgOzAstpj | in_progress |
| Insiden credential/production/data nyata | SECURITY, gate M16 | isolasi M04 existing; tidak merotasi dalam M06 | bukti revokasi/rotasi belum tersedia | PROGRESS checkpoint M04 | blocked |

## Rekonsiliasi capstone

[Tugas03](https://docs.google.com/document/d/1024MDDGIh6xQFAdJwLH4PXGsWnsEMgC6mU4bCqOVQBo/edit)
dibaca ulang melalui connector pada 12 Oktober. Diagram permohonan semua akun
menunggu approval berbeda dari keputusan terbaru dan PRD utama. Alur laporan
perkembangan membedakan aktivasi akun utama dari verifikasi keluarga. Dokumen
akademik asli tidak diedit. Selisih lainnya tetap di CAPSTONE_ALIGNMENT.md:
rupiah bigint, schema akun nullable saat bootstrap/pending, tabel operasi,
histori link M07 tambahan, volume media dibanding rancangan cloud storage,
push M10, dan CMS website M13–M14.

## Gate pembukaan M07

Semua penerimaan M06 harus terbukti, termasuk SMTP sampai inbox,
PostgreSQL nyata dan concurrency, cookie/CSRF, Flutter/APK/Android. Jika provider
belum tersedia, tulis blocker spesifik dan selesaikan pekerjaan M06 lain serta
album/laporan; jangan membuka implementasi M07 atas dasar build saja.
