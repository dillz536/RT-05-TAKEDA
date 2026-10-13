# Progress — RT05 TAKEDA

Updated: 13 Oktober 2026.

## Penutupan M06 — 13 Oktober 2026

- Coordinator menerima **M06 passed** pada runtime26a0b7e, byte aplikasi sama
  dengan HEAD2dfe4ba yang seluruh CI-nya SUCCESS: API184/1679, Flutter117/
  duaAPK, infra. Bukti52alur akun/17mekanika reset dan Android sebelumnya tetap.
- Key Resend khusus RT05 dibatasi Sending/domain terverifikasi. Claude mengubah
  hanya MAIL_* staging dan recreate API/worker-default pada tag yang sama,
  tanpa build/migration. DB/APP_KEY/Redis, ID service lain dan volume tetap.
  Recycle alami worker media/exports per jam bukan restart tambahan oleh task.
- Inbox lama tidak dapat diakses dari sesi tersedia; coordinator memakai Gmail
  pemilik yang terhubung sebagai default uji yang diumumkan setelah kesempatan
  memilih. Fixture/akun lama dipertahankan privat. Kontak resmi RT tidak berubah.
- **INBOX A02:53:25UTC**, tautan email asli/Chrome reset sukses; verifier A4/4:
  bearer lama401, sandi lama422, sandi baru200, kode ulang422. Fragment hilang.
- Android meminta kode03:09:56.910UTC; **INBOX B03:10:10UTC**, reset03:20:24.104,
  login Beranda03:22:55.348. Verifier B **4/4 PASS03:29:00.769UTC**. B awal dari
  helper digantikan; tidak dihitung sebagai reset akhir. Empat job DONE, nol
  FAIL/exception/pola kode atau fragment pada log yang diperiksa.
- Claude task_e6babca81a7e/ctx_0ec47366d23f dan Grok task_86000e8c6e85/
  ctx_13987cde954b completed; release mempertahankan terminal existing. Wait
  background Claude sempat dihentikan karena RAM PC kritis; root melanjutkan
  verify B langsung. Ini bukan kepastian penyebab penutupan CLI sesi sebelumnya.
- [INBOX_ACCEPTANCE.md](evidence/m06/INBOX_ACCEPTANCE.md) dan laporan/screenshots
  aman menjadi bukti penutupan. M07 boleh dimulai sesudah checkpoint tersimpan;
  belum terimplementasi. Insiden credential historis tetap open untuk M16.

## Pemulihan sesi12 Oktober malam — M06 lalu M07

- Pemilik meminta Claude Code backend/database, Grok mobile/build/infra,
  Codex koordinasi/Chrome, Kimi dokumentasi manusia di Drive. Otorisasi akun
  existing GitHub/Vercel/Drive/VPS Hostinger tercatat sebagai P19. Run Orca
  `run_d783036e0906`; tidak mengulang provisioning fondasi atau mereset data.
- Preflight konsol root srv2030339: Linux6.8.0-139, disk /opt tersedia79G,
  MemAvailable5454MB, env staging mode600, service existing sehat. Script
  immutable SHA256 `ad80d976f8b04f3a9d6969d9e8f70995c55b645170202cdcea7a340bbd75a096`
  sesuai source. Job2130943 menghasilkan footer **Selesai staging
  SHA26a0b7e22cfe96941266cb9c194072512eca707e** sekitar20.21WIB.
  Log privat `/root/rt05-m06-26a0b7e-deploy.log`; tidak memasukkan env ke Git.
  Smoke runtime kemudian **PASS** melalui SSH: marker
  `01a1305b-6267-72eb-970d-0023e9912c44` published→completed dengan attempts1
  sesudah restart Redis/worker-default; sentinel AOF bertahan, empat heartbeat
  sehat, DML pooled/direct rollback0, DDL runtime ditolak, volume dan container
  lain tetap. Hash script smoke
  `d74df7482c514c61812bd8153ba05ed5dbccce266d888b1f722f1997134aa93f`
  sesuai source. Hasil akun Claude/Grok dicatat pada checkpoint akhir di bawah.
- Key SSH publik existing ditambahkan idempotent lewat konsol root ke
  authorized_keys mode600; entry existing dipertahankan. Probe SSH strict
  host-key/BatchMode keluar0, hostname srv2030339 dan UID0. Tidak memindahkan
  password root atau menulis DB/SMTP secret store. Bootstrap sintetis dan
  penerimaan staging dialihkan ke Claude melalui akses SSH tersebut.
- Kimi menyusun panduan manusia16bagian,4tabel,6diagram dan6screenshot nyata.
  Folder Drive existing diverifikasi memiliki parent Tugas dan Project dalam
  `[Kamis] PG194 - Capstone Project`. Panduan native:
  https://docs.google.com/document/d/18yoO1-BY7-ylFyujpkDrpp_NkPQOwq-9-mTRPK_-KlM/edit
  Readback16heading/4tabel/12inlineImages; ekspor PDF16halaman. Pemeriksaan
  halaman4/6/8/14 menunjukkan diagram/layar terbaca; seluruh gambar di dalam
  batas halaman. Laporan existing ditambah checkpoint dan tautan panduan,
  tanpa mengubah dokumen akademik asli. Artefak sumber pada
  `docs/evidence/m06/drive/RT05-TAKEDA-Dokumentasi-Akses-M06.docx`.
- Stitch menghasilkan referensi M07 lalu koreksi aturan pada layar
  `e267c2b12d8b4021861da1cda87716e8` (MOBILE780×3256). Referensi belum menjadi
  bukti aplikasi M07; asumsi KK/SQLite dari keluaran awal tidak diterapkan.
- Claude rerun PostgreSQL lokal184tes/1679assertion dan Pint passed. Root
  `node infra/scripts/check-repo.mjs`653berkas dan diff-check lulus sebelum
  tambahan artefak berikutnya; pemeriksaan akhir dicatat sesudah semua worker.
  SMTP pengirim belum tersedia; pilihan pengirim ditanyakan sambil pekerjaan
  lain dilanjutkan. M06 belum passed; implementasi M07 tetap menunggu gate.
- Penerimaan backend live Claude:52/52alur akun,17/17mekanika reset dengan
  token buatan server (bukan inbox). Probe tambahan29/30 awal terkena pacing
  limiter; rerun terisolasi memenuhi respons yang diharapkan. OpenAPI kini
  menjelaskan kuota bersama mobile-login/web-login5permintaan/menit/IP+email,
  sesuai source dan uji live. Tidak ada perubahan implementasi backend.
- Android Grok membuktikan registrasi utama aktif/keluarga belum diverifikasi,
  tambahan pending, logout/login dan sesi offline→retry PID sama. Follow-up
  terbatas membuktikan ganti sandi/logout-all. Drill runtime Grok yang terpisah juga
  PASS dengan event `01a1305e-6dbe-71f3-bec4-ff4af89c01c9`; tidak menggabungkan
  event itu dengan event root atau menganggap dua drill sebagai satu event.
- Pengirim existing Resend di project lain diperiksa read-only untuk metadata,
  credential tetap di memori server dan tidak disalin/dipakai mengirim RT05:
  GET `/domains` HTTP401. Tidak membuat key atau mengubah env/service proyek
  lain. Pilihan akun Google Chrome tidak cocok dengan inbox uji privat.
  Dibutuhkan login akun pengirim yang tepat atau credential SMTP RT05 valid;
  helper inbox privat siap, tetapi belum dijalankan. Ini blocker akses/input
  nyata, terpisah dari insiden rotasi DB/APP_KEY historis yang tetap tidak diubah.
- Kimi menyelesaikan panduan utama dan koreksi atribusi sebelum quota403.
  Suplemen5diagram UML/sequence/ERD dikerjakan Claude, bukan diklaim karya Kimi.
  Review root meminta label kardinalitas M07 di luar kotak dan akses keluarga
  tambahan hanya setelah approval sebelum suplemen dipublikasikan.
- Suplemen tersebut sudah dipublikasikan ke native Doc yang sama, ditambah
  dua screenshot Android terautentikasi. Readback19inlineImages; ekspor23halaman
  sebelum direktori tautan PNG ukuran penuh ditambahkan. Root memeriksa halaman
  17/19/20/21/22: gambar/caption dan penjelasan berada dalam halaman. Lima PNG
  ukuran penuh terverifikasi MIME/parents pada folder existing; narasi plain
  Google Docs dibersihkan dari penanda Markdown/karakter rusak. Tidak menambah
  folder atau mengubah sharing/dokumen akademik asli.
- Checkpoint akhir: native panduan **24halaman/19gambar inline/4tabel** sesudah
  direktori tautan PNG ukuran penuh. Ekspor PDF terakhir menunjukkan nol gambar
  di luar halaman; halaman diagram, screenshot dan direktori diperiksa visual.
  Google Doc adalah versi terbaru; DOCX Kimi tetap sumber checkpoint sebelumnya.
- Android terakhir membuktikan sandi lama ditolak, sandi baru berhasil, bearer
  dicabut menghasilkan Sesi berakhir tanpa data akun, dan logout-all kembali
  ke form kosong. Sandi akun perangkat sintetis dipulihkan dan dibuktikan login.
  Login fixture bersama ditolak sekali dan tidak diulang; pemeriksaan memakai
  akun perangkat yang sudah terdaftar. Font1,0/jaringan pulih, emulator tetap.
- Prompt bootstrap milik sesi ini yang tertinggal dibersihkan: cmdline lengkap
  PID2138345 dicocokkan dengan perintah bootstrap sintetis sendiri, lalu SIGTERM
  hanya proses tersebut. Admin valid sudah dibuat lewat stdin SSH oleh Claude;
  tidak menghentikan service atau proses milik pekerjaan lain.
- Semua worker penerimaan/dokumentasi selesai. Tugas suplemen Kimi gagal quota
  provider403 dan dialihkan ke Claude tanpa pembelian/bypass. Gate M06 sekarang
  **blocked** pada input pengirim valid/inbox nyata, bukan izin VPS. M07 tetap
  planned. Langkah berikut: login pengirim atau konfigurasi SMTP RT05 valid,
  recreate API/worker-default sesuai runbook, lalu buktikan inbox browser dan
  Android sebelum membuka implementasi M07. Jangan mengulang rollout/drill PASS.
