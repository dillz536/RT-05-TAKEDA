# M07 mobile — hasil Grok

Branch `codex/m07-resident-data`, HEAD `bc57b4f`. M06 tidak diulang. Tidak ada commit, APK lokal, emulator, atau deploy staging. `docs/PROGRESS.md`, kontrak OpenAPI, dan `rest-api/` tidak diubah oleh pekerjaan ini (milik root/Claude). `.agents/` dan berkas Kimi M06 yang sudah kotor tidak disentuh. Root sudah mengirim koreksi Stitch (pesan `msg_35be…`) untuk proyek `7802748177711352102`. Screenshot dan HTML yang diunduh sesudah koreksi itu masih artefak lama; kontradiksi pada unduhan ditolak dan tidak dipakai sebagai sumber visual. Layar memakai merek yang sudah diterima di `mobile/lib/app/theme.dart` (navy `#123e65`, kuning `#f2cd5a`, sky, Bricolage Grotesque, Public Sans, logo gapura, navigasi bawah yang ada).

Infra tidak diubah. Workflow CI yang sudah ada membangun debug APK. Gradle/NDK lokal tidak dijalankan.

## Yang terpasang

Tombol ubah di panel keluarga memakai `dapat_ubah` dari GET /household yang baru bersama status akun `aktif`. Nilai yang hilang atau false berarti baca saja. Kemampuan sesi `dapat_ubah_keluarga` di `mobile/lib/core/models.dart` tidak lagi menentukan tombol itu, karena /auth/me bisa basi setelah pindah akun utama atau pindah anggota. Sesi lama yang masih true dengan keluarga baru false tidak menampilkan ubah, tambah, atau buka anggota. Kebalikannya, sesi lama false dengan `dapat_ubah` true menampilkan tombol ubah.

`PATCH /household` serta tambah, ubah, dan arsip anggota mengirim `versi`. Jika server membalas `VERSION_CONFLICT`, formulir tetap terbuka, teks yang diketik tidak dibuang, dan kalimat konflik ditampilkan utuh: pesan server ditambah "Teks yang Anda ketik tetap ada. Periksa data terbaru, lalu kirim lagi." Aplikasi memuat ulang sumber, memperbarui hanya `versi` dan baris "Data terbaru", lalu pengguna mengirim lagi. Tanpa versi, formulir menolak kirim dan meminta muat ulang. Respons 403 (`PRIMARY_ACCOUNT_REQUIRED`, "Hanya akun utama yang dapat mengubah data keluarga.") juga mempertahankan nama, WhatsApp, dan isian anggota, menampilkan pesan server utuh, lalu menyegarkan GET /household dan GET /auth/me tanpa menghapus token. Setelah keluarga baru `dapat_ubah` false, panel tidak lagi menampilkan tombol ubah. Akun menunggu tetap di layar terbatas. Pesan validasi nama, hubungan, dan WhatsApp berada di lebar daftar, bukan di sisa sempit di samping penghitung karakter.

Pengurus, hak setara, tanpa kirim peran dan tanpa editor peran:

- Rumah: daftar, buat, detail, tagihan dengan `versi`, arsip, riwayat hunian. Ubah alamat (`blok`, `jalan`, `nomor`, `alasan`, `versi`) terpisah dari ubah pemilik (`pemilik` atau `null` untuk lepas, plus `alasan` dan `versi`). Kode rumah tidak diubah. Alamat tidak mengirim pemilik atau tagihan. Pemilik tidak mengirim alamat. Mulai, akhiri, dan pindah hunian memakai `penghunian_aktif_id` atau id riwayat yang masih berjalan.
- Keluarga: daftar, buat (`POST /admin/families`: nama kepala wajib, hubungan dan WhatsApp opsional, anggota opsional, rumah opsional), detail, verifikasi dengan `versi`, arsip, ganti kepala, tambah anggota pengurus dengan `versi`, ubah anggota, akhiri keanggotaan dengan `versi`. Tanggal hunian kosong tidak dihitung di klien; server memakai hari ini di Asia/Jakarta.
- Pindah akun utama: `POST /admin/families/{id}/primary-account` dengan `akun_id`, `versi`, dan `alasan`, setelah konfirmasi. Calon adalah tautan aktif yang bukan utama dan bukan akun menunggu atau diarsipkan.
- Pindah anggota: `POST /admin/residents/{id}/move` dengan `keluarga_id`, `hubungan_keluarga`, `alasan`, dan `versi` keluarga asal, setelah konfirmasi. Kepala tidak dipindahkan dari formulir ini. Anggota yang punya akun dapat pindah; keanggotaannya tidak diakhiri sebelum akun dipindah. Keluarga asal tidak muncul sebagai tujuan.
- Akun: saring, nonaktifkan atau aktifkan kembali, arsip final untuk warga lain, serta riwayat akun (`GET /admin/accounts/{id}`: tautan dan permohonan). Tidak ada kata sandi, token, atau ubah peran. Salinan di riwayat: setiap pengurus memiliki hak yang sama.

Daftar rumah, keluarga, akun, pilihan rumah pada catat iuran, dan pemilih hunian memakai `page` serta `per_page` 100. Jika `meta.last_page` lebih besar dari halaman yang dimuat, tombol "Muat halaman berikutnya" meminta halaman berikutnya dan menambahkan baris. Footer menampilkan "Menampilkan X dari Y." Halaman tidak diambil semua sekaligus. Fixture lama tanpa `last_page` dan dengan total sama dengan jumlah baris tidak menampilkan tombol.

