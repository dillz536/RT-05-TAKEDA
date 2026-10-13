# Pembaruan Drive M07: draf bagian dokumentasi untuk Google Doc yang sudah ada

- **Penulis draf ini: Claude**, 13 Oktober 2026. Tugas ini semula diberikan kepada Kimi (`task_0eedd9132c2c`). Kimi sempat membaca konteks, tetapi provider menjawab HTTP 403 karena kuota lima jam habis, sehingga tidak ada keluaran. Terminal Kimi lalu dihentikan oleh root. Panduan utama M06 di Google Doc tetap karya Kimi. Draf M07 di bawah ini **bukan** karya Kimi.
- Lingkup file: hanya `docs/evidence/m07/drive/` dan dokumen ini. Draf ini tidak mengubah kode aplikasi, kontrak/OpenAPI, infra, PROGRESS, Git, atau cloud (Drive, staging, Neon). Lima PNG suplemen M06 tidak diubah; hash SHA256-nya tetap sama.
- Root yang menyisipkan isi ke **Google Doc native yang sudah ada**: `18yoO1-BY7-ylFyujpkDrpp_NkPQOwq-9-mTRPK_-KlM` di folder `1_WweESw5u0iHaDlw6LUfFAuUgOzAstpj`. Saat draf pertama dibuat, Doc itu berisi 26 halaman, 21 gambar, dan 4 tabel. Jangan membuat dokumen atau folder baru.

## Revisi 2 (keterbacaan Doc native)

Root memeriksa gambar 01 dan 05. Di ukuran asli, isinya terbaca. Namun teks terkecil 18 px hanya sekitar 4,0 pt setelah gambar ditempel selebar 425 pt, terlalu kecil untuk panduan manusia. Perubahannya:

1. **Lima gambar pendamping baru** (`06`–`10`) untuk ditempel di Doc. Semua teks berukuran ≥ 44 px, sekitar 9,8 pt pada lebar 425 pt, termasuk judul, label, kardinalitas, dan catatan kaki. Kata dikurangi; penjelasan dipindahkan ke prosa native.
2. **Lima gambar teknis asli** (`01`–`05`) tetap disimpan sebagai file ukuran penuh. Gambar ini tidak ditempel, tetapi ditautkan dari prosa. Isinya dikoreksi:
   - Permohonan **ditolak** tidak mengubah status akun menjadi "ditolak". Status permohonan menjadi `ditolak`, akun tetap `menunggu`, dan sesinya dicabut. Saat masuk lagi, akun hanya melihat status dan alasan penolakan (`Registration::reject`).
   - **Pengecualian versi:** `versi` wajib saat mengubah data keluarga/anggota, memverifikasi keluarga, memindahkan akun utama, dan mengubah rumah. Arsip keluarga/akun/rumah, mulai/akhiri/pindah hunian, dan status akun tidak memakai `versi`. Operasi itu memeriksa status di bawah kunci baris dan menjawab 409 bila keadaan sudah berubah.
   - Contoh "sebelum" pada M7.1 kini ditandai sebagai **pengandaian**, karena M06 belum punya fitur pindah anggota.
   - Keterangan status pada gambar dibuat netral: *"Implementasi M07: alur dan aturan; hasil penerimaan ada di laporan"*. Status gate hanya disebut di prosa, sehingga gambar tidak perlu dirender ulang setelah gate.

## Status yang dirujuk (hanya untuk prosa)

| Hal | Status saat draf | Bukti |
| --- | --- | --- |
| M06 | **LULUS**; PR #21 sudah di-merge ke `main` (`bc57b4f`) | [Suplemen M06](../m06/drive/supplement/SUPPLEMENT.md) |
| M07 backend | Source dan tes lokal; tanpa deploy | [CLAUDE_RESULT.md](CLAUDE_RESULT.md) |
| M07 mobile | Source dan tes Flutter lokal; tanpa APK baru atau uji perangkat | [GROK_RESULT.md](GROK_RESULT.md) |
| Gate M07 | **Masih tertunda** pada saat draf ini. Root mengganti baris ini dengan hasil gate yang benar-benar terjadi | [REQUIREMENTS.md](REQUIREMENTS.md) |

