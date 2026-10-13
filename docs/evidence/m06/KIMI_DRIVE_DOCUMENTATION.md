# KIMI Drive Documentation — M06 (akses akun) dan rancangan M07

- Penyusun: Kimi (dokumentasi manusiawi untuk Drive capstone). Tanggal: 12 Oktober 2026.
- Kepemilikan file: dokumen ini dan artefak dokumentasi di `docs/evidence/m06/drive/`. Tidak ada perubahan domain, sumber aplikasi, atau operasi Git.
- Akses Drive: sesi ini **tidak memiliki connector Drive/Docs**; artefak disusun lokal dan diserahkan ke coordinator untuk upload/update (lihat bagian Handoff).
- Batas kejujuran bukti: dokumen membedakan tegas antara **terimplementasi dan teruji CI/emulator**, **menunggu penerimaan live staging**, dan **blocked**. Tidak ada klaim M06 passed, SMTP terkirim, layar Stitch baru, atau fakta yang tidak dibuktikan.

## Publikasi oleh coordinator sesudah handoff Kimi

Panduan utama diimpor ke Google Doc native pada folder Capstone existing:
https://docs.google.com/document/d/18yoO1-BY7-ylFyujpkDrpp_NkPQOwq-9-mTRPK_-KlM/edit
Laporan existing sudah ditautkan ke panduan. Native Doc diperbarui dengan
rollout staging26a0b7e,52alur akun/17mekanika reset, dan screenshot Android
utama aktif/keluarga belum diverifikasi serta tambahan pending. Kimi adalah
penyusun panduan utama; suplemen5UML/sequence/ERD dikerjakan Claude sesudah
Kimi quota403, dengan koreksi visual root. Seluruh19gambar tersemat di Doc;
PNG suplemen ukuran penuh disimpan pada folder Drive yang sama dan ditautkan.
DOCX/master teks di bawah merupakan checkpoint Kimi sebelum hasil live akhir,
bukan salinan lengkap revision native terbaru. M06 tetap belum passed karena
inbox belum terbukti; M07 tetap rancangan.

## Target Drive (dari coordinator/pemilik)

| Item | ID | Catatan |
| --- | --- | --- |
| Folder laporan | `1_WweESw5u0iHaDlw6LUfFAuUgOzAstpj` | Capstone Project → Tugas dan Project → Perkembangan RT05 TAKEDA M06–M07. Jangan membuat folder duplikat. |
| Google Doc laporan native | `1Dxax50Kckfuc2cCO4_PlanRIlNNQVLpsIN0S42s5VMU` | Update dokumen ini, pertahankan materi lama yang masih berguna. |
| Dokumen akademik Tugas 03 | `1024MDDGIh6xQFAdJwLH4PXGsWnsEMgC6mU4bCqOVQBo` | READ ONLY; tidak diedit; selisih direkonsiliasi di laporan, bukan di dokumen asli. |

## Artefak yang diserahkan

| Artefak | Lokasi lokal | Isi |
| --- | --- | --- |
| Dokumen utama (DOCX, siap impor ke Google Docs) | `docs/evidence/m06/drive/RT05-TAKEDA-Dokumentasi-Akses-M06.docx` | Dokumentasi manusiawi lengkap dengan gambar tersemat: 6 diagram PNG + 6 screenshot emulator. |
| Master Markdown (sumber teks yang sama) | bagian "Isi dokumen" di bawah | Referensi teks untuk copy manual bila impor DOCX tidak dipakai. |
| Gambar (sudah ada di repo, dipakai ulang) | `docs/evidence/m06/diagrams/*.png`, `docs/evidence/m06/screenshots/*.png` | Diagram: mindmap, use-case, architecture, registration, approval, family-move. Screenshot: login, skala 1,3/2,0, reset 2,0, offline, retry. |

Semua gambar adalah PNG yang sudah ter-commit di repo — diagram terlihat sebagai gambar, bukan sumber Mermaid. Screenshot berasal dari `adb screencap` emulator nyata (bukan render desain/Stitch), memakai akun guest sintetis tanpa password/token/email nyata.

## Isi dokumen (master teks)

Teks berikut adalah isi DOCX dan merupakan materi yang diminta untuk Google Doc laporan.

---

# RT05 TAKEDA — Dokumentasi Akses Akun dan Pendataan (M06–M07)

