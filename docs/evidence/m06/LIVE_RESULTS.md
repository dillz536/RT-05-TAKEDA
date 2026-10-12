# M06 — pemeriksaan perangkat dan staging

12 Oktober2026. Paket backend/Flutter koreksi review:
`15885cbf2d08b3e0f2b66b32899e9e6857ff63d1`.
CI API184tes/1679assertions, Flutter114tes/analyze/format dan kedua APK SUCCESS.
Tautan/checksum di [CI_RESULTS.md](CI_RESULTS.md).

## Android pada source15885cb

APK staging diperiksa checksum dan sertifikat AndroidDebug, kemudian dipasang
di `emulator-5554` (Pixel8a,1080x2400,density420). Package guest M05 diganti
karena sertifikat debug CI berbeda; package lain tidak diubah. Ini APK debug,
bukan rilis signing key production.

| Pemeriksaan nyata | Hasil | Batas bukti |
| --- | --- | --- |
| Start dan health HTTPS | PASS;masuk guest, logo gapura/brand | Backend existing belum rollout M06 |
| Masuk dengan form kosong | PASS;pesan email/password muncul | Tidak membuktikan login akun |
| Form utama/tambahan | PASS;bisa dibuka, penjelasan active/unverified dan pending berbeda;submit kosong ditahan | Tidak membuat akun di staging |
| Skala1,3 dan2,0 pada masuk | PASS;scroll mencapai kedua tombol daftar | Bukan bukti layar pengurus/keluarga |
| Form reset | Permintaan untuk alamat sintetis example.invalid membuka form kode/password;validasi kosong muncul | Backend existing, tanpa email nyata;tidak menyatakan reset berhasil |
| Skala2,0 error reset | GAGAL visual;aturan password memakai ellipsis | Koreksi Grok task_b2f9f3619c32,verifikasi APK baru diperlukan |
| Guest offline/retry | PASS;airplane+wifi/data off menampilkan offline;sesudah network pulih Coba lagi kembali masuk dengan PID sama | Belum membuktikan sesi login tersimpan/logout offline |
| Log proses aplikasi |0fatal,0UnhandledException,0RenderFlexoverflow pada pemeriksaan | Error tekstual yang terpotong tidak menghasilkan RenderFlex exception |

Font scale dikembalikan1,0;airplane0,wifi1,data1. Tidak memasukkan password
atau token registrasi/reset ke screenshot. Alamat pada gambar reset adalah
example.invalid sintetis, bukan alamat inbox penerima yang disimpan privat.

Screenshot yang ditinjau visual:

- [Masuk](screenshots/login-15885cb.png)
- [Masuk skala1,3](screenshots/login-scale13-15885cb.png)
- [Masuk skala2,0 setelah scroll](screenshots/login-scale20-15885cb.png)
- [Temuan error reset sebelum koreksi](screenshots/reset-scale20-before-fix.png)
- [Guest offline](screenshots/guest-offline-15885cb.png)
- [Guest kembali online](screenshots/guest-retry-15885cb.png)

## Staging dan blocker

Konsol Hostinger yang sebelumnya dapat digunakan sekarang kedaluwarsa;
Chrome menampilkan login Hostinger. Pemilik diminta login dan membuka Konsol
VPS kembali. Script immutable yang diunduh memiliki SHA256 sesuai blob sumber,
tetapi **belum dieksekusi**. Pemeriksaan public health09:21UTC200/ready dengan
databaseok hanya membuktikan runtime existing masih sehat.

Bootstrap pengurus sintetis dan helper verifikasi API/browser telah disiapkan
privat di luar Git. Belum ada registrasi utama, approval/rejection, login
pengurus/warga, keluarga, cookie/CSRF atau header reset pada **rollout M06**
yang terbukti di staging/emulator. PHPUnit HTTP/role/concurrency tidak
menggantikan penerimaan live tersebut.

Pemilik mengonfirmasi pengirim email belum pernah disiapkan. Penerima uji
tersedia privat. [SMTP_OPERATOR.md](SMTP_OPERATOR.md) memberi langkah konkret;
bukti worker mengirim sampai inbox serta link/kode reset sekali pakai masih
blocked. Stitch baru juga belum tersedia sesudah timeout/listing40existing.
M06 belum passed; implementasi baru M07 terkunci. Production/data warga nyata
dan insiden credential tetap mengikuti gate M16.