Angka tes di laporan checkpoint (backend 212/212, Flutter 137) tidak dijalankan ulang untuk draf ini. Review backend yang masih berjalan dapat mengubah race akses atau urutan lock. Nama tabel dan kolom dianggap stabil.

## Cara menyisipkan

1. Biarkan isi M06 dan lampiran L1–L5 apa adanya. L5 tetap menjadi rencana historis M07. Di bawah penjelasan L5, tambahkan satu kalimat miring: *"Rencana ini sudah diimplementasikan dengan beberapa perbedaan; lihat Gambar M7.5."*
2. Tambahkan satu bagian baru di akhir dokumen: **"M07: pendataan rumah, keluarga, dan akun"**.
3. Setiap subbagian memakai **satu halaman**: judul subbagian, gambar pendamping selebar 425 pt, keterangan miring, prosa pendek, lalu tautan ke file teknis ukuran penuh. Pakai pemisah halaman sebelum setiap subbagian.
4. Unggah lima file teknis `01`–`05` ke folder Drive yang sama, lalu ganti setiap `[TAUTAN-…]` dengan URL file yang benar-benar teramati. Jangan memakai tautan karangan. Bila file belum diunggah, tulis "file teknis tersedia di repo: `docs/evidence/m07/drive/…`".
5. Perkiraan setelah disisipkan: gambar 21 → 26 (hanya PNG `06`–`10` yang ditempel), tabel 4 → 5 bila tabel jawaban ikut disisipkan, dan halaman bertambah sekitar 5–6. Root memastikan jumlah sebenarnya lewat readback dan render PDF.

### Ukuran tempel (lebar 425 pt)

| Gambar di Doc | File pendamping | Piksel | Tinggi di Doc | Teks terkecil |
| --- | --- | --- | --- | --- |
| M7.1 | `drive/06-native-before-after.png` | 1900 × 1640 | ±367 pt | 44 px ≈ 9,8 pt |
| M7.2 | `drive/07-native-userflow.png` | 1900 × 2060 | ±461 pt | 44 px ≈ 9,8 pt |
| M7.3 | `drive/08-native-usecase.png` | 1900 × 1960 | ±438 pt | 44 px ≈ 9,8 pt |
| M7.4 | `drive/09-native-sequence.png` | 1900 × 1856 | ±415 pt | 44 px ≈ 9,8 pt |
| M7.5 | `drive/10-native-erd.png` | 1900 × 1720 | ±385 pt | 44 px ≈ 9,8 pt |

Gambar tertinggi (±461 pt), ditambah judul, keterangan, dan sekitar 6–8 baris prosa, muat dalam satu halaman Letter atau A4 dengan margin 1 inci (±648 pt tinggi isi). Jangan mengecilkan gambar di bawah 425 pt: setiap pengecilan 10% menurunkan teks menjadi kira-kira 8,9 pt.

---

## Teks untuk disisipkan

### Pengantar

> Bagian ini menjelaskan perubahan M07 pada pendataan rumah, keluarga, anggota, dan akun warga. Isinya diturunkan dari kode, migration, dan kontrak M07. Saat bagian ini ditulis, gate penerimaan M07 masih tertunda; hasil akhirnya dicatat di laporan penerimaan. Gambar di bawah adalah versi ringkas. Versi teknis lengkap tersedia sebagai file terpisah. Semua contoh memakai nama fiktif dan tidak memuat data warga, kontak, NIK, atau pembayaran online.

### M7.1 Sebelum dan sesudah

- Tempel: `drive/06-native-before-after.png`
- Keterangan: *Gambar M7.1. Sebelum dan sesudah M07.*
- Prosa:

