# M06 — menyiapkan pengirim email

Pemilik mengonfirmasi12Oktober2026 bahwa pengirim belum pernah disiapkan.
Alamat penerima percobaan sudah disimpan privat. Reset sampai inbox tetap
blocked sampai pengirim terhubung dan email nyata diterima.

## Pilihan Gmail jika pemilik memilihnya

Gunakan akun pengirim yang dipilih pemilik. Aktifkan verifikasi dua langkah
lalu buat App Password untuk RT05 TAKEDA melalui pengaturan akun Google.
Ketersediaan App Password bergantung pada kebijakan akun. Petunjuk resmi:
[Google Account](https://support.google.com/accounts/answer/185833?hl=en).
Jangan mengirim password Gmail atau App Password lewat chat/repository.

Operator memasukkan nilai privat ke `/root/.rt05/.env.staging` yang mode600
melalui mekanisme privat milik operator. Konfigurasi nonsensitive:

```dotenv
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_SCHEME=smtp
MAIL_FROM_NAME="RT05 TAKEDA"
```

`MAIL_USERNAME` dan `MAIL_FROM_ADDRESS` memakai alamat pengirim terpilih;
`MAIL_PASSWORD` memakai App Password. Transport smtp pada port587 menaikkan
koneksi melalui STARTTLS. Rujukan host/port/auth:
[Gmail Help](https://support.google.com/mail/answer/7104828?hl=en).
Compose meneruskan key tersebut ke API dan worker. Setelah env valid disiapkan,
operator recreate API dan worker-default pada SHA yang sudah diverifikasi agar
worker membaca konfigurasi baru; rollout kode yang sudah PASS tidak perlu diulang.
Tidak perlu menambah Google OAuth untuk akun warga.

## Bukti penerimaan yang masih diperlukan

1. Akun sintetis staging meminta reset dengan alamat inbox uji yang diizinkan.
2. Worker-default memproses antrean; email tiba pada inbox, bukan hanya log.
3. Link HTTPS membuka form, reset berhasil, login sandi baru berhasil dan sesi
   lama ditolak. Kode yang sama ditolak ketika dipakai ulang; expiry juga diuji.
4. Simpan timestamp, SHA dan screenshot aman tanpa alamat privat/kode/secret.

Gangguan email tidak membalik transaksi akun. Forgot202 generik, mock adapter,
APK sukses, dan health worker tidak membuktikan delivery. Jika App Password
tidak tersedia, operator memilih pengirim SMTP lain yang benar-benar dimiliki;
endpoint dan credential tidak ditebak. Production tetap mengikuti gate M16.

## Checkpoint pemulihan malam12 Oktober

Staging26a0b7e/runtime/API/Android sudah diverifikasi. Env staging tetap
MAIL_MAILER=log tanpa pengirim SMTP; tidak ada email nyata dikirim. Pembacaan
metadata Resend existing pada proyek lain menghasilkan HTTP401, tanpa menyalin
key atau memakainya untuk RT05. Akun Chrome belum cocok dengan inbox privat.
Input yang diperlukan adalah login pengirim yang benar atau konfigurasi SMTP
RT05 valid, bukan izin VPS baru. Credential tidak ditulis lewat chat/Git.

Jika memilih Resend, gunakan key khusus RT05 dengan izin sending-only dan
domain yang terverifikasi. Host smtp.resend.com, username resend, port587 dan
MAIL_SCHEME=smtp untuk STARTTLS; password tetap privat. Referensi resmi:
[SMTP](https://resend.com/docs/send-with-smtp),
[izin API key](https://resend.com/docs/api-reference/api-keys/create-api-key).
Tidak membeli layanan atau mengubah pengirim proyek lain.

Helper privat inbox-flow.mjs sudah diperiksa sintaks, belum dijalankan.
Urutan penerimaan: precheck, prepare akun sintetis, forgot rondeA, konfirmasi
email inbox dan reset lewat tautan browser, verifyA, lalu rondeB lewat Android
dan verifyB. Forgot202 atau worker DONE sendiri tidak membuktikan inbox.
Expiry/reuse/race sudah diuji mekaniknya; tidak perlu mengulang drill runtime.
