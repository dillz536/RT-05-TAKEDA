# Referensi UI M07 — Google Stitch dan aplikasi aktual

13 Oktober 2026. Proyek Stitch existing `7802748177711352102`, layar
`e267c2b12d8b4021861da1cda87716e8` (Keluarga Saya & Histori Hunian).

Coordinator membaca proyek/layar dan meminta koreksi melalui `edit_screens`.
Provider mengembalikan event `project.file_update` dan koreksi teks/DOM:
akun utama **Aktif**, status keluarga belum diverifikasi terpisah, rumah kontrak
dan anggota berupa placeholder, tambahan disetujui hanya membaca, histori tanpa
tanggal karangan, tanpa panel JWT/API/NIK/pembayaran online.

Namun `get_screen` dan unduhan screenshot/HTML sesudah event masih memuat
artefak lama: status utama menunggu konfirmasi, panel arsitektur/JWT dan logo
buatan. Koreksi visual hasil unduhan **belum terverifikasi**; tidak dianggap
desain final ataupun bukti fitur berjalan. Permintaan layar baru sebelumnya
timeout300detik dan tidak diulang; tidak ada layar baru yang diklaim berhasil.

Grok menerima batas ini melalui Orca. Implementasi menggunakan hierarki kartu
keluarga/anggota/histori dan aturan yang sudah benar, sambil mempertahankan
brand Flutter accepted: Bricolage Grotesque, Public Sans, logo gapura existing,
navy `#123e65`, kuning `#f2cd5a`, sky `#eef5fa`. Label teknis milestone tidak
diperlukan oleh pengguna produk. Bukti penerimaan berasal dari APK yang cocok
dengan source, tes rendering320px/skala2 dan tangkapan layar Android nyata.