> M06 hanya menyimpan keadaan saat ini: warga menunjuk satu keluarga, dan akun menunjuk satu warga beserta jenisnya. Bila penunjuk itu diubah, nilai lama hilang tanpa jejak. M07 menambahkan dua tabel riwayat, `keanggotaan_keluarga` dan `tautan_akun`, masing-masing dengan waktu mulai dan selesai. Penunjuk lama tetap ada dan diperbarui dalam transaksi yang sama, sehingga aplikasi lama tetap membaca data yang benar. Hak mengubah data keluarga kini mengikuti **tautan aktif**, bukan `akun.jenis`.
>
> Data lama diisi ulang dengan tanggal mulai kosong, karena tanggal sebenarnya tidak diketahui. Bila data lama memiliki dua akun utama dalam satu keluarga, hanya akun yang tercatat paling awal yang menjadi tautan utama. Nilai `akun.jenis` lama tidak diubah, sehingga penanda "utama" ganda di kolom lama bisa tetap ada. Yang menentukan hak adalah tautan aktif.

- File teknis: *Versi teknis lengkap (contoh pengandaian dan daftar perubahan): [TAUTAN-01 diisi root] — `docs/evidence/m07/drive/01-sebelum-sesudah-m07.png`.*

### M7.2 Alur akun utama dan akun tambahan

- Tempel: `drive/07-native-userflow.png`
- Keterangan: *Gambar M7.2. Alur akun utama dan akun tambahan yang disetujui.*
- Prosa:

> Akun utama aktif sejak pendaftaran. Akun tambahan menunggu pengurus. Bila disetujui, pengurus menautkannya ke anggota keluarga sebagai tautan **tambahan**. Bila ditolak, permohonan berstatus ditolak, tetapi akun tetap berstatus menunggu dan hanya dapat melihat status serta alasan penolakan.
>
> Setelah masuk, server memeriksa tautan aktif. Akun utama dapat melihat dan mengubah data keluarga. Setiap simpan membawa versi data; bila orang lain sudah mengubah lebih dulu, server menjawab 409 dan aplikasi memuat ulang data terbaru. Akun tambahan hanya membaca, dan perubahan yang tetap dikirim ditolak dengan 403. Akun aktif yang belum tertaut menerima 404 dengan pesan "Akun belum terhubung dengan keluarga." Layanan warga lain tetap tersedia untuk semua akun aktif.

- File teknis: *[TAUTAN-02 diisi root] — `docs/evidence/m07/drive/02-userflow-akun-utama-tambahan.png`.*

### M7.3 Use case pengurus

- Tempel: `drive/08-native-usecase.png`
- Keterangan: *Gambar M7.3. Use case pengurus M07.*
- Prosa:

> Semua pengurus memiliki hak yang setara. Pekerjaan pengurus dibagi menjadi empat kelompok: keluarga dan anggota, akun warga, rumah, dan hunian. Elips kuning adalah perpindahan atomik, yaitu data lama ditutup dan data baru dibuka dalam satu transaksi: pindah anggota, pindah akun utama, dan pindah rumah.
>
> Semua perubahan mengunci data terkait lebih dulu. Perubahan isi data (keluarga, anggota, verifikasi, akun utama, rumah) memeriksa **versi**. Arsip, hunian, dan status akun memeriksa **status** di bawah kunci. Bila data sudah berubah, server menjawab 409. Arsip tidak menghapus riwayat, dan tagihan lama tetap di rumahnya.

- File teknis: *Daftar use case lengkap: [TAUTAN-03 diisi root] — `docs/evidence/m07/drive/03-use-case-pengurus-m07.png`.*

### M7.4 Pindah anggota bersamaan

- Tempel: `drive/09-native-sequence.png`
- Keterangan: *Gambar M7.4. Pindah anggota bersamaan: satu berhasil, satu 409.*
- Prosa:

