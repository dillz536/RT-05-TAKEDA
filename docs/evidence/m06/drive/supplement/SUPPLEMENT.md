# Suplemen diagram Drive M06: use case, sequence, dan ERD

- Disusun oleh Claude pada 12 Oktober 2026 sebagai cadangan dokumentasi, karena kuota lima jam Kimi habis (HTTP 403). Ini **bukan** karya Kimi. Panduan utama tetap karya Kimi dan sudah terbit sebagai Google Doc native `18yoO1-BY7-ylFyujpkDrpp_NkPQOwq-9-mTRPK_-KlM` di folder `1_WweESw5u0iHaDlw6LUfFAuUgOzAstpj` (16 bagian, 4 tabel, 12 gambar). Lihat juga [catatan Kimi](../../KIMI_DRIVE_DOCUMENTATION.md).
- Suplemen ini hanya menambah lima gambar teknis yang diminta pemilik: use case UML yang memakai aktor dan elips, dua sequence diagram, ERD M06 yang diturunkan dari migration, dan ERD rencana M07 yang digambar terpisah.
- Lingkup file: hanya `docs/evidence/m06/drive/supplement/`. Tidak mengubah dokumen master Kimi, DOCX, PROGRESS, kode domain/mobile/infra, OpenAPI, maupun Git.
- Sesi ini tidak memakai connector Drive. Setelah review, root menambahkan gambar dan teks di bawah ke **Google Doc yang sama**, bukan ke dokumen baru.

## Status yang dirujuk (12 Oktober 2026)

| Hal | Status | Bukti |
| --- | --- | --- |
| Staging `26a0b7e` | Ter-deploy, runtime smoke PASS | [Penerimaan Claude](../../CLAUDE_RESUMED_ACCEPTANCE.md) |
| Probe staging backend | 52/52 PASS | Sama, §4 |
| Mekanika reset kata sandi di staging | 17/17 PASS memakai kode yang dibuat server, bukan dari inbox | Sama, §6 |
| Email reset sampai inbox | **DIBLOKIR**: SMTP staging belum dikonfigurasi | Sama, §7 |
| M06 | Belum dinyatakan lulus, karena gerbang inbox | Sama |
| M07 | Belum dibuka; rancangan saja | [ROADMAP](../../../../ROADMAP.md) |

## Cara menyisipkan ke Google Doc

Tambahkan bagian baru di akhir dokumen utama dengan judul **"Lampiran: diagram teknis akses akun (suplemen)"**. Sisipkan kalimat pembuka berikut:

> Lampiran ini melengkapi panduan utama dengan diagram teknis yang mengikuti notasi UML dan ERD. Semua isi diturunkan dari kode dan migration M06 yang sudah ada. Rencana M07 digambar terpisah dan diberi tanda "belum diimplementasi".

Lalu sisipkan kelima gambar berikut, sesuai urutan. Untuk setiap gambar, tempel gambar selebar halaman, lalu teks keterangan (miring), lalu penjelasan singkat.

### Gambar L1. Use case UML

- File: `docs/evidence/m06/drive/supplement/01-use-case-uml-m06.png` (1900 × 1600)
- Keterangan: *Gambar L1. Diagram use case UML akses akun M06: aktor, batas sistem, dan layanan yang sudah ada.*
- Penjelasan: Gambar ini menunjukkan siapa memakai layanan apa. Pengunjung publik hanya membaca website tanpa akun. Warga akun utama dan warga akun tambahan mendaftar dengan token RT 6 digit. Kedua alur pendaftaran itu memakai langkah bersama "Verifikasi token RT" (hubungan «include»). Warga akun tambahan baru dapat membaca data keluarga setelah disetujui pengurus; selama status menunggu, ia hanya melihat status permohonan. Warga, warga tambahan, dan pengurus sama-sama merupakan "Pengguna terdaftar", sehingga ketiganya dapat masuk, keluar, dan mereset kata sandi. Pengurus adalah admin di dalam aplikasi: ia mengelola token RT dan menyetujui atau menolak akun tambahan. Operator teknis bukan pengurus. Operator hanya bekerja lewat command server yang diaudit untuk membuat, memberi, atau mencabut peran pengurus.

### Gambar L2. Sequence registrasi akun utama

- File: `docs/evidence/m06/drive/supplement/02-sequence-registrasi-akun-utama.png` (1880 × 1883)
- Keterangan: *Gambar L2. Sequence diagram registrasi akun utama dalam satu transaksi database.*
- Penjelasan: Setelah isian lolos validasi, server membuka satu transaksi. Di dalamnya server mencocokkan token RT, memastikan email dan alamat rumah belum terdaftar, lalu membuat rumah, keluarga, anggota, penghunian, akun, catatan audit, dan bearer perangkat. Bila satu langkah saja gagal, seluruh transaksi dibatalkan (ROLLBACK), sehingga tidak ada data setengah jadi. Warga hanya menerima pesan generik. Bila berhasil, akun utama langsung aktif, sedangkan data keluarga menunggu verifikasi pengurus.

### Gambar L3. Sequence lupa dan reset kata sandi