- Validasi penutupan bukti: `node infra/scripts/check-repo.mjs` PASS682berkas;
  `git diff --check` PASS; Redocly2 lint OpenAPI exit0. Perubahan sesi pemulihan
  hanya README/dokumentasi/deskripsi OpenAPI/artefak diagram dan screenshot;
  source rest-api/mobile/infra/website/workflow identik26a0b7e yang diuji.
  Laporan Drive existing menerima checkpoint akhir dan readback verified;
  PR21 tetap draft karena gate inbox belum lulus. Tidak mengklaim deploy kode
  baru hanya dari commit dokumentasi berikutnya.

## Status

Paket konteks diimpor pada M01. API/mobile dan staging telah dibangun pada sesi
berikutnya; hasil dan keterbatasan ada di checkpoint di bawah. Status gate tidak
ditentukan hanya oleh tersedianya kode.

| Milestone | Status | Bukti/ketergantungan |
| --- | --- | --- |
| M00 | accepted_baseline | Handoff repo menyatakan desain accepted; M01 rerun QA baseline pada 34e9b21 dan dari website/ (lihat M01) |
| M01 | passed | PR #10 merged (02d92a1); production alias rt05takeda.vercel.app 19/19 HTML + 26 aset cocok setelah merge |
| M02 | passed | PR #11 merged (321db0d) |
| M03 | passed | PR #15, kode 0d678bb: API CI 88 tes/727 assertion, recovery worker/Redis nyata dan build infra SUCCESS; review final Kimi PASS; docs/evidence/m03/CI_RESULTS.md |
| M04 | passed | Gate fungsional sebelumnya12b4642; patch isolasi credential6a8d4f8 diterapkan12 Oktober: runtime tanpa migrator, DML/DDLdenied, direct job rollback0, heartbeat dan volume/unrelated preserved. Insiden rotasi tetap open; production belum siap. Checkpoint keamanan di bawah. |
| M05 | passed | PR17, source13de24e: 77 tes/analyzer/dua APK CI SUCCESS, checksum cocok; emulator health HTTPS, offline/retry proses sama, ikon dan skala teks1,3/2,0; review final Kimi PASS. docs/evidence/m05/LIVE_RESULTS.md |
| M06 | passed | Staging26a0b7e; API52alur/17mekanika reset; Android akun/sesi; SMTP nyata, INBOX A/browser dan B/Android, verifier4/4 masing-masing. Penutupan13Oktober; INBOX_ACCEPTANCE |
| M07 | planned | M06 passed; tahap berikutnya pendataan lengkap/histori membership/link, izin, concurrency dan penerimaan Android/staging |
| M08 | in_progress | Pengaduan + foto privat + anonim + status; tes CI lulus |
| M09 | in_progress | Aspirasi terpisah; tes CI lulus |
| M10 | in_progress | Agenda/ICS/Google Calendar, pengumuman, inbox; push FCM blocked (tanpa project Firebase) |
| M11 | in_progress | Tarif/tagihan idempotent/catch-up/transparansi; tes CI lulus |
| M12 | in_progress | Pembayaran offline/idempotency/pembalikan/koreksi/kas/CSV; tes CI lulus |
| M13 | planned | CMS API belum operasional |
| M14 | planned | Website real publication belum terintegrasi |
| M15 | planned | UAT/full QA belum dilakukan |
| M16 | planned | Production baru belum dirilis |

M00 memakai label accepted_baseline khusus, bukan status passed hasil tes baru. M01–M16 menggunakan planned/in_progress/passed/blocked.

## Riwayat pembukaan M06 (checkpoint 12 Oktober)

**M06 — registrasi dan akses akun**, dibuka oleh instruksi pemilik12 Oktober
melalui rencana M06–M07. Baseline `fb6e59f`; branch `codex/m06-account-access`.
M03/M04/M05 passed berdasarkan bukti existing. M07 belum dibuka untuk
implementasi baru sampai gate M06 lengkap. Rencana/kontrak dan matriks di
[CONTRACT.md](evidence/m06/CONTRACT.md) dan
[REQUIREMENTS.md](evidence/m06/REQUIREMENTS.md). Run Orca `run_f3422d73e850`:
Claude backend `task_3cdd792156f2`/`ctx_684922fbe969`, Grok Flutter
`task_3873a1c7e6bc`/`ctx_0b094886b9fe`; Codex integrasi/website/dokumen/CI.
Keputusan utama langsung aktif dengan keluarga belum_verifikasi dikonfirmasi
oleh rencana pemilik; capstone asli dipertahankan, selisih dilaporkan.

Checkpoint awal:
- PRD repo dan `PRD (2).md` induk identik SHA256
  `63c5888fa6005d3fbe893ddef66f7172a16800621dc21045bd3652a97d17fbac`.
- Folder laporan Drive dibuat pada Capstone/Tugas dan Project:
  https://drive.google.com/drive/folders/1_WweESw5u0iHaDlw6LUfFAuUgOzAstpj.
  Empat contact sheet diunggah; readback final dan laporan menyusul.
-40 foto ditinjau visual; satu duplikat persis; enam foto menjadi turunan
  WebP tanpa metadata. Dua album tambahan, album17Agustus bertambah dua foto;
  semua tanggal/tahun kosong. [ALBUM_REVIEW.md](evidence/m06/ALBUM_REVIEW.md).
- Website `npm.cmd run build:preview`:21 rute; `npm.cmd run test:preview`:
  105 kasus responsif,45 Axe +3 editor,37 link, nol error browser; interaksi,
  IndexedDB isolation/recovery dan slideshow3detik PASS. `php artisan test
  --compact`:9 tes/173 assertions passed. Ini QA lokal, bukan deploy online.
- OpenAPI M06 payload/auth envelopes diperinci; `npx.cmd --yes @redocly/cli@2
  lint docs/openapi.yaml` exit0. Gateway cookie/CSRF/reset dan session/mail
  env disiapkan; belum dibuktikan di server.
- SMTP host belum terlihat pada env lokal/staging yang diperiksa (hanya key
  dan status konfigurasi, tanpa mencetak nilai). Lokasi konfigurasi dan inbox
  uji ditanyakan; reset external belum passed. Insiden DB/APP_KEY tetap open;
  production dan data warga nyata tidak dibuka.

Checkpoint lanjutan12 Oktober:
- Album PR20 merged `9dd047f` setelah checks konten sukses. Deploy Git Vercel
  gagal mengambil informasi Git; snapshot statis committed `e472f7a` diterbitkan
  ke project existing. Alias publik terverifikasi **21/21HTML,32aset, mobile
  PASS,404rute asing,nol error** pada07:42UTC. [ALBUM_LIVE.json](evidence/m06/ALBUM_LIVE.json).
- Backend handoff pertama:162tes/1275assertions PostgreSQL17.10 lokal terisolasi,
  HTTP terpisah dan concurrency proses nyata; Pint passed. Flutter handoff
  pertama:analyze clean,98tes passed. Build lokal terhenti mengunduh NDK;
  APK/emulator dan CI tahap ini belum terbukti. [CLAUDE_RESULT.md](evidence/m06/CLAUDE_RESULT.md),
  [GROK_RESULT.md](evidence/m06/GROK_RESULT.md).
- Root meminta tindak lanjut sebelum review silang: guard mailer log di semua
  env, batas72byte UTF8 dan NUL sebelum bcrypt, status tambahan ditolak tetap
  terbatas, barrier rotasi deterministik, brand form reset, dan respons logout
  lama tidak menghapus sesi mobile baru. Task Claude `task_fb43b380b205`, Grok
  `task_f2c4204621e6`; hasil akhir/CI dicatat sesudah worker_done.
- Gateway `/auth-assets/*`, cookie API terpisah, mail scheme/from dan deploy
  immutable branch M06 disiapkan; Redocly valid, check-repo pass, kontrak infra
  pass, isolasi database self-test2positif+19negatif pass. Konsol Hostinger
  dapat dibuka; inspeksi read-only masih menunjukkan stack sehat SHA `6a8d4f8`.
  Belum ada rollout M06 atau klaim integration live baru.
- Folder Drive berisi15artefak yang di-readback: laporan/matriks/review/UI,
  enamPNGdiagram/sumberZIP,empatcontactsheet. Laporan final akan diperbarui
  pada fileID sama. Stitch listing masih40layar existing sesudah timeout;
  tidak ada layar baru terbukti.

Handoff final kode sebelum CI: Claude follow-up **171tes/1461assertions**
passed, Pint passed; race deterministik5run berurutan dan uji mutasi menemukan
regresi yang disengaja. Form reset Playwright lokal320–1280px tanpa overflow,
kedua font termuat, Axe0violation. Grok follow-up **107tes** passed/analyze
clean; epoch logout/401/storage/password response dan Unicode/NUL diuji.
[CLAUDE_FIXES.md](evidence/m06/CLAUDE_FIXES.md),
[GROK_FIXES.md](evidence/m06/GROK_FIXES.md). Ini belum membuktikan CI/Linux,
APK, staging baru atau inbox. SMTP staging dicek langsung dengan output hanya
nama key/status: mailer bukan smtp; HOST/PORT/USERNAME/PASSWORD tidak ada.
Konfigurasi pengirim belum tersedia; acceptance reset tetap blocked.

Checkpoint review12Oktober:
- Source `8d0b3df` CI seluruhnya SUCCESS: API171/1461, Flutter107/analyze/format
  dan kedua APK dev/staging, infra serta hygiene. [CI_RESULTS.md](evidence/m06/CI_RESULTS.md).
- Review silang Claude mobile/Grok backend selesai pada source tersebut.
  Grok memperbaiki bearer/epoch logout yang dipatok saat request dibuat,
  menahan kembali selama registrasi, dan judul/alasan permohonan ditolak;
  analyze clean dan114tes lokal passed. [GROK_REVIEW_FIXES.md](evidence/m06/GROK_REVIEW_FIXES.md).
