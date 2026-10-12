# M06 — kontrak kerja dan checkpoint

Tanggal: 12 Oktober 2026. Baseline `fb6e59f`; branch `codex/m06-account-access`.
Instruksi pemilik terbaru membuka M06 setelah M03–M05 passed. M07 hanya dibuka
setelah seluruh gate M06 terbukti. Kode M07–M12 existing bukan bukti penerimaan.

## Kontrak yang dikunci

- Nama tabel/field Indonesia ADR17 dan endpoint existing tetap kompatibel Flutter.
- `POST /auth/register`: payload existing email/password/password_confirmation,
  registration_token **string enam digit**, device_name, nama_kepala, whatsapp
  opsional, blok opsional, jalan, nomor, jenis_hunian=pemilik|kontrak, anggota.
  Respons 201 `data.token`, token_type, expires_at, akun. Akun utama langsung
  aktif/peran warga; keluarga belum_verifikasi. Tidak ada Google OAuth.
- Akun, rumah, keluarga, warga, penghunian, preferensi, audit/outbox dan token
  perangkat sukses bersama atau rollback. Rumah existing ditolak tanpa auto-merge.
  Constraint PostgreSQL dan konflik generik melindungi registrasi concurrent.
- `POST /auth/mobile-login`: existing payload dan bearer per perangkat 30 hari.
  Status menunggu hanya me/status/logout; nonaktif/diarsipkan akses ditolak.
- Penolakan tambahan mencabut bearer lama; akun tetap jenis tambahan/status
  menunggu dengan permohonan ditolak. Login ulang terbatas dapat membaca alasan
  sendiri dan logout; tidak membuka keluarga/layanan aktif. Nonaktif/arsip tetap
  kehilangan akses. Keputusan approval/rejection berulang menunggu tes gate M07.
- Password baru minimal8 karakter Unicode berisi huruf/angka, maksimal72byte
  UTF-8, tanpa NUL, pada registrasi/reset/ganti/operator. Batas byte diperiksa
  sebelum bcrypt agar hash tidak terpotong atau menghasilkan error500.
  Login tetap memvalidasi maksimal200karakter; input lebih72byte dianggap
  kredensial salah agar sufiks yang diabaikan bcrypt tidak diterima. Akun
  sintetis lama dengan password lebih72byte perlu reset.
- Konfirmasi logout-all/ganti sandi memakai kecocokan persis <=72byte tanpa
  NUL, tetapi menerima sandi lama yang lemah jika memang cocok. Reset limiter
  terpisah10/15menit per IP+email; forgot tetap3/15menit. Kegagalan antrean
  tidak membatalkan akun yang sudah committed. Bootstrap tanpa password
  noninteraktif menolak transport yang diketahui tidak dapat mengirim
  sebelum perubahan akun/audit.
- `GET /sanctum/csrf-cookie`, `POST /auth/web-login`: cookie API pengurus aktif,
  CSRF wajib, tanpa bearer pada localStorage. Logout menangani bearer dan cookie.
  Cookie session dipisahkan dari website. Reset/logout-all/status/role revoke
  harus mengakhiri sesi browser lama juga.
- Cookie CSRF API bernama `rt05_api_xsrf` agar tidak bertabrakan dengan
  `XSRF-TOKEN` website; header tetap `X-XSRF-TOKEN`. Middleware selalu memeriksa
  token, termasuk tes. `akun.versi_sesi` additive mencabut cookie lama; idle120
  menit. Scheduler catchup hourly merotasi token setelah usia7hari.
- Forgot response generik 202; notification antrean default after_commit.
  Email menggunakan tautan HTTPS ke `GET /reset-password` yang dilayani API,
  beserta kode yang dapat disalin ke aplikasi Android. Form browser mengirim
  ke `POST /api/v1/auth/reset-password`. Reset expiry/reuse ditolak; tidak ada
  token dalam log. Tidak menambah ketergantungan Android App Links terverifikasi.
- Tautan email memakai fragment `#token=...&email=...`; browser membacanya
  lokal dan mengirim POST, tanpa secret pada query yang masuk access log.
- Flutter mempertahankan API existing, secure storage, UI gapura/navy/kuning/sky,
  Public Sans/Bricolage Grotesque. Form memvalidasi dan menahan submit ganda,
  menampilkan field errors/loading/gagal/berhasil; tidak membuat data dummy runtime.
- M06 UI tambahan hanya memperjelas alur pending existing; administrasi M07 baru
  ditahan. Bootstrap operator diaudit tanpa password bawaan.

## Kepemilikan

Codex: docs/OpenAPI/ERD, integrasi, website, Stitch/Drive, Git/CI dan acceptance.
Claude: `rest-api/` dan laporan `docs/evidence/m06/CLAUDE_RESULT.md`.
Grok: `mobile/` dan laporan `docs/evidence/m06/GROK_RESULT.md`.
Kontrak yang memerlukan perubahan dibicarakan melalui mailbox Orca sebelum edit.
Hanya Codex switch/commit/push/merge. Semua data tes sintetis.

## Gate

PHPUnit PostgreSQL terisolasi (CI), concurrency proses independen, rollback,
token invalid/revoked/leading-zero/rotasi, role/status/rate limit, cookie/CSRF,
session expiry/revocation, reset expiry/reuse. Flutter analyze/widget/perilaku,
APK staging, emulator registrasi/login/logout/offline/skala 1,3 dan 2,0.
SMTP harus terbukti sampai inbox; mock tidak membuktikan delivery.
Staging health/worker dan `node infra/scripts/check-repo.mjs` harus lulus.
Credential incident tetap open; production/data warga nyata tertahan M16.