**Tanggal:** 12 Oktober 2026 · **Status milestone:** M06 sedang diverifikasi (belum passed); M07 masih rancangan dan terkunci sampai M06 dinyatakan lulus.

## 1. Ringkasan

Aplikasi RT05 TAKEDA sekarang memiliki fondasi akses akun yang lengkap: warga dapat mendaftar sebagai akun utama rumah atau akun tambahan keluarga, masuk dengan email dan password, memulihkan password lewat kode email, dan pengurus memakai command yang diaudit untuk mengelola hak admin. Seluruh aturan bisnis berada di REST API Laravel; aplikasi Flutter Android dan website hanya menjadi klien. Paket kode ini lulus CI GitHub (184 tes backend pada source `15885cb`; Flutter 114 tes pada run historis, lalu 117 tes setelah koreksi skala teks `7d42d56`) dan pemeriksaan emulator nyata sebagai tamu. Penerimaan live di staging (registrasi/login nyata, email reset sampai inbox) masih menunggu rollout dan konfigurasi SMTP — keduanya dijelaskan apa adanya pada bagian status.

## 2. Latar belakang: mengapa perubahan dilakukan

Dokumen akademik awal (Tugas 03) menggambarkan **semua** permohonan akun menunggu persetujuan pengurus. Setelah diskusi kebutuhan, pemilik menetapkan keputusan yang lebih sesuai operasional RT:

- **Akun utama rumah** yang datanya valid **langsung aktif**, agar warga tidak tertunda memakai layanan; data keluarganya ditandai **belum terverifikasi** sampai pengurus meninjaunya.
- **Akun tambahan** (anggota keluarga lain di rumah yang sama) tetap **menunggu persetujuan pengurus**, karena pengurus perlu memastikan orang tersebut memang bagian keluarga itu.
- Dokumen akademik asli **tidak diubah**; perbedaan ini didokumentasikan sebagai rekonsiliasi resmi (bagian 13).

Perubahan lain adalah penguatan keamanan dan kejujuran data: token registrasi enam digit disimpan aman, sesi perangkat memakai bearer token, koreksi keuangan selalu lewat pembalikan teraudit, dan tidak ada payment gateway — iuran Rp75.000 per rumah per bulan tetap dibayar offline dan dicatat pengurus.

## 3. Perubahan utama: sebelum dan sesudah

| Aspek | Sebelum (rancangan/baseline) | Sesudah (implementasi M06) |
| --- | --- | --- |
| Aktivasi akun utama | Semua permohonan menunggu approval pengurus | Akun utama valid langsung aktif; keluarga berstatus belum terverifikasi |
| Akun tambahan | Belum diatur rinci | Pending; hanya bisa melihat status permohonan dan logout; setelah disetujui menjadi warga dengan data keluarga read-only |
| Token registrasi | Konsep umum | String 6 digit (nol di depan dipertahankan), satu token aktif per RT, rotasi mencabut token lama segera, dapat dilihat/disalin pengurus |
| Logo mobile | Digambar ulang manual | SVG gapura resmi byte-identik dengan website; ikon Android dikonversi dari aset itu |
| Font | Unduhan runtime (google_fonts) | Public Sans dan Bricolage Grotesque terbundel berlisensi OFL; tanpa unduhan runtime |
| Pemulihan password | Belum ada | Permintaan reset antrean terenkripsi after-commit; kode/tautan sekali pakai dengan expiry dan anti-reuse; **pengiriman email nyata masih blocked (SMTP belum disiapkan)** |
| Sesi browser CMS | Belum terpisah | Cookie/CSRF API dipisah dari cookie website; pencabutan sesi lewat versi sesi |
| Hak admin | Implisit | Grant/revoke admin hanya lewat command operator yang diaudit; tanpa password bawaan; semua admin setara |
| Keterbacaan teks besar | Pesan error terpotong (ellipsis) pada skala 2,0 | Diperbaiki dan diverifikasi pada APK baru; skala 1,3/2,0 terbaca penuh |

## 4. Aktor sistem