- Claude menutup bootstrap fail-fast, kecocokan password lama persis, exception
  antrean sesudah commit dan limiter reset terpisah;179tes/1577assertions lokal
  passed. [CLAUDE_REVIEW_FIXES.md](evidence/m06/CLAUDE_REVIEW_FIXES.md).
  Review root menemukan race inisialisasi RateLimiter di implementasi reservasi
  token yang baru; follow-up terbatas `task_f684bcfbae8b`/`ctx_660bb74c1bce`
  menangani fresh/zero counter sebelum freeze kode final. Belum dianggap passed.
  Hasil suite sesudah koreksi184tes/1679assertions dan Pint pass; uji mutasi
  mendeteksi5reservasi lolos pada batas3 dalam versi vendor. CI baru menyusul.
- Pemilik menegaskan pengirim email belum pernah disiapkan. Asumsi SMTP existing
  pada rencana dicabut. Penerima uji sudah disimpan privat, bukan di Git.
  [SMTP_OPERATOR.md](evidence/m06/SMTP_OPERATOR.md) menyediakan langkah konkret.
  M06 belum passed dan implementasi baru M07 tetap terkunci.
- Folder Drive sekarang16artefak termasuk Google Doc native pada folder yang
  sama; isi/parents dan58paragraf readback verified. Final CI/live/screenshots
  akan diperbarui pada fileID yang sama.

Checkpoint source koreksi `15885cbf2d08b3e0f2b66b32899e9e6857ff63d1`:
- API CI38228867337 SUCCESS184tes/1679assertions, OpenAPI/Pint, Redis recovery
  dan role runtime/direct smoke; infra38228867353 dan hygiene38228867368 SUCCESS.
  Mobile38228867355 SUCCESS114tes/analyze/format dan kedua APK.
- Review delta Claude mobile/Grok backend selesai pada byte source yang sama;
  temuan sebelumnya tertutup. [CLAUDE_MOBILE_DELTA_REVIEW.md](evidence/m06/CLAUDE_MOBILE_DELTA_REVIEW.md),
  [GROK_BACKEND_DELTA_REVIEW.md](evidence/m06/GROK_BACKEND_DELTA_REVIEW.md).
- APK staging checksum cocok dan terpasang di emulator guest sintetis;
  fingerprint ada di CI_RESULTS. Layar masuk/validasi, daftar utama/tambahan,
  forgot generik untuk example.invalid dan form reset diperiksa. Form kosong
  tidak submit. Skala1,3/2,0 dapat scroll, tetapi error aturan password pada2,0
  terpotong; koreksi UI dibuka ke Grok `task_b2f9f3619c32`/`ctx_f84669a0ef50`.
  Tidak menganggap uji tampilan final passed sebelum APK koreksi diperiksa.
- Sesi konsol Hostinger sebelumnya kedaluwarsa; Chrome kini login Hostinger.
  Pemilik diminta membuka ulang Konsol VPS. Deploy script belum dieksekusi;
  health existing200/ready09:21UTC bukan bukti M06 terpasang. Bootstrap dan
  skrip browser/API sintetis siap privat, belum dijalankan ke staging.

Koreksi visual lanjutan: helper aturan password bersama dipakai daftar/reset/
ganti sandi; label pendek dan error16baris mengatasi ellipsis. Grok115tes awal
lalu116tes sesudah ganti sandi, analyze clean; tes320px/skala2,0 memeriksa
RenderParagraph utuh untuk aturan minimum dan pesan72byte. [GROK_EMULATOR_UI_FIX.md](evidence/m06/GROK_EMULATOR_UI_FIX.md).
CI/APK baru dan pemeriksaan emulator koreksi tetap menunggu. Guest offline/retry
source15885cb passed dengan PID sama; jaringan/font dikembalikan.
Koreksi terakhir: pengulangan reset terbukti terpotong pada 320px dan skala 2,0,
lalu `errorMaxLines: 16` dipasang pada error layar masuk, daftar, lupa sandi,
dan ganti sandi. Analyze bersih dan 117 tes lulus. Laporan
[GROK_EMULATOR_UI_FIX.md](evidence/m06/GROK_EMULATOR_UI_FIX.md) dan
[CLAUDE_PASSWORD_UI_REVIEW.md](evidence/m06/CLAUDE_PASSWORD_UI_REVIEW.md).
CI `7d42d56` lulus untuk repositori, API, infra, dan kedua APK. Di emulator,
skala 2,0, pesan pengulangan reset tampil utuh. [LIVE_RESULTS.md](evidence/m06/LIVE_RESULTS.md).
M06 belum passed. M07 tetap terkunci.
[LIVE_RESULTS.md](evidence/m06/LIVE_RESULTS.md) memisahkan bukti yang tersedia
dari gate registrasi/login/approval/keluarga dan sesi yang masih blocked.

### Checkpoint sebelumnya

**Penutupan patch keamanan fondasi M04**, PR18 merged8e2d8a5,
staging6a8d4f8 terverifikasi12 Oktober. Dokumen bukti pada branch
`codex/m04-security-evidence`; tidak membuka M06+.
Instruksi12 Oktober: prioritaskan insiden DB/APP_KEY dan isolasi migrator sebelum
data warga nyata. Gate M05 lulus source13de24e dan review final Kimi; PR17
sudah merged `ba3528d` pada11 Oktober12:02:22UTC. Seluruh delapan checks pada
head da42063 sukses, termasuk Mobile38137327953 (77 tes).
M04 sebelumnya merged `3a8be79`; rollout keamanan berikutnya menjalankan6a8d4f8.
Pemilik meminta pengerjaan bertahap dengan empat agent (P16), bukan membuka
seluruh roadmap serentak. [COORDINATION.md](COORDINATION.md) mencatat ownership,
gate, pemulihan setelah limit/listrik mati, dan urutan berikutnya.

PR #14 digabung pada 11 Oktober 2026 setelah lima cek SUCCESS dan review patch;
merge commit `c644b84`. `main` kini memuat routing/aset HTTPS yang sudah dipakai
staging. Tidak ada redeploy server dari aksi merge ini.

### M02 — Laravel API dan Neon foundation

- Status: passed (10 Oktober 2026), dengan catatan Postgres lokal di PC pemilik belum tersedia (lihat limitations).
- Branch/commit/PR: `codex/m02-api-neon-foundation` dari main 02d92a1; PR https://github.com/morrispes5/RT-05-TAKEDA/pull/11.
- Scope dan FR/BR: fondasi untuk FR18 (audit), FR23 (health), DATABASE.md/API.md/SECURITY.md konvensi; tidak ada fitur warga.
- Dependency verified: M01 merged; live preview cocok setelah merge (`check-live`: 19/19 HTML, 26 aset, HP PASS, 0 error).
- Runtime: Laravel 13.35.0 (sama dengan website), PHP 8.4.13 + pdo_pgsql/pgsql/intl/sodium (diaktifkan di php.ini lokal; backup `php.ini.bak-rt05-m02`), libpq 16.9, Postgres 17 (CI dan Neon 17.11).
- File berubah: `rest-api/` (skeleton Laravel dipangkas: tanpa frontend/Vite, users default, sessions/cache/jobs tables, rute storage otomatis), `docs/openapi.yaml`, `redocly.yaml`, `.github/workflows/api.yml`, `infra/compose.local.yml`, `infra/sql/grant-runtime-role.sql`, `infra/docker/postgres/init/01-rt05-roles.sql`, `infra/scripts/check-repo.mjs` (DSN lokal & SQL sumber diizinkan), `.gitignore`, docs DECISIONS (ADR13–16), DATABASE, ERD, README, infra/README, PROGRESS.
- Endpoint: `GET /health/live`, `GET /health/ready`, `GET /api/v1` (implemented). 102 operasi lain `x-status: planned` per milestone dan menghasilkan 404 JSON.
- Migration: failed_jobs, audit_logs (append-only trigger), outbox_events, idempotency_requests, system_settings.
- Environment/data: Neon project `rt05-takeda` (raspy-sky-23605923, aws-ap-southeast-1, PG17, gratis) dibuat dengan persetujuan pemilik; branch `production` (kosong) dan `dev` (br-crimson-art-b31mz59n). Data hanya sintetis/rollback; tidak ada data warga.
- Commands dan actual results:

  | Command | Hasil |
  | --- | --- |
  | CI `api.yml` (Postgres 17 service, role dari init SQL): `php artisan test` | 33 passed (370 assertions), 0 risky |
  | CI `rt05:db-smoke --connection=pgsql` / `pgsql_migrations` | rt05_app: tulis+baca ok, sisa 0, DDL denied; rt05_migrator: ok |
  | CI `npx @redocly/cli lint docs/openapi.yaml` | valid, 0 warning (operation-4xx-response off, ADR di redocly.yaml) |
  | Neon dev `composer migrate` (direct, rt05_owner) | 5 migration DONE |
  | Neon dev `rt05:db-grant-runtime-role rt05_app` | hak DML diterapkan |
  | Neon dev `rt05:db-smoke` (pooled, rt05_app) | server 17.11, endpoint pooled, sslmode verify-full, tulis+baca ok, sisa 0, DDL denied |
  | Neon dev `rt05:db-smoke --connection=pgsql_migrations` (direct) | ok, sisa 0 |
  | `APP_ENV=staging rt05:db-smoke` | exit 0 dengan verify-full; dengan URL `sslmode=require` exit 1 "sslmode harus verify-full" |
  | TLS negatif ke Neon | CA palsu: "certificate verify failed"; sslmode=disable: ditolak Neon "connection is insecure" |
  | `php artisan serve` → Neon dev | /health/live 200 ok; /health/ready 200 database ok; /api/v1 200 + request_id; /api/v1/auth/register 404 NOT_FOUND; Cache-Control no-store; body tanpa host/role |
  | Role check | rt05_app anggota neon_superuser: 0 |
  | `node infra/scripts/check-repo.mjs` | OK; uji negatif DSN Neon berpassword tertangkap |

