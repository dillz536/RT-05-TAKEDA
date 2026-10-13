# Matriks kebutuhan dan penerimaan M06–M07

12 Oktober 2026. Acuan utama: PRD repo identik SHA-256 dengan `PRD (2).md`
di folder induk (`63c5888fa6005d3fbe893ddef66f7172a16800621dc21045bd3652a97d17fbac`).
Keputusan pemilik terbaru mengikat: akun utama langsung aktif, keluarga belum
diverifikasi; tambahan menunggu pengurus; tanpa OAuth dan tanpa auto-merge rumah.
`passed` pada matriks hanya berlaku terhadap bukti yang disebut, bukan seluruh
milestone. Status akan diperbarui setelah tes dan integrasi selesai.

Checkpoint13Oktober: staging26a0b7e, API52alur/17mekanika reset, bootstrap dan
Android registrasi/password/sesi passed dalam lingkup sintetis. Email asli
masuk INBOX lalu dipakai pada browser/Android; verifier A dan B4/4 PASS.
M06 passed oleh coordinator; M07 planned dan siap dimulai. Bukti terkini pada
[INBOX_ACCEPTANCE.md](INBOX_ACCEPTANCE.md) serta laporan Claude/Grok.
Panduan native Drive ditindaklanjuti pada file/folder existing yang sama.

| Kebutuhan | Sumber | Implementasi | Tes | Bukti | Status |
| --- | --- | --- | --- | --- | --- |
| Registrasi utama atomik, termasuk bearer perangkat | FR03, BR03/05/06, keputusan pemilik | Registration + AuthController; form Flutter | M06RegistrationTest rollback;M06ConcurrencyTest proses independen | CI184 + staging52alur dan Android registrasi utama | passed |
| Rumah existing tidak digabung; keluarga belum_verifikasi | PRD §7, BUSINESS_RULES identitas | normalized Address + constraint; pesan form | M06RegistrationTest rumah kosong/arsip;constraint/normalisasi;payload akun | CI184 + API staging dan Android akun aktif/keluarga belum diverifikasi | passed |
| Token string enam digit, lihat/salin admin, rotasi | FR04, BR04 | RegistrationToken, scheduler; UI admin existing | M06Registration/Concurrency/FollowUpTest invalid/expiry/leading-zero,rotasiFIFO/tidak reissue | CI184 + probe rotasi/token staging; bukti Claude | passed |
| Akun tambahan pending dan alasan penolakan | FR05, BR05 | Registration additional/status;akun ditolak tetap terbatas | M06FollowUpTest rejected relogin/reason,nonaktif/arsip denied;Flutter pending widget | CI184/Flutter117 + API approve/reject dan Android pending | passed |
| Login/logout/password/logout-all, token aman dan expiry | SECURITY, API, FR22 | Sanctum + secure storage + SessionController | M06SessionTest;session/auth_access/logout_pin tests offline/epoch/storage | CI184/Flutter117 + Android password/revoke/logout-all dan inbox verifier A/B | passed |
| CMS cookie+CSRF dan pencabutan sesi browser | API/SECURITY/ARCHITECTURE | web-login, csrf-cookie, session generation | M06BrowserHttpTest serverHTTP nyata;M06SessionTest CSRF/origin/idle/revoke | CI184 + cookie/CSRF/session staging52alur dan probe extended | passed |
| Reset antrean dengan tautan HTTPS/kode Android | ROADMAP M06, API, keputusan SMTP pemilik | encrypted after_commit notification + /reset-password + form Flutter | M06PasswordReset/FollowUpTest expiry/reuse/rollback/log-matrix;browser lokal320–1280/Axe0 | INBOX_ACCEPTANCE: email nyata/browser/Android, verifier4/4 masing-masing | passed |
| Bootstrap/grant/revoke operator diaudit | BR07, BUSINESS_RULES | rt05:pengurus;tanpa password bawaan atau output secret | M06OperatorTest grant/revoke/lastadmin/hidden prompt/audit | CI184 operator + bootstrap sintetis staging exit0/login200 | passed |
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