| Aktor | Deskripsi | Hak utama |
| --- | --- | --- |
| Pengunjung publik | Siapa pun tanpa akun | Membaca website publik (profil, artikel, dokumentasi, album) |
| Warga akun utama | Satu akun utama per rumah | Login, layanan warga, membaca dan mengedit data keluarga sendiri |
| Warga akun tambahan | Anggota keluarga lain, disetujui pengurus | Layanan warga; data keluarga read-only |
| Pengurus (admin) | Pengurus RT yang ditetapkan | Mengelola konten, token registrasi, persetujuan akun tambahan, pendataan, iuran/kas; semua admin setara |
| Operator | Pengelola teknis lewat command | Bootstrap/grant/revoke admin yang teraudit; bukan akun harian |

## 5. Alur registrasi akun utama

1. Warga mengisi email, password, token RT 6 digit (diperoleh dari pengurus), dan data rumah/keluarga dari awal.
2. Server memvalidasi token, alamat yang dinormalisasi, dan email; rumah yang sudah terdaftar **tidak digabung otomatis** — benturan dilaporkan, bukan diam-diam membuat keluarga utama kedua.
3. Satu transaksi atomik membuat akun, rumah, keluarga, anggota, hunian, catatan audit, dan bearer token perangkat.
4. Akun utama langsung **aktif** dengan peran warga; keluarga berstatus **belum terverifikasi** sampai pengurus meninjau.
5. Jika token salah/kedaluwarsa atau data bentrok, pesan kesalahan generik ditampilkan dan warga dapat memperbaiki isian atau menghubungi pengurus.

Gambar: `diagrams/registration.png`

## 6. Alur registrasi akun tambahan dan persetujuan

1. Warga mendaftar dengan email, password, token, dan referensi alamat keluarga.
2. Akun berstatus **menunggu**; hanya dapat melihat status permohonan, profil sendiri, dan logout — tidak dapat membaca layanan/data warga lain.
3. Pengurus meninjau (fitur review lengkap pada M07) dan memutuskan dengan alasan yang tercatat di audit.
4. **Ditolak:** status dan alasan tetap terlihat oleh pemohon; akses warga tetap tertolak.
5. **Disetujui:** akun aktif sebagai warga, terhubung ke keluarga yang benar, data keluarga read-only.

Gambar: `diagrams/approval.png`

## 7. Login, reset password, dan sesi

- **Login** memakai email dan password (bukan Google OAuth). Aplikasi Android menyimpan bearer token di secure storage sistem operasi; `allowBackup` dimatikan agar token tidak ikut cadangan.
- **Ketahanan sesi:** hanya respons 401 atau logout yang menghapus token. Gangguan jaringan, timeout, 503, atau 403 mempertahankan token dan menampilkan status tidak tersedia dengan tombol coba lagi; respons sesi lama tidak dapat menimpa login baru (dijaga mekanisme epoch dan storage terserialisasi).
- **Reset password:** warga mengisi email; respons selalu generik (tidak membocorkan email terdaftar). Kode/tautan sekali pakai dikirim lewat antrean terenkripsi setelah transaksi berhasil, dengan masa berlaku dan penolakan pemakaian ulang. **Catatan jujur:** konfigurasi pengirim SMTP di staging belum pernah disiapkan, sehingga bukti email sampai ke inbox masih blocked — langkah setup terdokumentasi di SMTP_OPERATOR.md.
- **Logout dan ganti password:** server mencabut sesi; "keluar dari semua perangkat" tersedia; pesan sukses tidak diklaim saat perangkat sedang offline.

## 8. Use case layanan

Gambar: `diagrams/use-case.png` — memetakan lima aktor ke layanan: pengunjung membaca konten publik; akun utama mendaftar/login/reset dan mengelola keluarga sendiri; akun tambahan memantau status permohonan; pengurus mengelola review, token, dan pendataan; operator menjalankan command bootstrap/grant/revoke. REST API menjadi titik validasi, otorisasi, transaksi, dan audit untuk semuanya.

## 9. Arsitektur teknis

Gambar: `diagrams/architecture.png` — satu origin HTTPS di depan dua klien (browser untuk website/CMS dengan cookie+CSRF; Android Flutter dengan bearer per perangkat). REST API Laravel memegang role/status/policy, transaksi, audit, dan outbox. PostgreSQL Neon adalah sumber data utama (runtime dipisah dari migrator; DML tanpa DDL). Redis terpisah untuk queue dan cache/session/rate-limit. Worker mengirim email reset lewat SMTP — bagian inilah yang masih membutuhkan konfigurasi pengirim nyata.