- Evidence path: log CI PR #11 (API verification), tabel di atas.
- Acceptance gates passed: migration Postgres CI + Neon dev direct sukses; runtime pooled read/write sintetis terverifikasi; SSL verify-full terbukti (positif + negatif); client publik tidak menerima DSN (tes no-leak health/error); health/error schema diuji terhadap OpenAPI; role minimal (DDL denied, bukan superuser).
- Acceptance gates blocked/failed: tidak ada untuk gate ROADMAP. Catatan: Postgres lokal pada PC pemilik belum berjalan (Docker Desktop butuh WSL2 yang belum terpasang); tes Postgres dijalankan di CI.
- Security/finance/privacy implications: role runtime tanpa DDL; audit append-only; error tanpa stack trace/host; rute `storage/{path}` bawaan dimatikan; tes menolak database selain *_test lokal sehingga tidak menyentuh Neon.
- External mutation performed dan dasar otorisasi: merge PR #10 (instruksi "oke merge"); pembuatan project Neon rt05-takeda + branch dev + role rt05_app + migration fondasi di branch dev (persetujuan eksplisit di pertanyaan sesi M02); push branch + PR #11. Tidak ada perubahan Vercel/DNS/VPS. Branch production Neon tidak disentuh.
- Known limitations:
  - Tidak ada Postgres di PC pemilik sampai WSL2/Docker atau Postgres portabel disiapkan.
  - `sslrootcert=system` tidak didukung PHP Windows; dev memakai salinan CA bundle Git di `%USERPROFILE%\.rt05\ca-bundle.crt` (path tanpa spasi). Bundle salinan tidak otomatis ter-update.
  - `pg_prepared_statements` via pooler menampilkan statement milik pooler; tes nol-prepared hanya berarti pada koneksi direct.
  - Password Neon ada di `rest-api/.env` lokal (di-gitignore). Credential owner pernah tampil di keluaran tool agent saat diambil; pertimbangkan reset password rt05_owner branch dev setelah sesi.
  - FK actor_id ke users menunggu M06. Redis/queue/session menunggu M03.
- ADR/doc updates: ADR13–ADR16; DATABASE.md, ERD.md (implementasi M02), rest-api/README, infra/README, README root, openapi.yaml.
- Next milestone: M03 (Redis queue/cache, worker, scheduler, outbox dispatcher). Prasyarat lokal Redis juga butuh Docker/WSL atau Redis di CI.

### M01 — Monorepo foundation

- Status: passed (10 Oktober 2026). Gate lokal, CI GitHub, dan preview Vercel PR terbukti. PR belum di-merge; setelah merge, Root Directory Vercel diubah pemilik (docs/website/VERCEL_ROOT.md).
- Branch/commit/PR: `codex/m01-monorepo-foundation` dari `main` 34e9b212a3948a52e5f705a561691fac10103ae9. Commit: 30e3903 (rename murni 219 file, 100% identik), 8b36540 (adaptasi monorepo), 350390a (perbaikan script paket), 1ccc932 (progress), commit penutupan ini. PR: https://github.com/morrispes5/RT-05-TAKEDA/pull/10.
- Tanggal: 10 Oktober 2026.
- Scope dan FR/BR: FR01 (rute/layout baseline), BR01/BR02 dipertahankan; tidak ada fitur baru.
- Dependency verified: M00. `git status` bersih di `main`; tag `web-frontend-approved-2026-10-10` = HEAD; branch remote `claude/hopeful-ptolemy-acq64b` tidak memuat commit tambahan; tidak ada perubahan pengguna yang belum di-commit. Repo publik, `main` tanpa branch protection.
- Runtime: PHP 8.4.13, Composer 2.8.12, Node 22.16.0, npm 10.9.2, Git 2.54, gh 2.97, Docker 29.6.1 (daemon tidak berjalan saat inspeksi). Flutter/Dart tidak ditemukan. Android SDK (platforms 34–36.1, build-tools 36.x), emulator + AVD Pixel_7a/Pixel_8a, JBR Android Studio tersedia; `java` di PATH = 1.8, `JAVA_HOME` kosong.
- File berubah:
  - Dipindah: source/preview/scripts/tests/config/lockfile → `website/`; dokumen/bukti/screenshot frontend → `docs/website/`; `.github/workflows/frontend.yml` → `website.yml`.
  - Baru: `docs/*.md` (18 dokumen paket), `docs/website/VERCEL_ROOT.md`, `docs/evidence/m01/`, `.github/workflows/repo.yml`, `infra/scripts/check-repo.mjs`, `infra/README.md`, `mobile/README.md`, `rest-api/README.md`, `vercel.json` root (jembatan), `.gitignore` root.
  - Diubah: `README.md`/`AGENTS.md` root (gabungan paket + AGENTS frontend), `website/README.md`, `website/scripts/package-frontend.ps1`, `docs/FRONTEND.md` (koreksi commit/tree), `docs/SOURCES.md` (tautan tag), `docs/DECISIONS.md` (P13, P14, ADR11, ADR12), tautan internal `docs/website/*`.
  - Tetap di root: `.claude/`, `.editorconfig`, `.gitattributes`.
- Endpoint/migration/config baru: tidak ada endpoint/migration. Config: workflow `website.yml` (working-directory `website`, path filter `website/**`), `repo.yml`.
- Environment/data: lokal Windows; tanpa database; tanpa data warga.
- Commands dan actual results:

  | Command (cwd) | Hasil |
  | --- | --- |
  | Baseline di root, commit 34e9b21: `npm.cmd run build:preview`, `git status -- preview` | 19 rute diekspor; `preview/` tanpa diff |
  | Baseline: `php artisan test`, `php vendor/bin/pint --test` | 9 tes/155 assertions passed; Pint passed |
  | `node scripts/check-live.mjs https://rt05takeda.vercel.app` (sebelum migrasi) | 19/19 HTML exact, 26 aset cocok, 404 untuk rute tak dikenal, HP PASS, 0 error browser |
  | `curl -I` live | 200, `X-Robots-Tag: noindex`, `/pengurus/konten` 307 → `/pengurus` |
  | `website/`: `npm.cmd run build:preview` | 19 rute; 67 blob `website/preview/` identik dengan blob `preview/` baseline |
  | `website/`: `php artisan test`; Pint | 9/155 passed; passed |
  | `website/`: `php artisan route:list` | Rute tetap `/`, `/profil`, `/fasilitas`, `/dokumentasi/{slug}`, `/artikel/{slug}`, `/kontak`, `/pengurus/*`; tidak ada prefix `/website` |
  | `website/`: `npm.cmd run test:preview` (Playwright Chromium) | 95 kasus responsif, 41 Axe, 33 tautan internal, 0 error browser; interaksi, editor (artikel/album/persistensi/isolasi/recovery/text safety/reset, 0 network write), slideshow 3 detik semua PASS |
  | `website/`: `npm.cmd audit` | 0 vulnerabilities |
  | Clone bersih branch: `node infra/scripts/check-repo.mjs`, composer/npm install, build, test, Pint, `git diff --exit-code -- preview` | Semua lulus; preview clean |
  | Clone bersih: `scripts/package-frontend.ps1` | 201/201 file `website/` cocok blob Git; ZIP berawalan `web/`, tanpa `docs/`/`mobile/` |
  | Uji negatif `check-repo.mjs` (DSN berpassword, `.git` bersarang, tautan rusak) | Exit 1, ketiganya tertangkap; dibersihkan |
  | Parse YAML `.github/workflows/*.yml` (paket `yaml` di scratchpad) | Valid; `website.yml` 13 step working-directory `website`; `repo.yml` 3 step |
  | `git log --follow -- website/README.md` | History berlanjut ke commit sebelum migrasi |
  | `git push -u origin codex/m01-monorepo-foundation`; `gh pr create` | PR #10 dibuat, mergeable/clean |
  | GitHub Actions run 38045547706 (Repository checks / hygiene) | pass |
  | GitHub Actions run 38045547743 (Website verification / verify) | pass: 19 rute, 95 responsif, 41 Axe, 0 error browser |
  | Vercel deployment PR (dpl_7TmjL5n7RZkFdBiQF2uchf558nEQ), Root Directory belum diubah | Ready; dilayani lewat jembatan `vercel.json` root |
  | Fetch + SHA-256 seluruh 67 file preview PR dari Chrome pemilik (preview dilindungi SSO Vercel) | 66 exact; `/` cocok setelah membuang script toolbar Vercel Live yang disisipkan Vercel; 404 untuk rute tak dikenal; `/pengurus/konten` redirect; `X-Robots-Tag: noindex` |

- Evidence path: `docs/evidence/m01/local-qa.json`, `docs/evidence/m01/live-web-before-migration.json`, `docs/evidence/m01/pr10-ci-and-preview.json`.
- Acceptance gates passed: struktur normal tanpa `.git` bersarang; routes/desain unchanged; preview export sesuai source; test web relevan lulus; workflow valid (dijalankan di GitHub Actions); credential tidak ikut (check-repo); dokumen/prompt dipasang; PROGRESS memuat commit baseline; konfigurasi Vercel eksternal yang belum diubah dicatat terpisah (VERCEL_ROOT.md).
- Acceptance gates blocked/pending: tidak ada untuk M01. Tindak lanjut di luar gate: merge PR #10, ubah Root Directory Vercel, hapus jembatan `vercel.json` root.
- Security/finance/privacy implications: tidak ada data warga/credential. `.gitignore` root menolak `.env*`, kunci, signing, dump.
- External mutation performed dan dasar otorisasi: push branch `codex/m01-monorepo-foundation` dan pembuatan PR #10 atas instruksi langsung pemilik ("push aja terus bikin PR nya"). Tidak ada merge, perubahan setelan Vercel, DNS, atau VPS. Baca saja: GitHub API, Vercel API, HTTP GET preview, fetch preview PR melalui Chrome pemilik.
- Known limitations:
  - Proyek Vercel preview tidak terlihat dari connector agent pada team `morriz`; Root Directory aktual belum dibaca. Jembatan `vercel.json` root menjaga preview sampai pemilik mengubahnya (docs/website/VERCEL_ROOT.md).
  - Commit 30e3903 sendiri berisi workflow dengan path lama; branch dinilai sebagai satu PR.
  - Preview PR Vercel memakai Deployment Protection; `scripts/check-live.mjs` dari luar mendapat 302 SSO. Verifikasi preview PR memakai sesi Chrome pemilik. Alias production `rt05takeda.vercel.app` publik dan tetap dapat diperiksa dengan `check-live`.
  - Connector Vercel agent tidak berizin membaca deployment proyek `morrizshkki/rt05takeda` (403).
  - Flutter SDK belum terpasang (prasyarat M05).
