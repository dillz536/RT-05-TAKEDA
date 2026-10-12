# UI akses M06 dan rancangan administrasi M07

Sumber desain: proyek Stitch `7802748177711352102`, RT05 Penyempurnaan Website,
baseline yang diterima serta theme Flutter. Logo SVG gapura, Public Sans dan
Bricolage Grotesque terbundel; navy/kuning/sky dipertahankan.

Generasi layar akses M06 sebelumnya timeout300detik; hasil itu tetap tidak
dianggap berhasil. Pada pemulihan sesi malam, permintaan baru referensi M07
menghasilkan `3f36e8c8361c4de78df370e412c79b0e`, lalu koreksi
`e267c2b12d8b4021861da1cda87716e8` (MOBILE780×3256), diverifikasi get_screen.
Listing proyek tetap mengembalikan40layar existing; tidak mengklaim inventaris
lengkap berubah. Pratinjau URL default hanya123×512; pemeriksaan visual memakai
unduhan ukuran penuh780×3256. Dua permintaan koreksi berikutnya mengembalikan
narasi tetapi screenshot tidak berubah.

Referensi hanya membantu hierarki kartu. Status akun utama yang masih tertulis
menunggu admin, istilah JWT, logo gambar ulang, serta panel spesifikasi teknis
tidak sesuai kontrak/brand dan tidak diterapkan. Data keluarga belum diverifikasi
harus dibedakan dari akun utama aktif. Implementasi Flutter tetap mengikuti brand
existing dan spesifikasi berikut; screenshot aplikasi nyata dipisahkan dari Stitch.

| Layar | Keadaan yang wajib terlihat | Tindakan |
| --- | --- | --- |
| Login | Guest, validasi email/password, request aktif, error kredensial, jaringan gagal, sesi berakhir | Password RT05 TAKEDA; bukan Google OAuth; tombol dan keyboard tidak submit ganda |
| Daftar utama | Token6digit, data alamat/keluarga, error tiap anggota, benturan rumah, loading | Akun aktif sesudah transaksi berhasil; keluarga belum_verifikasi; tidak auto-merge |
| Daftar tambahan | Referensi alamat, validasi, loading, berhasil pending | Tidak membuka keluarga/layanan sampai disetujui |
| Status tambahan | Loading, gagal/retry, diajukan, ditolak+alasan, disetujui | Periksa lagi menyegarkan akun; role warga; tambahan read-only keluarga |
| Lupa password | Email, validasi, loading, respons generik | Pesan mengatakan permintaan diterima; tidak menjamin inbox tiba |
| Reset password | Email+kode dari email, password baru+konfirmasi, error expiry/reuse | Konfirmasi benar sebelum submit; sukses kembali login |
| Password/logout-all | Password saat ini, konfirmasi baru; loading/gagal/sukses | Server mencabut sesi; offline tidak mengklaim server berhasil; respons lama tidak mengakhiri sesi baru |
| Token pengurus | Empty/loading/gagal, kode leading-zero, copy success, rotate | Akses pengurus; tanpa token pada screenshot publik/log |
| Administrasi M07 | Daftar kosong/filter/loading/error, validasi, verifikasi/arsip, review repeated, konflik hunian | Rancangan saja sebelum gate M06; API final menentukan status |

Form berada pada area scroll, keyboard tidak menutup tombol terakhir. Label status
disertai teks, target sentuh minimal44px. Uji lebar320/360 dengan skala1,3/2,0,
server field errors, disposal/mounted dan pemulihan jaringan. Screenshot emulator
harus memakai akun sintetis dan menyembunyikan password/token/email reset.