## 10. Model data (ERD) dan rancangan M07

Konsep data inti: **House** (unit tagihan dengan kode stabil dan alamat ternormalisasi), **Family**, **Resident** (anggota, termasuk yang tidak punya akun), **Occupancy** (relasi keluarga–rumah dengan rentang hunian dan penanda keluarga utama; rumah kontrakan memakai tipe tenant), **membership keluarga** dan **link akun** yang memiliki rentang waktu sehingga riwayat tidak hilang. Tagihan iuran melekat pada **rumah**, bukan orang: perpindahan keluarga tidak memindahkan tagihan atau menghapus tunggakan.

Rancangan M07 (belum diimplementasikan — menunggu M06 passed): pengelolaan rumah/penghuni/keluarga/verifikasi/arsip oleh pengurus; akun utama mengedit keluarga sendiri, akun tambahan read-only; **perpindahan hunian atomik** — satu transaksi menutup hunian dan link lama lalu membuka yang baru, histori membership/link dipertahankan, backfill data lama konservatif tanpa menebak tanggal; arsip tidak menghapus kas atau histori keuangan.

Gambar: `diagrams/family-move.png`

## 11. Peta ruang lingkup

Gambar: `diagrams/mindmap.png` — M06 (akses: registrasi utama/tambahan, login, logout, pemulihan; perlindungan: token RT, CSRF, role, status, audit) berjalan sekarang; M07 (pendataan dan histori hunian) mengikuti setelah penerimaan M06.

## 12. Walkthrough aplikasi nyata (emulator)

Screenshot nyata dari APK staging (build debug CI) pada emulator Pixel 8a, sebagai tamu tanpa akun:

| Gambar | Yang ditunjukkan |
| --- | --- |
| `screenshots/login-15885cb.png` | Layar masuk ber-brand resmi: logo gapura, warna navy/kuning/sky, font terbundel, badge STAGING |
| `screenshots/login-scale13-15885cb.png` | Layar masuk pada skala teks 1,3 tetap terbaca |
| `screenshots/login-scale20-15885cb.png` | Skala teks 2,0: scroll mencapai semua isian dan tombol |
| `screenshots/reset-scale20-7d42d56.png` | Form reset pada skala 2,0: aturan password dan pesan error tampil utuh (perbaikan dari temuan ellipsis) |
| `screenshots/guest-offline-15885cb.png` | Mode pesawat: pesan tidak dapat terhubung + tombol Coba lagi, tanpa keluar paksa |
| `screenshots/guest-retry-15885cb.png` | Setelah jaringan pulih, Coba lagi pada proses yang sama kembali ke layar masuk |

Batas bukti: screenshot memakai keadaan guest tanpa token; alur terautentikasi (registrasi, approval, login pengurus/warga) diuji pada CI dan tes lokal, dan akan dibuktikan di staging setelah rollout.

## 13. Selisih terhadap dokumen capstone Drive

| Selisih | Kondisi | Penanganan |
| --- | --- | --- |
| Approval registrasi | Tugas 03: semua akun pending. Keputusan terbaru: akun utama langsung aktif (data unverified), tambahan tetap pending | Direkonsiliasi di laporan ini; dokumen akademik asli tidak diedit |
| Tipe nominal uang | ERD akademik DECIMAL(14,2); implementasi bigint rupiah bulat | ADR17; tidak mengubah tarif/snapshot |
| Relasi akun–warga | Tugas 03 mewajibkan warga_id; implementasi mengizinkan null saat bootstrap/pending | Penyesuaian schema; invariant diverifikasi M06/M07 |
| Penyimpanan foto | Rancangan cloud storage; staging memakai volume api-storage | Keterbatasan dilaporkan; integrasi media/cloud belum selesai |
| Push notification | Dibolehkan setelah layanan utama; roadmap menempatkan di M10 | Tidak diklaim siap |

## 14. Status penerimaan per area