- ADR/doc updates: ADR11, ADR12, P13, P14; koreksi FRONTEND.md (34e9b21 adalah commit, tree 37510826bfedb447ea572f0b72cd5577f7bd0270).
- Next milestone: M02 (Laravel API + Neon dev). Tutup M01 setelah push/PR: CI hijau dan preview Vercel PR cocok dengan `check-live`.

## Template laporan milestone

### MNN — Nama

- Status:
- Branch/commit/PR:
- Tanggal:
- Scope dan FR/BR:
- Dependency verified:
- File berubah:
- Endpoint/migration/config baru:
- Environment/data:
- Commands:
- Actual results:
- Evidence path:
- Acceptance gates passed:
- Acceptance gates blocked/failed:
- Security/finance/privacy implications:
- External mutation performed dan dasar otorisasi:
- Known limitations:
- ADR/doc updates:
- Next milestone:

### Sesi 3 (10 Oktober 2026) — layanan inti, mobile, artefak deploy

- Instruksi pemilik: kerjakan semampunya lintas milestone (izin VPS/Docker/PC), hemat SSD, desain mobile boleh kolaborasi Google Stitch. Dicatat sebagai P15.
- Branch `codex/m06-m12-layanan-inti`, PR https://github.com/morrispes5/RT-05-TAKEDA/pull/12 (belum merge).
- Logbook #1–#4, Tugas 03/04, ERD v1.1 (31 tabel) dibaca dari Google Drive. Tabel domain mengikuti nama ERD v1.1 (ADR17 rencana): akun, token_registrasi, permohonan_akun, rumah, pemilik_rumah, keluarga, warga, penghunian, kategori/pengaduan/foto/riwayat, aspirasi/riwayat, agenda, pengumuman, notifikasi, preferensi, tarif/tagihan/pembayaran/alokasi iuran, transaksi_kas/riwayat. Penyesuaian v1: rupiah bigint, outbox/idempotency/audit_logs.
- API: 82 route implemented (OpenAPI 1.0.0-m12, 33 planned: konten publik CMS M13/M14, media terpisah, device push, app-version).
- Bukti:
  - CI API (Postgres 17, role runtime): 71 tes lulus termasuk identitas, keuangan (snapshot tarif, multi-bulan, parsial ditolak, idempotency replay/409, alokasi ganda ditolak DB, pembalikan sekali, koreksi atomik, ledger immutable), layanan (anonimitas feed/detail/riwayat/notifikasi, edit pemilik, versi 409, foto re-encode & spoof 415, outbox dedup).
  - Smoke HTTP lokal → Neon dev: 24/24 lulus (login, keluarga, iuran Rp75.000, transparansi tanpa PII, saldo kas 530.000, anonim, 403 warga, akun menunggu, token no-store, AMOUNT_MISMATCH, replay, 409, pembalikan, inbox, Google Calendar).
  - CI Infra images: compose valid, image API + website build, smoke gateway (/health/live, /api/v1, /profil, 404 JSON) lulus.
  - Mobile: `flutter analyze` bersih, `flutter test` 13 lulus (lokal); CI build APK debug berjalan.
- Bug nyata ditemukan & diperbaiki: catch-up tagihan melewatkan bulan berjalan (konversi Asia/Jakarta→UTC); timestamptz(0) membulatkan ke atas sehingga outbox tertahan; FK self-reference sebelum PK; Sanctum provider tidak terdaftar karena --no-scripts.
- Lingkungan PC: Flutter di C:\Users\USER\dev\flutter (1,4 GB, Android saja). WSL/Docker lokal sengaja TIDAK dipasang (hemat SSD); tes Postgres/Redis/Docker di GitHub CI. Emulator Pixel_8a dinyalakan.
- Neon dev di-reset (migrate:fresh, branch dev sintetis) dan di-seed `rt05:demo-seed`; password akun demo hanya ada di `/tmp/demo-seed.txt` lokal (Git Bash), tidak di repo.
- VPS: Hostinger KVM 2 srv2030339.hstgr.cloud 187.77.113.136, Ubuntu 24.04 + Coolify. SSH dari agent ditolak pengaman otomatis; port 8000 (panel Coolify) tidak terjangkau dari luar. DNS morriz.tech: @ → 2.57.91.91, www CNAME; record `takeda` BELUM dibuat (sesi terhenti karena batas pemakaian).
- Blocked/tersisa: DNS takeda + takeda-staging; deploy Coolify (butuh URL/akses panel Coolify; credential production dimasukkan pemilik sendiri ke Coolify); branch Neon production role rt05_app; SMTP; FCM; signing key rilis; website CMS ke API (M13/M14); UAT.

### Sesi 4 (10 Oktober 2026) — emulator, DNS, siap deploy

- PR #12 merged (00cd078) setelah API, Infra images, Mobile, Repository checks hijau.
- Emulator Pixel_8a: APK CI terpasang; login warga demo → beranda (iuran Oktober Belum dibayar), iuran (Agustus/September Lunas), layanan (pengaduan anonim "Identitas disembunyikan"), akun/keluarga; login pengurus → ringkasan (saldo Rp530.000, 3 rumah, 1 permohonan) → catat iuran Oktober A-1 → saldo Rp605.000, rekap 1 lunas/2 belum. Bukti: docs/evidence/mobile/01–08.
- DNS: A takeda dan takeda-staging → 187.77.113.136 dibuat lewat hPanel (izin pemilik); resolusi 8.8.8.8 terverifikasi; HTTPS ke host menjawab 503 dari proxy Coolify (belum ada aplikasi).
- Neon: branch staging (dari dev, data demo) dibuat; role rt05_app di branch production dibuat via SQL (bukan neon_superuser). Env server ditulis ke infra/env/.env.staging dan .env.production (gitignored, tidak dicetak).
- Deploy: infra/compose.vps.yml + infra/scripts/deploy-vps.sh (ADR18). Menunggu pemilik menjalankan script di Konsol web Hostinger (agent tidak menjalankan perintah shell di server).
- Keterbatasan: staging memakai password rt05_app yang sama dengan dev (ALTER ROLE ditolak di branch turunan); push FCM, SMTP, CMS website→API (M13/M14), signing key rilis, UAT belum.

### Sesi 5 (11 Oktober 2026) — deploy staging lewat Konsol web

- Otorisasi langsung pemilik: jalankan instruksi deploy staging dari Claude melalui Chrome dan beri kabar setelah selesai. Target hanya `takeda-staging.morriz.tech` / project `rt05-staging`.
- Baseline source server/lokal: `53a8b10c9156fa6dcbd57d623863efb238f39d64`. Perbaikan lokal disimpan pada branch `codex/m04-staging-routing`; server menjalankan baseline tersebut dengan patch empat file yang sama. Patch belum berada pada `origin/main`.
- Build pertama lima image berhasil (~311 detik), deploy script exit 0. Migrasi: `Nothing to migrate`; hak DML role runtime diterapkan. Env server berada di `/root/.rt05/.env.staging`, mode 600; direktori mode 700.
- Dua masalah live ditemukan dan diperbaiki: label Traefik berbentuk mapping tidak menginterpolasi nama label sehingga HTTPS menjawab 503; chain proxy tidak mempertahankan HTTPS sehingga URL aset HTTP diblokir browser. Label kini memakai list `key=value`; gateway mempercayai proxy privat; website menerima `TRUSTED_PROXIES` dan APP_ENV staging.
- File implementasi: `infra/compose.vps.yml`, `infra/compose.production.yml`, `infra/docker/gateway.Caddyfile`, `website/bootstrap/app.php`. Hanya gateway/web staging dibuat ulang setelah perbaikan; tidak ada operasi mutasi pada container layanan lain, DNS, firewall, atau production.
- Verifikasi 07:31 WIB: HTTPS valid tanpa bypass; `/health/live`, `/health/ready`, `/`, `/profil`, `/api/v1` semuanya HTTP 200; ready mengembalikan database ok. Browser Chrome memuat CSS/JS/gambar HTTPS dan navigasi profil berhasil. API/web/Redis healthy; worker/scheduler Up; enam container staging restart count 0.
- Lokal: `php artisan test --compact` lulus 9 tes/155 assertion; pemeriksaan repo dan `git diff --check` lulus. Caddy validation, Compose config, build web ulang (~11 detik) berhasil.
- Insiden rahasia: setelah env ditempel dengan terminal echo off, pembacaan AX browser masih mencatat nilai textarea dalam keluaran alat. Tidak ada nilainya pada source atau evidence tersimpan; clipboard dibersihkan. Rotasi password role database dan APP_KEY staging diperlukan sebagai tindak lanjut; password runtime dev yang sama perlu ditangani bersama. Rotasi belum dilakukan.
- Handoff lengkap: [docs/evidence/m04/STAGING_DEPLOY_2026-10-11.md](evidence/m04/STAGING_DEPLOY_2026-10-11.md). Jangan menjalankan ulang script yang melakukan reset ke `origin/main` sebelum patch disatukan ke main, karena akan mengembalikan dua bug deploy.
- Batas hasil: deploy/smoke staging selesai; gate M04 menyeluruh (Neon write, job/heartbeat, restart persistence) dan M15/M16 belum dinilai ulang pada sesi ini.

