# UI akses M06 dan rancangan administrasi M07

Sumber desain: proyek Stitch `7802748177711352102`, RT05 Penyempurnaan Website,
baseline yang diterima serta theme Flutter. Logo SVG gapura, Public Sans dan
Bricolage Grotesque terbundel; navy/kuning/sky dipertahankan.

Generasi layar akses baru diminta melalui Stitch12Oktober; tool timeout setelah
300detik. Listing berikutnya masih40 layar existing, tanpa layar M06 baru.
Tidak mengulang generation atau menganggap timeout sebagai hasil berhasil.
Implementasi Flutter mengikuti brand existing dan spesifikasi keadaan berikut;
screenshot aplikasi nyata dibedakan dari referensi Stitch.

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
