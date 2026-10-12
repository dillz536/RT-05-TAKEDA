# Review album — 12 Oktober 2026

Sumber: folder DOKUMENTASI RT 05 pada Drive. Listing connector: **40 foto JPEG
dan 2 video MP4**. Seluruh foto diunduh untuk review ke folder privat di luar
repo; empat contact sheet diperiksa visual. Enam foto terpilih diperiksa juga
pada resolusi asli. Foto asli tidak masuk Git/arsip. Tidak ada berkas asli dihapus.
Contact sheet hanya di folder laporan Drive, bukan repository publik.

Metadata: seluruh40 JPEG tanpa EXIF; SHA256 menunjukkan foto23 identik dengan21.
Nama file WhatsApp/timestamp upload tidak dipakai sebagai tanggal kegiatan.
Turunan WebP480/960/1280 kualitas76 dibuat melalui sharp, tanpa metadata.
Tanggal/tahun semua album tetap null. Caption/alt mendeskripsikan yang terlihat;
nama orang/lokasi kegiatan tidak ditebak.

| Nomor contact sheet | Keputusan | Alasan / album |
| --- | --- | --- |
| 01,02,04 | ditahan | Variasi portrait kelompok hijau; landscape03 lebih representatif |
| 03 | dipilih | Foto barisan kelompok di lapangan; cover Kebersamaan di Lapangan |
| 05 | dipilih | Foto kelompok dengan gawang/ring; Kebersamaan di Lapangan |
| 06 | ditahan | Banyak ruang langit; varian05 lebih jelas |
| 07 | ditahan | Miring dan objek kecil; kegiatan spesifik belum dikonfirmasi |
| 08,11 | review | Pertemuan kelompok berpakaian putih; konteks kegiatan/privasi belum dikonfirmasi |
| 09 | dipilih | Foto kelompok di sisi bus; Kebersamaan di Ruang Terbuka |
| 10 | dipilih | Foto kelompok di area hijau; cover Kebersamaan di Ruang Terbuka |
| 12,13,14,15,16,18,19 | review | Pertemuan di rumah/interior pribadi; konteks dan kelayakan publikasi belum dikonfirmasi;14 berpita hitam |
| 17 | review | Kelompok pada interior; nama/konteks tempat belum dikonfirmasi |
| 20,22 | dipilih | Varian panggung merah putih; tambah album Perayaan17Agustus tanpa tahun |
| 21 | baseline | Foto panggung sudah menjadi aset album accepted; tidak ditambahkan dua kali |
| 23 | duplikat | Byte identik21; tidak dipublikasikan ulang; asli tetap di Drive |
| 24,25,26,27,30,32,33,34,35,36,38 | baseline | Foto lingkungan/fasilitas sudah dipakai website accepted; tidak membuat album kegiatan tambahan dari foto fasilitas |
| 28,29,37,39,40 | review | Anak terlihat di area bermain/lapangan; hindari tambahan publikasi tanpa review |
| 2 video | ditahan | Video bukan fitur website tahap ini; tidak diunduh/ditranscode |

Hasil konten: Perayaan17Agustus tiga foto (satu baseline + dua tambahan),
Kebersamaan di Lapangan dua foto, Kebersamaan di Ruang Terbuka dua foto.
Layout/logo/font/palette/heroslideshow diterima tetap dipertahankan.

## Perintah dan verifikasi

- Dari `website/`: `node C:/Users/USER/.rt05/m06-photo-review/publish-albums.mjs`
  menghasilkan18 WebP (enam foto × tiga lebar), update sumber album.json.
- `npm.cmd run build:preview`: exit0,21 rute diekspor termasuk dua slug baru.
- `npm.cmd run test:preview`: exit0;105 kasus responsif,45 Axe+3 editor,
  37 tautan, nol error browser; interaksi/editor/slideshow PASS. Dua konteks
  browser terpisah membuktikan draf lokal tidak memengaruhi album publik.
- `php artisan test --compact`:9 tes/173 assertions passed.
- Publikasi online dan browser independen dicatat terpisah dari build lokal.