### Sesi 6 (11 Oktober 2026) — verifikasi staging, APK staging, temuan rahasia

- Verifikasi dari PC: `/health/ready` 200 (database ok), `/health/live`, `/api/v1`, `/`, `/profil` 200; `POST /api/v1/auth/mobile-login` tanpa body → 422, `GET /api/v1/auth/me` tanpa token → 401. `https://takeda.morriz.tech` masih sertifikat default Traefik (production belum di-deploy).
- Patch routing sesi 5 dibawa ke main lewat PR #14 (branch `codex/m04-staging-routing`).
- APK debug dengan `API_BASE_URL=https://takeda-staging.morriz.tech` dibangun via workflow_dispatch (run 38099378306, sukses), dipasang ke emulator Pixel_8a; layar login tampil. Bukti: docs/evidence/mobile/09-login-staging.png. Login ke staging belum diuji agent (credential ke host non-lokal diketik pemilik sendiri). Catatan: kunci debug CI berbeda per run, jadi APK lama harus di-uninstall sebelum memasang yang baru.
- Temuan: password `rt05_owner` di `.env.staging` identik dengan `.env.production` (role disalin saat branch Neon dibuat), sehingga insiden sesi 5 juga mengenai credential owner production. Rotasi oleh agent ditolak pengaman (penulisan secret store); **blocked → pemilik**: reset password `rt05_owner` di branch production (dan staging/dev) serta buat APP_KEY production baru sebelum deploy production. Langkah ada di docs/DEPLOYMENT.md (Rotasi credential).

## Checkpoint sesi

### Sesi 7 (11 Oktober 2026) — koordinasi dan pemulihan M03 setelah listrik mati

- Instruksi terbaru: Codex mengelola Claude Code, Grok, dan Kimi dengan ownership
  file terpisah, satu milestone beserta gate sebelum maju; target sampai M05
  bertahap, semua perbedaan planning/Drive capstone dilaporkan. Pemilik menyediakan
  Chrome Hostinger dan mengizinkan terminal/emulator serta akses deployment.
  Otorisasi dicatat di DECISIONS dan COORDINATION; tidak meminta ulang akses.
- Run Orca `run_2d500f79d407` dipulihkan dan coordinator diikat ulang pada runtime
  setelah listrik mati. Sesi Claude yang sudah dipulihkan pemilik dipakai kembali;
  tidak mereset konteks, provisioning, database, atau pekerjaan pengguna.
- PR #14 diverifikasi lima cek SUCCESS, ditinjau, lalu merged dengan HEAD
  `8a723f9` ke merge commit `c644b84`. Branch `codex/m03-async-recovery`
  dibuat dari baseline itu. Server tidak berubah akibat merge lokal/GitHub.
- Pembagian: Claude `rest-api/` (dispatcher, job, failure parking, heartbeat dan
  tes); Grok `infra/` serta workflow API/infra (Redis terpisah, worker, recovery
  drill); Kimi laporan penerimaan independen; Codex Git, kontrak, dokumen dan
  verifikasi gabungan. Tidak ada agent lain mengubah Git pada tahap ini.
- Audit awal Kimi menemukan dispatcher sinkron tanpa Redis job, satu Redis
  untuk antrean/cache, serta bukti restart yang belum ada. Backend dan infra
  diserahkan dengan worker_done diterima. Laporan di `docs/evidence/m03/`.
- Kontrak: `ProcessOutboxEvent` membawa UUID, inbox/completion atomik di Postgres;
  lease/stale replay; event gagal diparkir lewat `failed_at` dan retry per UUID.
  Config queue `retry_after=180`, `after_commit=true`; connection queue/cache
  terpisah; probe internal `rt05:heartbeat-check`. ERD/ASYNC_JOBS diperbarui;
  tidak ada endpoint HTTP baru sehingga kontrak OpenAPI tidak berubah.
- Validasi yang sudah dijalankan: `node infra/scripts/check-repo.mjs` lulus
  (470 berkas pada checkpoint integrasi); `git diff --check` lulus;
  `website/: php artisan test --compact` lulus 9 tes/155 assertion.
  Website tidak diubah pada branch M03, sehingga tidak ada ekspor preview baru.
- Backend lokal: Pint passed; `RT05_SKIP_TEST_MIGRATIONS=1 php vendor/bin/phpunit
  tests/Feature/HeartbeatTest.php tests/Unit` passed 10 tes/50 assertion tanpa
  koneksi database. Tes Postgres/Redis penuh menunggu CI; angka ini bukan
  kelulusan gate recovery.
- Infra statis `check-m03-compose.mjs` dan `bash -n` lulus. Sesudah handoff,
  Codex memperbaiki kepemilikan PID worker/scheduler dengan exec PHP, memeriksa
  inbox kosong/outbox committed sebelum SIGKILL, menunggu dua delivery drain,
  mengikuti AOF everysec, dan menambah path filter CI skrip recovery.
- Dokumen capstone Tugas 03/04 dibaca melalui Google Drive; sumber dicatat di
  SOURCES; Logbook 1/2/3 dan brainstorm turut dibandingkan. Semua selisih yang
  teridentifikasi dicatat di CAPSTONE_ALIGNMENT.md, termasuk flow approval
  registrasi utama dan tipe nominal. Chrome Hostinger dapat diinspeksi melalui computer-use; emulator
  Pixel_8a dinyalakan, `adb shell getprop sys.boot_completed` mengembalikan 1.
  Belum ada login emulator atau redeploy staging pada checkpoint ini.
- CI aktual kode `0d678bb` pada PR #15: API run 38112554974 SUCCESS,
  88 tes/727 assertion; kill setelah commit, duplikat, AOF everysec restart,
  queue-loss replay (attempts=2/inbox=1), heartbeat worker/scheduler,
  runtime/migrator smoke rollback=0 dan OpenAPI. Infra run 38112554907
  SUCCESS: Compose, build API/web, gateway smoke. Hygiene/Vercel keduanya
  SUCCESS. Bukti rinci: `docs/evidence/m03/CI_RESULTS.md`.
- Review final Kimi memeriksa log CI langsung dan menutup B01–B05/N01–N04;
  seluruh gate M03 PASS. Docker/WSL lokal tetap tidak dipasang sesuai ADR19;
  CI sintetis ini bukan bukti deployment VPS baru. Tidak menjalankan tes Neon.
- Kimi aktif mengaudit meskipun composed startup Orca keliru menandai
  `agent_prompt_stalled`; laporan tersimpan. Coordinator memulihkan tracking
  tanpa menggandakan atau merestart agent yang masih bekerja. Review final
  dikirim sebagai prompt biasa dan task dipulihkan manual berdasarkan laporan
  tersimpan; tidak mengklaim worker_done diterima dari capability yang dicabut.
- Langkah berikutnya: merge penutupan M03, kemudian staging M04 karena gate
  M03 telah terbukti. Insiden credential
  sesi 5–6 tetap open; production/UAT/signing/SMTP/FCM bukan hasil tahap ini.

### Sesi 8 (11 Oktober 2026) — M04 setelah M03 lulus

- PR #15 merged `27a3e5b` sesudah lima cek SUCCESS pada `ea4179e`;
  kode/API/infra diuji ulang, review final Kimi PASS. Branch M04:
  `codex/m04-staging-recovery`, dari main yang sudah memuat M03.
- Claude menyerahkan probe CLI `rt05:staging-smoke`: APP_ENV staging dan
  flag konfirmasi wajib sebelum akses DB; satu event sintetis tanpa penerima,
  akun, inbox atau keuangan. Guard *_test CI lama tidak dilonggarkan.
- PR #16 pada `75dee91`: lima check SUCCESS. API run 38113599864 lulus
  93 tes/766 assertion (lima StagingSmokeTest); infra 38113599888 dan hygiene
  38113599875 SUCCESS. Bukti `docs/evidence/m04/CI_RESULTS.md`; ini belum
  mencakup skrip rollout infra berikutnya maupun operasi live VPS.
- Grok menyiapkan rollout SHA tetap dan checkout release terpisah; Kimi
  memeriksa batas tindakan, schema/privasi, credential dan bukti live.
- Preflight nyata Chrome Hostinger ada di `docs/evidence/m04/PREFLIGHT.md`.
  Server mempunyai empat patch routing lama belum di-commit; dipertahankan.
  Available RAM 5614 MiB dan disk 80G; Docker 29.8.2/Compose 5.6.0.
  Coolify/aplikasi lain berjalan; tidak disentuh.
- Dua password Redis staging belum ada. AUTH tetap wajib; provisioning
  khusus staging harus menjaga credential/APP_KEY/DSN yang sudah ada.
  Tidak merotasi credential production yang sebelumnya ditolak pengaman.
- Setelah handoff Grok, Codex menutup temuan integrasi: same-SHA enam service,
  snapshot volume/mount dan unrelated ID/StartedAt, compatibility rollback,
  timeout probe, serta marker pending yang harus selesai dengan attempts
  sama setelah Redis AOF restart. Helper Python hanya mengisi dua key Redis
  staging kosong/hilang secara atomik pada env existing privat; tanpa output
  nilai atau perubahan DSN/APP_KEY. `bash -n`, empat pure synthetic tests,
  check-m03-compose, check-repo dan diff-check lokal lulus; belum dijalankan
  terhadap file env/server nyata. Reviewer pengganti Claude memeriksa kode.
- Gate live M04 masih pending: rollout baru, Neon read/write sintetis,
  heartbeat empat komponen, restart/persistence dan pemeriksaan service lain.
  M05 belum dibuka untuk implementasi baru sebelum bukti remote M04.
- Audit baca-saja kesiapan M05 oleh Claude ada di
  `docs/evidence/m05/READINESS_REVIEW.md`; belum implementasi baru atau gate
  mobile. M05 dapat dibuktikan dengan health request nyata; login layanan
  M06 dan UAT fitur berikutnya bukan prasyarat fondasi M05.