| Area | Status | Bukti |
| --- | --- | --- |
| Kode akses akun (backend+mobile) | Terimplementasi, CI hijau — belum live staging | Source 15885cb: API 184 tes/1679 assertions; Flutter 114 tes historis, 117 tes setelah koreksi skala teks 7d42d56 (screenshot terbaru 26a0b7e); analyze/format bersih; APK dev+staging SUCCESS (run 38228867337/38228867355 dst.) |
| Website + album kegiatan | Live dan terverifikasi | PR20 merged; 21 rute HTML + 32 aset identik di alias publik |
| Pemeriksaan emulator (guest) | PASS terbatas | Health HTTPS nyata, offline→retry proses sama, skala teks 1,3/2,0, tanpa crash/overflow |
| Rollout staging kode M06 | **Menunggu** | Konsol Hostinger sudah dibuka kembali; script deploy immutable terverifikasi checksum tetapi belum dieksekusi — footer/SHA live belum berubah |
| Reset password sampai inbox | **Blocked** | SMTP pengirim belum pernah disiapkan; penerima uji tersedia privat; langkah di SMTP_OPERATOR.md |
| Layar Stitch baru | **Blocked** | Generation timeout 300 detik; listing masih 40 layar existing; implementasi mengikuti brand existing |
| Insiden credential historis | **Open** | Rotasi menunggu pemilik; tidak diakali; production/data warga nyata mengikuti gate M16 |
| M07 (pendataan) | **Terkunci** | Baru dibuka setelah M06 passed |

## 15. Blocker dan langkah berikutnya

1. Konsol Hostinger sudah dibuka kembali → eksekusi script deploy immutable yang terverifikasi dan buktikan footer/SHA live berubah.
2. Siapkan pengirim SMTP sesuai SMTP_OPERATOR.md → buktikan email reset tiba di inbox uji.
3. Setelah rollout: buktikan registrasi utama, approval/penolakan tambahan, login pengurus/warga, dan cookie/CSRF di staging dengan akun sintetis.
4. Rotasi credential insiden historis (M16) tetap open.

## 16. Sumber

PRD, BUSINESS_RULES, DECISIONS, ROADMAP, TESTING, OpenAPI (repo, PRD identik SHA256 dengan dokumen induk); dokumen akademik Tugas 03/04 dan logbook di Drive (read-only); bukti teknis di folder repo `docs/evidence/m06/` (REPORT, REQUIREMENTS, CI_RESULTS, LIVE_RESULTS, UI_STATES, CONTRACT, SMTP_OPERATOR, diagram dan screenshot).

---

## Handoff ke coordinator (upload/update Drive)

Karena connector Drive tidak tersedia di sesi ini, diminta coordinator:

1. Verifikasi parent folder `1_WweESw5u0iHaDlw6LUfFAuUgOzAstpj` (Capstone Project → Tugas dan Project) tanpa membuat folder duplikat.
2. Pilih salah satu jalur (keduanya sah):
   - **Update laporan existing** `1Dxax50Kckfuc2cCO4_PlanRIlNNQVLpsIN0S42s5VMU` lewat `batch_update_document` dengan `image_uris` untuk gambar inline — pertahankan materi checkpoint lama yang masih berguna; atau
   - **Pisahkan panduan manusiawi** sebagai Google Doc native baru di folder yang sama, lalu tautkan dari laporan existing.
3. Cara termudah: buka `docs/evidence/m06/drive/RT05-TAKEDA-Dokumentasi-Akses-M06.docx` dan impor/salin ke Google Doc (heading dan gambar ikut).
4. Lakukan readback isi/heading Doc setelah update dan catat hasilnya di PROGRESS.md.
5. Integrasi hasil final Claude/Grok berikutnya lewat follow-up terpisah; dokumen ini sudah menandai area yang masih menunggu/blocked sehingga tidak perlu dikarang.

### Urutan persis untuk Google Doc native (teks/gambar)

Urutan konten DOCX (yang sama dengan bagian "Isi dokumen") untuk disusun di Doc native; gambar selalu inline tepat sesudah paragraf/daftar yang menyebutnya:

