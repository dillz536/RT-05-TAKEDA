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
Compose meneruskan key tersebut ke API dan worker. Operator melakukan rollout
staging pada SHA yang sudah diverifikasi agar worker membaca konfigurasi baru.
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