- Kimi menyelesaikan checkpoint kode backend, tetapi dispatch review infra
  berikutnya berhenti karena provider 403 batas penggunaan 5 jam. Task
  `task_9689bdf377b5` ditandai blocked lewat pemulihan coordinator, tanpa
  memalsukan worker_done. Sesi tidak ditutup/diganti atau diulang saat limit.
  Review independen integrasi infra dialihkan kepada Claude.

### Penutupan operasi M04 (11 Oktober, sesudah sesi/kuota dipulihkan)

- Instruksi terbaru pemilik: bila Kimi limit, alihkan tugas ke Grok, pertahankan
  sesi Kimi. Review acceptance final M04 memakai Grok, Dispatch baru
  `ctx_457c94ed8b6a`; kepemilikan file review saja. Codex tetap pemilik Git/VPS.
- Provisioning hanya dua key Redis staging kosong melalui helper yang blob
  SHA256-nya cocok; file env root mode 600/direktori 700, credential lain
  dipertahankan, tidak ada nilai dicetak. Ini sudah dieksekusi, bukan pending.
- Rollout pertama62 menemukan kesalahan nyata opsi `compose run --no-build`;
  berhenti sebelum migrasi/API. Dipulihkan dari image62 tanpa edit worktree:
  migrasi failed_at applied, grant runtime DML, up stack hanya project sendiri.
  Perbaikan opsi `run --rm --no-deps --pull never` dan guard CI di12b4642.
- Lima cek PR #16 pada12b sukses: API38115505380 (93 tes/766 assertion),
  infra38115505350, hygiene38115505386, Vercel dan Preview Comments.
- Rollout otomatis final12b benar-benar selesai di Hostinger. Current-sha12b,
  previous62; enam service image SHA sama dan worker/scheduler ID sama API.
  Source legacy dengan empat patch routing belum commit tetap dipertahankan.
- Runtime smoke final12b PASS: Neon staging pooled rt05_app verify-full
  read/write rollback0 DDLdenied, direct owner rollback0; event published1
  tetap tersimpan sesudah Redis antrean AOF restart dan completed1/inbox0.
  Empat heartbeat sehat; tiga volume name/CreatedAt dan 13 unrelated ID/StartedAt
  sama. Diff baseline unrelated dari sebelum operasi62 ke12 comparison=0.
- GET luar VPS TLS valid: live/ready/API root dan empat rute website200;
  ready databaseok; tanpa token auth/me401; rute tidak ada404. Profil Agus,
  aset beranda/profil tidak memakai HTTP. API /profil belum M13 (404 expected).
- Neon connector mengonfirmasi staging terpisah dari dev/production; pencarian
  substring host yang diizinkan pada env cocok dengan compute staging, tanpa DSN.
  Bukti lengkap `docs/evidence/m04/LIVE_RESULTS.md`. Review Grok final menerima
  gate fungsional pada `docs/evidence/m04/GROK_ACCEPTANCE_REVIEW.md`.
- Gate fungsional M04 terpenuhi. Rotasi credential DB/APP_KEY insiden lama
  belum selesai karena pengaman otomatis sebelumnya menolak (secret store);
  tidak dicoba lewat jalur lain. Tidak mengklaim keamanan production/M16.
  M05 dibuka setelah review, pembukuan dan merge PR16; hanya fondasi mobile.

### Checkpoint M05 — implementasi dan review awal

- Branch `codex/m05-mobile-foundation`, rencana dipush pada `0309f3e` setelah PR16 merged. Scope hanya fondasi M05; layanan bisnis historis M06+ belum diterima oleh gate baru.
- Claude memiliki `mobile/**`: config, health, secure session, go_router, brand/font terbundel, lokalisasi dan tes skala teks. Overflow tata letak lama ditemukan lewat widget test dan sedang diperbaiki; tidak ada perubahan aturan bisnis.
- Grok menyerahkan workflow/guard CI awal dan laporan `docs/evidence/m05/GROK_CI_RESULT.md`. Review Codex menemukan perbedaan penerimaan slash akhir/port dengan Dart; follow-up Grok aktif untuk menyamakan kontrak. Belum ada run GitHub atau APK baru.
- Pemilik memperkirakan kuota Kimi tersedia lagi. Sesi sama mulai merespons audit, tetapi dua `worker-start` diberi `agent_prompt_stalled` oleh Orca; laporan completion konteks M04 lama ditolak `inactive_dispatch`. Ini bukan bukti gate baru. Sesi dipertahankan dan scope diarahkan ke audit rencana M05 saja; Grok tetap melanjutkan pekerjaan utamanya.
- `docs/MOBILE.md` menjelaskan struktur fondasi aktual, konfigurasi origin/HTTPS, health publik sebagai gate M05, dan pilihan kecil label navigasi maksimum skala1,3 (isi layar mengikuti skala sistem). Gate analyze/test baru, artifact CI, health emulator, offline/retry dan review final masih pending.
- `node infra/scripts/check-repo.mjs` (516 berkas pada pemeriksaan Codex) serta `git diff --check` lulus pada checkpoint dokumentasi; angka dapat bertambah saat worker menulis. Tidak menjalankan Gradle/APK lokal, tes/factory Neon, atau redeploy VPS.

Integrasi setelah handoff: Claude menyelesaikan75 tes, Grok menyerahkan guard
yang telah dikoreksi. Codex menambahkan validasi port/bentuk mentah URL dan dua
tes config, flag Android `allowBackup=false`, serta self-test guard CI Node22.
Hasil lokal Codex: `flutter analyze` No issues found; `flutter test`77/77;
format25 file0changed; self-test guard, `check-repo`520 file dan diff-check lulus.
Completion audit rencana Kimi akhirnya diterima pada ctx_820928d88366
(worker-show completed/succeeded); retry duplikat ditolak, tidak dihitung ulang.
Tiga butir rencana implisit dibuat eksplisit. CI/APK dan emulator tetap pending;
scope fitur/business/API/ERD tidak berubah, sehingga kontrak API/schema tidak
perlu diubah untuk fondasi ini.

### Remediasi credential12 Oktober — source dan staging isolasi PASS, rotasi OPEN

- Insiden historis DB/APP_KEY tetap open dan menghalangi data warga nyata/
  production. Pemisahan role DDL semestinya ditutup pada fondasi staging,
  tidak ditunda ke M16. Tidak mencoba ulang atau mengakali penolakan otomatis
  penulisan secret store; operator harus memberikan bukti rotasi nonsecret.
- Baseline ba3528d: reproduksi `docker compose --env-file` example eksplisit
  dan env sintetis, production+VPS `config --format json` menunjukkan key
  DATABASE_URL_UNPOOLED pada API, tiga worker, scheduler dan migrate.
  Nilai env/DSN asli tidak dibaca/dicetak dan tidak ada koneksi DB.
- Patch: key direct hanya `migrate.environment`; PHP tidak mendaftarkan
  pgsql_migrations bila URL direct tidak tersedia; TLS hanya melewati koneksi
  migrasi yang tidak tersedia, runtime tetap strict; smoke/grant menolak
  sebelum DB call. Direct smoke lewat job migrate sementara. Deploy/rollback
  memeriksa graph Compose efektif, release lama tanpa isolasi ditolak.
- Root .dockerignore menutup jalur COPY env/cache/storage privat pada semua
  stage. CI BuildKit menguji canary env/cache sintetis beserta kontrol sumber,
  logo dan contoh env yang harus tetap tersedia. Tidak mengganti website/mobile,
  aturan bisnis, schema atau kontrak HTTP/OpenAPI/ERD.
- File: infra/compose.production.yml; check_database_isolation.py;
  check-build-context.sh; deploy-vps.sh/deploy-staging-immutable.sh;
  staging-runtime-smoke.sh; .github/workflows/infra.yml; config/database.php,
  TlsPolicy, DatabaseSmoke/GrantRuntimeRole; DatabaseIsolationTest,
  TlsPolicyTest dan HealthTest; SECURITY/DATABASE/DEPLOYMENT/PROGRESS.
- Validasi lokal: Compose5.2.0 production+VPS resolved graph PASS setelah patch;
  guard --self-test PASS19 kasus bocor/misconfig dan dua kontrol valid; phpunit filter
  DatabaseIsolationTest|TlsPolicyTest PASS11 tes48 assertion. Bootstrap tetap
  menolak host nonlokal/database selain *_test; RT05_SKIP_TEST_MIGRATIONS=1
  hanya untuk focused tests tanpa DB. Tidak melakukan factory/tes Neon.
- PHP syntax empat file PASS, Bash syntax empat script PASS, Pint PASS,
  check-m03-compose PASS, check-repo538 berkas dan diff-check PASS.
  Docker CLI tersedia tetapi daemon lokal tidak jalan; tidak menghidupkan/
  memasang Docker/WSL atau mengklaim BuildKit lokal. Full API/BuildKit/image
  verifikasi menunggu CI. Investigator independen selesai; reviewer kandidat
  menemukan bypass query username/password terhadap parser Laravel. Root
  mengonfirmasi sumber dan menolak override credential, termasuk percent/
  array/NUL dan username JSON encoded; opsi TLS/channel_binding tetap valid.
  Tidak ada temuan konkret lain pada satu siklus review. Folder pengguna
  .agents/ tetap untracked.
- Pada checkpoint ini VPS masih deployment yang terakhir diverifikasi12b4642;
  source patch belum di-deploy. Status kode dan operasi live harus dipisahkan.
  Runbook rotasi kini menjaga env atomik, inventaris data terenkripsi/revokasi
  sesi, dan bukti penolakan credential lama; tidak menghapus env terlebih dulu.