> Dua pengurus memindahkan anggota yang sama dengan versi yang sama. Angka versi 7 dan 8 pada gambar hanya contoh. Server mengunci kedua keluarga dalam urutan tetap. Transaksi pertama menutup keanggotaan lama, membuka yang baru, memindahkan tautan akun anggota itu sebagai tautan tambahan, lalu menaikkan versi. Transaksi kedua menunggu, lalu melihat bahwa versinya sudah berubah, sehingga dibatalkan tanpa mengubah data dan menjawab 409. Sebagai pertahanan terakhir, database menolak keanggotaan atau tautan aktif ganda dan jawabannya tetap 409, bukan 500. Pola yang sama dipakai untuk pindah akun utama dan pindah rumah.

- File teknis: *Langkah lengkap dan urutan kunci: [TAUTAN-04 diisi root] — `docs/evidence/m07/drive/04-sequence-pindah-anggota-409.png`.*

### M7.5 ERD M07

- Tempel: `drive/10-native-erd.png`
- Keterangan: *Gambar M7.5. ERD konseptual M07: delapan entitas utama dan aturan aktif.*
- Prosa:

> Kotak kuning adalah tabel baru M07. Rumah dihuni keluarga melalui `penghunian`. Riwayat anggota keluarga ada di `keanggotaan_keluarga`, dan riwayat akun ada di `tautan_akun`. Angka di garis adalah kardinalitas minimum dan maksimum. Aturan "paling banyak satu yang aktif" dijaga database: satu hunian aktif per rumah, satu keanggotaan aktif per warga, satu tautan aktif per akun, dan satu akun utama aktif per keluarga.
>
> Berbeda dari rencana L5, tautan akun tidak menyimpan rumah. Rumah diturunkan dari hunian aktif keluarga, sehingga satu akun utama per keluarga sama dengan satu akun utama per rumah. Penghapusan tidak berantai (RESTRICT), sehingga riwayat tetap utuh.

- File teknis: *Kolom lengkap dan nama index: [TAUTAN-05 diisi root] — `docs/evidence/m07/drive/05-erd-aktual-m07.png`.*

### Tabel: jawaban yang mungkin dilihat pengguna (opsional, tabel ke-5)

| Situasi | Jawaban server | Yang terlihat |
| --- | --- | --- |
| Data keluarga sudah diubah orang lain | 409 `VERSION_CONFLICT` | Data terbaru dimuat ulang |
| Akun tambahan mencoba mengubah | 403 `PRIMARY_ACCOUNT_REQUIRED` | Tombol ubah tidak ditampilkan |
| Akun aktif belum tertaut | 404 `HOUSEHOLD_NOT_LINKED` | "Akun belum terhubung dengan keluarga." |
| Rumah tujuan masih dihuni | 409 `HOUSE_OCCUPIED` | Pesan tetap di formulir |
| Dua pengurus memindahkan anggota yang sama | 409 `CONFLICT` | Muat ulang lalu coba lagi |
| Arsip keluarga yang masih menghuni rumah atau punya akun | 409 `FAMILY_OCCUPYING` / `FAMILY_HAS_ACCOUNTS` | Akhiri hunian atau arsipkan akun lebih dulu |

### Penutup status

> M06 sudah lulus dan di-merge. Pada saat bagian ini ditulis, gate M07 masih tertunda. Root mengganti kalimat ini dengan hasil gate yang benar-benar terjadi.

---

## Dasar isi (grounding)