409 yang bukan konflik versi (rumah sudah ada, rumah masih dihuni, dan kode bisnis lain) tetap di formulir dengan pesannya sendiri dan tidak dianggap basi. Salinan pengguna berbahasa Indonesia. Tidak ada panel API, NIK, pembayaran online, QRIS, atau dompet.

## Cakupan sumber

Layar pengurus memakai kontrak yang sudah ada di `docs/evidence/m07/CONTRACT.md` dan controller `rest-api/app/Http/Controllers/Admin/ResidentsController.php`. Path akun hidup adalah `/admin/accounts`. Tidak ada aksi admin M07 pada kontrak itu yang sengaja ditunda.

| Aksi | Layar |
| --- | --- |
| `POST /admin/families` | `FamilyCreatePage` |
| `POST /admin/families/{id}/members` | `AdminAddMemberPage` |
| `POST /admin/families/{id}/primary-account` | `TransferPrimaryPage` |
| `POST /admin/residents/{id}/move` | `MoveResidentPage` |
| `PATCH /admin/houses/{id}` alamat | `HouseAddressPage` |
| `PATCH /admin/houses/{id}` pemilik | `HouseOwnerPage` |
| `GET /admin/accounts/{id}` | `AccountDetailPage` |
| Halaman daftar berikutnya | `AppendedPage` |

## Berkas mobile

Baru pada pekerjaan M07:

- `mobile/lib/features/household/paged_list.dart`
- `mobile/lib/features/household/admin_edits.dart`
- `mobile/test/admin_edits_test.dart`
- `mobile/test/support/readable_text.dart` — pemeriksaan `RenderParagraph.didExceedMaxLines` dan gulir sampai pesan bisa diketuk.

Diubah dan tetap dipakai:

- `mobile/lib/core/models.dart` — getter `dapatUbahKeluarga` sudah ada di pohon dan terlewat dari daftar laporan sebelumnya. Putaran ini tidak mengedit berkas itu lagi. Panel keluarga tidak membaca getter tersebut.
- `mobile/lib/features/household/census_providers.dart` — kueri membawa `page`; akun mengembalikan satu halaman.
- `mobile/lib/features/household/family_actions.dart` — `canEditHousehold` memakai status aktif dan `dapat_ubah` baru; konflik mempertahankan `versi`; badan keluarga, pindah akun, pindah anggota, alamat, dan pemilik terpisah.
- `mobile/lib/features/household/family_page.dart` — tombol ubah dari data keluarga baru; 403 menyegarkan rumah tangga dan sesi tanpa membuang draf; pesan validasi selebar daftar.
- `mobile/lib/features/household/admin_links.dart` — keluarga baru, tambah anggota, pindah akun, pindah anggota, konflik pengurus.
- `mobile/lib/features/household/admin_census.dart` — ubah alamat, ubah pemilik, riwayat akun, muat halaman.
- `mobile/lib/features/pengurus/pengurus_pages.dart` — daftar rumah dan pilihan rumah catat iuran memakai halaman.
- `mobile/lib/core/api.dart`
- `mobile/lib/features/warga/warga_pages.dart`
- `mobile/test/support/fakes.dart` — fixture keluarga memuat `dapat_ubah`.
- `mobile/test/family_actions_test.dart`
- `mobile/test/family_flow_test.dart`
- `mobile/test/admin_links_test.dart`

Bidang pengurus dan halaman daftar yang sudah terpasang tidak dicabut.

## Uji

`admin_edits_test.dart` membuktikan badan persis tanpa `peran` atau `role`, izin tombol, halaman 2 dari meta, riwayat akun tanpa kontrol peran, dan formulir alamat 320px dengan skala teks 2.0. Kalimat konflik 409 digulir sampai terlihat, lalu `RenderParagraph.didExceedMaxLines` false dan lebar teks di dalam layar. Jalan dan alasan tetap; kirim ulang memakai `versi` baru. `family_flow_test.dart` membuktikan sesi true + `dapat_ubah` false baca saja, sesi false + `dapat_ubah` true bisa ubah, konflik 409 dan 403 mempertahankan isian, serta validasi nama/hubungan/WhatsApp. Pemeriksaan 320px skala 2.0 membaca paragraf yang terlukis, bukan `maxLines` pada konfigurasi widget. `family_actions_test.dart` membuktikan pemisahan payload dan aturan tutup saat `dapat_ubah` hilang atau false. `admin_links_test.dart` membuktikan formulir anggota yang basi tetap terbuka dan pesan 409 yang sama terlukis utuh pada 320px skala 2.0.

## Perintah

Dari `mobile/`, Flutter `C:\Users\USER\dev\flutter\bin`. Lebar format 160.

- `dart format --line-length 160 lib test` — exit 0. `Formatted 40 files (0 changed) in 0.23 seconds.`
- `flutter analyze` — exit 0. `No issues found! (ran in 14.7s).`
- `flutter test` — exit 0. `00:15 +152: All tests passed!`

Dari root:

- `node infra/scripts/check-repo.mjs` — exit 0. `OK: 730 berkas dilacak; tanpa file terlarang, pola secret, .git bersarang, atau tautan relatif rusak; vercel.json setara.`

## Batas

Tidak ada commit. Tidak ada APK, emulator, atau penerimaan staging. `docs/PROGRESS.md` tidak diperbarui. Koreksi Stitch sudah diterima, tetapi screenshot dan HTML unduhan masih artefak lama sehingga tidak dijadikan bukti visual. Root yang membekukan sumber, menjalankan CI, dan uji perangkat Android setelah putaran ini. Daftar memuat 100 baris per permintaan; halaman berikutnya hanya dimuat saat pengguna menekan tombol.