- [PR18](https://github.com/morrispes5/RT-05-TAKEDA/pull/18), source2b8dbce:
  seluruh lima checks SUCCESS. [API38190853171](https://github.com/morrispes5/RT-05-TAKEDA/actions/runs/38190853171)
  PASS100 tes785 assertion, Pint, worker/Redis recovery, runtime DML/DDLdenied,
  direct migration smoke dan OpenAPI. [Infra38190853238](https://github.com/morrispes5/RT-05-TAKEDA/actions/runs/38190853238)
  PASS2m31s: BuildKit lima canary env/cache tidak ikut COPY, source/logo/examples
  tetap tersedia; kedua graph Compose resolved dan guard19 kasus PASS;
  kedua image terbangun dan HTTP image smoke PASS. Hygiene38190853200 dan
  Vercel/Preview Comments PASS. Runtime migration/grant/bootstrap sintetis
  tetap berfungsi; caller direct smoke pindah ke job, bukan container API.
  Ini bukti kode/build, belum bukti patch diterapkan atau credential dirotasi
  di VPS. Dokumen commit berikutnya tidak mengubah source yang diuji.
- Head penutupan6a8d4f8 identik source2b8 untuk kode/workflow. Semua lima
  checks terbaru SUCCESS: API38191265964 (100 tes), Infra38191265941,
  Hygiene38191265944, Vercel dan Preview Comments. PR18 merged
  `8e2d8a5393273d90ce0c2446c23e8ff9b97cb71e` pada01:19:33UTC.
- **Rollout staging aktual PASS**: hostname srv2030339 via konsol Hostinger
  yang diotorisasi. Helper blob6a8 SHA256
  e39ec37008ad630bfc46be5b3dbac94393ca94eb9e7857c04db026b3fd655f28 cocok
  dengan Git; umask077, job556073 mulai01:24:34UTC dan selesai exit0.
  Log privat `/root/rt05-security-6a8d4f8-deploy.log`. Footer menyatakan
  Selesai staging SHA6a8d4f8; graph isolasi PASS sebelum build/rollout.
  Lima runtime memakai image API yang sama; ID image
  b93025ee869d0d2eea010c597ffe57b93c2752caafcf38c5db34f1ea452deb76.
- Bukti runtime sesudah recreate: `docker exec ... printenv
  DATABASE_URL_UNPOOLED >/dev/null` pada api, worker-default, worker-media,
  worker-exports dan scheduler semuanya exit1 (key absent); tidak mencetak
  nilai. API direct smoke ditolak dengan pesan koneksi migrasi tidak tersedia,
  exit2, sebelum akses DB. DML smoke API: PG17.11/rt05_app, pooled,
  verify-full, tulis+baca ok, rollback0 dan DDLdenied.
- Kontrol operator: `compose run --rm --no-deps --pull never migrate php
  artisan rt05:db-smoke --connection=pgsql_migrations` pada tag6a8:
  rt05_owner/direct, verify-full, tulis+baca ok dan rollback0. DDL tidak
  diprobe pada job owner. Docker ps -aq filter project/service migrate kosong
  sesudah job --rm, tidak meninggalkan container DDL. Tidak menjalankan
  migration/factory/seed destruktif.
- Heartbeat aktual default1s/media2s/exports1s/scheduler59s. Pipeline
  assert_staging_images/volumes/unrelated lulus sebelum footer selesai.
  Redis-data CreatedAt2026-10-11T00:26:59Z, api-storage00:27:01Z,
  apk-downloads00:27:19Z dipertahankan. Pohon legacy empat patch tetap ada;
  service lain, DNS, port80/443 dan firewall tidak diubah.
- Probe HTTPS luar VPS: live/ready/APIv1/profil200; ready databaseok.
  Tidak merotasi DB/APP_KEY, tidak mengubah env privat existing, tidak deploy
  production atau memasukkan data warga. R08 selesai untuk source/staging;
  R07 tetap OPEN sampai bukti rotasi/revokasi nonsecret dari operator tersedia.
  Rollback ke12b yang masih membagikan migrator ditolak oleh guard; riwayat/
  image lama tidak dihapus dan tidak disebut target rollback aman.

### M05 — bukti source final dan emulator, gate PASS

- Source `13de24e230b44dd78b382260013108b5daf1d84a`, PR17. Seluruh delapan
  checks SUCCESS: Mobile38135976078 (format25 file0changed, analyzer bersih,
  77 tes dan APK dev/staging), Infra38135976074, Hygiene38135976098,
  Vercel dan Preview Comments. [CI_RESULTS.md](evidence/m05/CI_RESULTS.md).
- `gh run download`, `Get-FileHash`/scan ZIP Python, `aapt2 dump badging/xmltree`
  dan `adb install`: dua checksum cocok, aset SVG identik website, delapan
  TTF, nol pola DSN/credential yang diperiksa; allowBackup=false dan cleartext
  debug hanya host lokal. Instalasi staging Success setelah hanya paket uji
  lama kosong dihapus karena signing debug berbeda.
- Emulator Pixel_8a: startup staging health HTTPS nyata PASS, airplane/Wi-Fi/data
  offline menampilkan error; jaringan dipulihkan dan Coba lagi pada PID yang
  sama kembali health ok. UUID response tercatat di UI/logcat. Skala Android
  1,3/2,0 terbaca, scroll tombol tetap dapat dijangkau, ikon native resmi;
  tidak ada exception/overflow/crash. [LIVE_RESULTS.md](evidence/m05/LIVE_RESULTS.md)
  dan tujuh screenshot aman tanpa akun/data warga. Pengaturan emulator restored.
- Drill runtime memakai guest; preservasi token/race dibuktikan 14 session
  tests dalam77 CI, bukan autentikasi akun nyata. Tidak mengklaim korelasi
  UUID access log VPS, UAT, APK signed, SMTP/FCM atau gate M06+.
- Claude review CI PASS dan Grok review sumber mobile PASS. Kimi sesi sama
  membaca tujuh screenshot dan bukti aktual, verdict akhir PASS pada
  `task_84918b406e92` / `ctx_94fad7e0330f`. Worker_done `msg_7b7c8f755a8a`
  seq156 diterima, completed/succeeded; receipt awal agent_prompt_stalled
  tidak membuktikan limit. Sesi retained user_requested sebelum ack delivery.
  Tidak membuat retry duplikat. [KIMI_ACCEPTANCE_REVIEW.md](evidence/m05/KIMI_ACCEPTANCE_REVIEW.md).
- `check-repo`532 berkas dan `git diff --check` PASS pada checkpoint bukti.
  Perbedaan kosmetik banner STAGING dengan jam status bar dicatat di
  CAPSTONE_ALIGNMENT; source frozen, tidak ada perubahan schema/API atau deploy.
- Root menerima gate M05 berdasarkan bukti aktual dan review independen.
  Commit berikutnya hanya dokumentasi/screenshot aman; source mobile/workflow
  identik source13de24e. Sebelum merge PR17, check terbaru harus SUCCESS.
  Target sampai M05 terpenuhi secara fungsional; pekerjaan baru M06 tidak
  dibuka. Tahap berikutnya nanti: rekonsiliasi flow approval Drive/aturan bisnis
  dan gate identitas M06, dengan insiden credential historis tetap open.

10 Oktober 2026 (M01): checkout lokal pemilik berada di `Documents/RT X TAKEDA/RT-05-TAKEDA/`; folder induk `RT X TAKEDA/` berisi paket MD asli (tidak di-commit). PR #10 merged. Sesi M02: branch `codex/m02-api-neon-foundation`, PR #11. Neon dev sudah dimigrasi (5 migration fondasi). `rest-api/.env` lokal menunjuk Neon dev. php.ini lokal: pdo_pgsql/pgsql/intl/sodium diaktifkan. Tidak ada migration, image, atau job. Input belum tersedia: credential Neon, akses VPS/DNS (ditawarkan pemilik via Chrome untuk M04), SMTP, FCM, signing key, Flutter SDK.

Catat Git status, perubahan pengguna yang belum commit, migration yang sudah applied,
image/tag deploy, jobs pending, dan credential input missing tanpa menuliskan nilainya.

Jangan mengulangi provisioning/migration/deploy yang sudah berhasil hanya karena sesi baru.
Selesaikan perubahan pengguna dengan hati-hati; tidak reset/force-push.

## Bug/risiko

| ID | Severity | Domain | Repro/bukti | Milestone | Status |
| --- | --- | --- | --- | --- | --- |
| R01 | medium | website/release | `package-frontend.ps1` setelah dipindah ke `website/` menghasilkan ZIP kosong yang lolos verifikasi (filesVerified 0), lalu CRLF pada arsip subtree | M01 | fixed (350390a), diverifikasi 201/201 |
| R02 | low | docs | FRONTEND.md/SOURCES.md menyebut 34e9b21 sebagai Git tree; sebenarnya commit | M01 | fixed |
| R03 | medium | deploy preview | Root Directory Vercel belum diubah; tanpa jembatan root, merge akan memutus preview | M01 | mitigated (ADR12), menunggu pemilik |
| R04 | medium | mobile | Flutter SDK dahulu belum terpasang | M05 | resolved: Flutter3.47.7/Dart3.13.5; analyze77 tests/dua APK CI dan emulator staging lulus11 Oktober |
| R05 | high | database | pdo_pgsql named prepares + pooler Neon → transaksi gugur 25P02 pada tulis pertama | M02 | fixed (ADR14), terverifikasi di Neon pooled |
| R06 | medium | dev env | Docker Desktop tidak jalan: WSL2 belum terpasang (Windows Home) | M02/M03 | accepted limitation ADR19: build Docker/Postgres/Redis di CI, runtime staging di VPS; tidak perlu instalasi Docker/WSL lokal untuk gate ini |
| R07 | high | security | Credential DB dan APP_KEY historis terpapar; role/password diwariskan antar branch | M02/M04 | open: rotasi/revokasi semua lingkungan terdampak dan audit belum terbukti; data nyata/production blocked |
| R08 | high | security | DATABASE_URL_UNPOOLED diwariskan ke lima runtime; COPY dapat memasukkan env/cache sebelum penghapusan layer | M04 | fixed: PR18 merged, full CI100 tests/BuildKit PASS, staging6a8d4f8 runtime key absent/direct denied/DML preserved terverifikasi12 Oktober; R07 tetap open |

## Bukti historis frontend

Lihat docs/website/FRONTEND_HANDOFF.md setelah M01. Angka tes di handoff adalah bukti commit terdahulu, bukan otomatis hasil untuk HEAD yang baru.