| Gambar | Sumber di repo |
| --- | --- |
| M7.1 | Migration `2026_10_13_000100_m07_keanggotaan_tautan_akun`, `Domain/Residents/Links.php`, `LegacyBackfill.php` (utama ganda: hanya yang terawal, `akun.jenis` tidak diubah), [CONTRACT.md](CONTRACT.md) §1–§3 |
| M7.2 | [CONTRACT.md](CONTRACT.md) §4–§5, `Links::assertEditor`, `Registration::approve` dan `reject`, `mobile/lib/features/household/family_page.dart`, `family_actions.dart` |
| M7.3 | `routes/api.php`, `Domain/Residents/{Households,Occupancies,Accounts}.php`, validasi `versi` di `HouseholdController` dan `Admin/ResidentsController`, [CONTRACT.md](CONTRACT.md) §6 dan §8 |
| M7.4 | `Households::moveMember`, `Links::{transaction,lockFamilies,assertVersion}`, `M07ConcurrencyTest`, [CONTRACT.md](CONTRACT.md) §7 |
| M7.5 | Migration M07 dan `2026_10_11_000100` (`penghunian_satu_aktif_*`, `warga_satu_kepala_aktif`), [CONTRACT.md](CONTRACT.md) §2 |

## File keluaran

| File | Peran | Piksel | KB | SHA256 (8 awal) |
| --- | --- | --- | --- | --- |
| `drive/06-native-before-after.png` | tempel di Doc | 1900 × 1640 | 136 | `ee3e20e3` |
| `drive/07-native-userflow.png` | tempel di Doc | 1900 × 2060 | 132 | `d3c8680c` |
| `drive/08-native-usecase.png` | tempel di Doc | 1900 × 1960 | 159 | `dbae84cb` |
| `drive/09-native-sequence.png` | tempel di Doc | 1900 × 1856 | 140 | `0c88c6b7` |
| `drive/10-native-erd.png` | tempel di Doc | 1900 × 1720 | 129 | `9ae2662e` |
| `drive/01-sebelum-sesudah-m07.png` | file teknis (tautan) | 1900 × 1430 | 215 | `c798e51e` |
| `drive/02-userflow-akun-utama-tambahan.png` | file teknis (tautan) | 1900 × 1860 | 188 | `4b659dd9` |
| `drive/03-use-case-pengurus-m07.png` | file teknis (tautan) | 1900 × 1640 | 214 | `fb7d2553` |
| `drive/04-sequence-pindah-anggota-409.png` | file teknis (tautan) | 1900 × 1939 | 218 | `40a68ae8` |
| `drive/05-erd-aktual-m07.png` | file teknis (tautan) | 1900 × 1560 | 259 | `180a8468` |
| `drive/render_m07_native.py` | sumber 06–10 | – | – | – |
| `drive/render_m07_drive.py` | sumber 01–05 | – | – | – |

Render ulang dari root repo:

- `python docs/evidence/m07/drive/render_m07_native.py`: skrip gagal bila ada font di bawah 44 px, teks tidak muat di kotak atau elips, konten menabrak catatan kaki, atau tinggi gambar melebihi 2100 px.
- `python docs/evidence/m07/drive/render_m07_drive.py`: skrip gagal bila ada label yang melebihi kotaknya.

Kedua skrip memakai Pillow 12, font Public Sans dan Bricolage Grotesque dari `mobile/assets/fonts`, serta palet navy/kuning/sky. Kesepuluh PNG sudah diperiksa visual pada ukuran asli; tidak ada label terpotong.

## Privasi dan batas

- Gambar tidak memuat email, kata sandi, token, nomor WhatsApp, alamat, nama warga, NIK, atau kontak RT. Contoh nama bersifat fiktif. Tidak ada tangkapan layar, dan diagram bukan bukti bahwa layar sudah berjalan di perangkat.
- File teknis `01`–`05` tetap memakai teks kecil (18–21 px, sekitar 4,0–4,7 pt pada lebar 425 pt). Karena itu file ini hanya untuk tautan atau zoom, bukan untuk ditempel di Doc.
- Bila review backend mengubah urutan lock atau kode error, sesuaikan prosa M7.4, tabel jawaban, dan file teknis 04. Gambar pendamping 09 sengaja tidak menyebut urutan lock rinci.
