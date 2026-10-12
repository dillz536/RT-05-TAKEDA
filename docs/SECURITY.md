# Keamanan, privasi, dan batas akses

## Trust boundary

Browser/Flutter adalah client yang tidak dipercaya. API memverifikasi actor, role, status, resource ownership, dan payload. UUID, tombol tersembunyi, atau route admin tidak menggantikan policy server.

Neon/Redis/storage hanya diakses backend/worker. Source repo bersifat public sehingga seluruh artifact harus aman dibaca publik.

## Identitas

- Password memakai hashing Laravel yang sesuai runtime, bukan enkripsi reversible.
- Sanctum menyimpan API token dalam hash. Token mobile di OS secure storage, tidak log/localStorage/plain preferences.
- Expiry mobile v1 30 hari; reauth saat expired; logout/recovery/status inactive mencabut akses.
- Session CMS HttpOnly/Secure/SameSite=Lax, expiry idle 120 menit, CSRF wajib. Cookie API dan web shell mempunyai nama berbeda.
- Email dinormalisasi; respons register/reset tidak mengungkap daftar warga terdaftar.
- Token registrasi six-digit mempunyai entropy terbatas: rate limit, rotasi, logging aman, dan validasi membership diperlukan. Token valid tidak memberi hak admin.
- Nilai invite terenkripsi agar admin dapat melihat/salin; digest untuk validasi. Akses no-store dan audit tanpa token plaintext.
- Command grant/revoke admin hanya operator berotorisasi, dengan audit. Tidak menerima role dari request registrasi/PATCH user.

## Policy resource

Test warga A tidak dapat mengubah laporan milik B, membaca keluarga B, mengunduh ekspor admin, melihat token registrasi, atau mengubah iuran/kas. Admin setara hak bisnisnya, tetapi setiap tindakan tercatat actor/time/reason.

Akun tambahan pending tidak mendapat feed/keuangan/data warga. Akun inactive ditolak walaupun token belum kedaluwarsa. Role diperiksa ulang API; jangan mempercayai cached role APK.

## Anonimitas laporan

Resource serializer warga menghilangkan author_user_id/name/email/contact serta indirect link. Notifikasi/ekspor/media URL tidak mengungkap identitas. Admin resource dapat memuat identitas sesuai hak.

Foto mungkin memperlihatkan rumah/wajah; UI memberi pengingat singkat sebelum publish ke warga. Pilihan menyembunyikan nama tidak menjanjikan foto/deskripsi sepenuhnya anonim.

## Data pribadi

V1 tidak meminta NIK, KTP, nomor KK, rekening, atau GPS. Warga hanya membaca keluarga sendiri. Transparansi keuangan memakai kode rumah/status, bukan data anggota keluarga.

Setiap environment nonproduction memakai data sintetis atau anonymized yang disetujui. Dilarang clone branch Neon berisi PII production ke dev public tanpa sanitasi dan otorisasi.

Arsip digunakan untuk histori administrasi, bukan penyimpanan PII tanpa batas. Kebijakan retensi final harus ditetapkan sebelum data nyata masuk; default operasional v1:

- Temporary uploads orphan: 24 jam.
- Export files: 24 jam.
- Access/application logs nonsensitive: 14 hari.
- Notifikasi inbox: minimal 12 bulan, dapat diarsipkan.
- Audit/ledger: tidak auto-delete v1; jadwal retensi harus ditetapkan pemilik/mitra.
- Identitas warga pindah: nonaktifkan akses segera, pertahankan histori minimum yang diperlukan; privacy request ditinjau dan pseudonymized jika memungkinkan tanpa merusak ledger.

Privacy notice menjelaskan tujuan data, pihak pengelola, hak akses, retensi, kontak nyata, dan pengajuan koreksi. Jangan mengarang alamat kontak privasi untuk memenuhi placeholder.

## Konten dan upload

Server memverifikasi magic bytes/MIME, ukuran, megapiksel, decompression limits, owner/purpose, dan referensi parent. Hanya JPG/PNG/WebP untuk upload foto v1. Disallow HTML/SVG/script/remote URL arbitrary.

Transcode server membuang metadata, memakai timeout/memory limits; file private tidak berada di document root. Public media tidak mengizinkan directory listing. Nama file random UUID.

Teks user escaped; rich text jika ditambahkan memakai allowlist sanitizer. URL sumber artikel hanya http/https; tidak javascript:/data: dari input. CSV mencegah formula injection untuk field yang diawali =,+,-,@, tab/CR.

## Secrets

APP_KEY, DATABASE_URL, direct database role, Redis credentials, SMTP, FCM service account, APK signing, backup encryption, dan SSH key berada secret manager/env runtime.

Commit hanya .env.example berisi placeholder. Jangan print credential saat debugging. Redact Authorization/Cookie/token/body PII dari logs. Jangan menyimpan API token dalam OpenAPI examples.

APP_KEY/encryption key harus dibackup aman. Kehilangan key dapat membuat invite terenkripsi tidak terbaca; rotasi key memerlukan prosedur decrypt/re-encrypt serta session handling.

## Infrastruktur

HTTPS wajib; trusted proxy dibatasi network aktual, bukan seluruh internet. Origin produksi sama dengan API; CORS default deny origin asing; tidak memakai wildcard+credentials.

Port public hanya reverse proxy 80/443 dan SSH sesuai policy host. Redis/FPM/internal API tidak diekspos. Tidak mengubah firewall yang digunakan service lain.

DB runtime role minimal DML; migration role DDL hanya job. TLS Neon diverifikasi. Container nonroot bila kompatibel, filesystem read-only kecuali volume writable yang ditentukan.

API, tiga worker dan scheduler tidak menerima `DATABASE_URL_UNPOOLED` atau
salinannya lewat alias/command. Job migrasi memiliki env tersendiri;
`pgsql_migrations` tidak tersedia pada proses runtime tanpa URL direct.
Tidak memasukkan `.env`, cache konfigurasi PHP atau storage privat ke build
context; menghapus secret pada layer berikutnya bukan bukti secret tidak
pernah masuk image. Deployment memeriksa konfigurasi Compose yang sudah
di-resolve; rollback ke release yang belum memiliki isolasi ditolak.

Insiden DB/APP_KEY historis tetap terbuka hingga bukti rotasi/revokasi pada
semua lingkungan terdampak diterima. Patch isolasi dan build CI tidak merotasi
credential yang sudah terpapar. Data warga nyata dan production tetap blocked
oleh insiden ini; status passed M04/M05 adalah penerimaan fungsional.

## Release gates

IDOR/role/CSRF/anonim/resource media/CSV abuse tests lulus; secrets scan; dependency review; public preview tidak mengandung PII; session revoked diuji; backup encryption/restore diuji; privacy notice final; seluruh blocker data nyata resolved.

Temuan kritis/high terkait akses data atau integritas uang menghalangi production. Temuan desain/accessibility dicatat dengan dampak dan solusi konkret; tidak boleh mengklaim aman hanya dari satu automated scanner.