- File: `docs/evidence/m06/drive/supplement/03-sequence-reset-kata-sandi.png` (1900 × 1946)
- Keterangan: *Gambar L3. Sequence diagram lupa dan reset kata sandi; pengiriman SMTP ke inbox masih diblokir.*
- Penjelasan: Token reset disimpan dalam transaksi. Email baru diantrekan **setelah** transaksi commit, sehingga gangguan email tidak menghapus data. Jawaban server selalu sama (202), terdaftar atau tidak, agar daftar email warga tidak bocor. Kotak bergaris putus-putus menandai bagian yang belum terbukti. Server SMTP staging belum disiapkan, jadi email belum pernah sampai ke inbox. Langkah penggantian kata sandi (10 sampai 13) sudah lulus tes otomatis dan cek staging. Kode untuk cek itu dibuat di server, bukan diambil dari inbox.

### Gambar L4. ERD M06

- File: `docs/evidence/m06/drive/supplement/04-erd-m06-akses-pendataan.png` (1900 × 1500)
- Keterangan: *Gambar L4. ERD akses akun dan pendataan M06 sesuai migration yang sudah berjalan.*
- Penjelasan: Rumah, keluarga, dan warga dihubungkan oleh tabel penghunian. Satu rumah dan satu keluarga masing-masing hanya boleh punya satu penghunian aktif. Akun boleh terhubung ke satu warga. Setiap pendaftaran tercatat di `permohonan_akun` beserta token RT yang dipakai dan pengurus yang memprosesnya. Data tidak dihapus berantai: penghapusan memakai `archived_at`, dan foreign key memakai RESTRICT. Garis putus-putus menandai hubungan tanpa foreign key, misalnya token reset yang dicocokkan lewat email.

### Gambar L5. Rencana ERD M07

- File: `docs/evidence/m06/drive/supplement/05-erd-rencana-m07.png` (1900 × 1220)
- Keterangan: *Gambar L5. Rencana M07 (belum diimplementasi): keanggotaan keluarga dan tautan akun.*
- Penjelasan: Kotak kuning bergaris putus-putus belum ada di database. Gambar ini memperlihatkan arah M07 sesuai `docs/ERD.md`. Keanggotaan keluarga akan menyimpan riwayat seseorang di sebuah keluarga. Tautan akun akan menghubungkan akun dengan warga, keluarga, dan rumah, lengkap dengan rentang waktunya. Aturan yang direncanakan: satu tautan utama aktif per rumah dan satu tautan aktif per akun. Riwayat perpindahan juga tidak boleh dihapus. Nama tabel dan kolom final baru ditetapkan saat M07 dibuka setelah M06 lulus.

## Dasar isi (grounding)

| Gambar | Sumber di repo |
| --- | --- |
| L1 | `rest-api/routes/api.php`, `docs/BUSINESS_RULES.md` BR01–BR07, command `rt05:pengurus` (`rest-api/app/Console/Commands/PengurusAccount.php`), `mobile/lib/features/pengurus/pengurus_pages.dart` |
| L2 | `AuthController::register`, `rest-api/app/Domain/Identity/Registration.php` (`primary`, `lockAndVerify`, `guarded`), `TokenAttempts` |
| L3 | `AuthController::forgotPassword` dan `resetPassword`, `Domain/Identity/PasswordReset.php`, `Notifications/ResetPasswordNotification.php` (`ShouldQueueAfterCommit`, `ShouldBeEncrypted`, `shouldSend`), `config/auth.php` (expire 60, throttle 60) |
| L4 | Migration `2026_10_10_000200`, `2026_10_11_000100`, `2026_10_11_000200`, `2026_10_11_000400` (`preferensi_notifikasi`), `2026_10_12_000100` (`versi_sesi`) |
| L5 | `docs/ERD.md` (`family_memberships`, `account_links`), `docs/DATABASE.md` (constraint `account_links`), `docs/BUSINESS_RULES.md` bagian rumah/keluarga, `docs/ROADMAP.md` M07 |

## Privasi dan batas

- Diagram tidak memuat email, kata sandi, token, kode reset, nomor WhatsApp, alamat, nama warga, isi inbox, atau nilai fixture privat. Isinya hanya nama tabel, kolom, endpoint, dan status.
- Tidak ada fitur di luar M06/M07, dan tidak ada perubahan kode bisnis.
- Gambar dibuat ulang dengan `python docs/evidence/m06/drive/supplement/render_supplement.py` (Pillow). Palet navy/kuning/sky dan font Public Sans + Bricolage Grotesque sama dengan `infra/scripts/render-m06-diagrams.py`. Skrip memeriksa setiap label dan gagal bila ada teks yang melebihi kotaknya.
- Kelima PNG sudah diperiksa visual pada ukuran asli.
- Koreksi setelah review root, 12 Oktober 2026:
  - L5: angka kardinalitas `1` di sisi `rumah` dan `akun` sebelumnya menempel pada baris `alamat_key` dan header/`PK` `akun`. Angka itu kini digeser keluar dari tepi kotak. Garis `akun`–`tautan akun` sekarang masuk ke baris isi, bukan batas header.
  - L1: hubungan warga akun tambahan ke "Melihat data keluarga sendiri" kini diberi label "hanya setelah disetujui (aktif)". Akun tambahan yang masih menunggu tidak dapat membaca data keluarga.
  - Gambar L2, L3, dan L4 tidak berubah isinya.