1. Judul (Title): "RT05 TAKEDA — Dokumentasi Akses Akun dan Pendataan (M06–M07)", lalu paragraf miring tanggal/status.
2. H1 "1. Ringkasan" + 1 paragraf.
3. H1 "2. Latar belakang: mengapa perubahan dilakukan" + paragraf + 3 bullet + 1 paragraf.
4. H1 "3. Perubahan utama: sebelum dan sesudah" + tabel 3 kolom × 9 baris.
5. H1 "4. Aktor sistem" + tabel 3 kolom × 5 baris (operator terpisah dari admin aplikasi).
6. H1 "5. Alur registrasi akun utama" + daftar bernomor 5 butir + gambar `diagrams/registration.png` (lebar penuh).
7. H1 "6. Alur registrasi akun tambahan dan persetujuan" + daftar bernomor 5 butir + gambar `diagrams/approval.png`.
8. H1 "7. Login, reset password, dan sesi" + 4 bullet.
9. H1 "8. Use case layanan" + 1 paragraf + gambar `diagrams/use-case.png`.
10. H1 "9. Arsitektur teknis" + 1 paragraf + gambar `diagrams/architecture.png`.
11. H1 "10. Model data (ERD) dan rancangan M07" + 2 paragraf (M07 ditandai jelas sebagai rancangan/terkunci) + gambar `diagrams/family-move.png`.
12. H1 "11. Peta ruang lingkup" + 1 paragraf + gambar `diagrams/mindmap.png`.
13. H1 "12. Walkthrough aplikasi nyata (emulator)" + paragraf pengantar + untuk tiap screenshot: paragraf caption lalu gambar (lebar ±7 cm): `login-15885cb.png`, `login-scale13-15885cb.png`, `login-scale20-15885cb.png`, `reset-scale20-7d42d56.png`, `guest-offline-15885cb.png`, `guest-retry-15885cb.png` + paragraf batas bukti.
14. H1 "13. Selisih terhadap dokumen capstone Drive" + tabel 3 kolom × 5 baris.
15. H1 "14. Status penerimaan per area" + tabel 3 kolom × 8 baris.
16. H1 "15. Blocker dan langkah berikutnya" + daftar bernomor 4 butir.
17. H1 "16. Sumber" + 1 paragraf.

## Verifikasi lokal

- `node infra/scripts/check-repo.mjs` lulus setelah penulisan artefak (653 berkas, tanpa file terlarang/secret).
- Atribusi angka dikoreksi pada follow-up: API 184 tes/1679 assertions pada source `15885cb`; Flutter 114 tes historis lalu 117 tes setelah koreksi `7d42d56` (bukti PROGRESS.md dan GROK_EMULATOR_UI_FIX.md); screenshot terbaru pada `26a0b7e`. Status konsol Hostinger diperbarui: sudah dibuka kembali, rollout tetap menunggu bukti footer/SHA live.
- **Page fit diperbaiki:** lebar diagram awalnya 16 cm melebihi area cetak usable 15,24 cm (Letter default + margin 1"); kini diagram 15 cm dan screenshot 7 cm — diverifikasi dari `wp:extent` di `document.xml` (12 gambar: 6×15,0 cm + 6×7,0 cm).
- **Verifikasi visual:** keenam diagram PNG dibaca langsung — label terbaca, brand navy/kuning konsisten, operator tergambar sebagai aktor terpisah dari pengurus, diagram M07 bertanda rancangan. Screenshot `reset-scale20-7d42d56.png` menunjukkan helper dua baris dan pesan error utuh (email sintetis `warga.uji@example.test`); `guest-offline-15885cb.png` menunjukkan ikon mode pesawat dan pesan offline. Renderer docx (LibreOffice/pdftoppm) tidak tersedia di mesin ini; verifikasi visual memakai PNG sumber yang byte-nya sama dengan yang tersemat.
- Tidak ada credential, inbox pribadi, draf browser, atau foto asli Drive yang masuk Git.

## Publikasi dan checkpoint terbaru oleh coordinator

DOCX di atas adalah sumber checkpoint Kimi sebelum rollout, bukan salinan
tersinkron Google Doc. Versi terbaru ada pada panduan native dalam folder yang
sama: https://docs.google.com/document/d/18yoO1-BY7-ylFyujpkDrpp_NkPQOwq-9-mTRPK_-KlM/edit.
Coordinator memperbarui bukti rollout/API/Android dan gate inbox; lima diagram
UML/sequence/ERD tambahan dikerjakan Claude setelah quota Kimi403, dengan
atribusi terpisah. Ditambah dua screenshot akun utama/pending dari emulator.
Final native24halaman,19gambar inline,4tabel; ekspor PDF dan halaman terpilih
diinspeksi visual, nol gambar di luar halaman. Lima PNG ukuran penuh memiliki
tautan pada direktori terakhir. M06 blocked pada inbox nyata, M07 masih rancangan.
