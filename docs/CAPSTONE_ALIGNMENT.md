# Kesesuaian capstone dan implementasi

Diperiksa 11 Oktober 2026. Target terbaru pemilik: M03 → M04 → M05,
bertahap dengan bukti sebelum maju. Pemeriksaan ini membandingkan dokumen
yang dibaca dan kode yang tersedia; bukan hasil UAT atau pernyataan bahwa
seluruh dokumen Drive/prototype sudah diperiksa.

Pembaruan12 Oktober: pemilik membuka M06–M07 secara berurutan. PRD repo
terverifikasi identik dengan PRD asli di folder induk; Tugas03 dibaca ulang
melalui connector. Akun utama valid langsung aktif, data keluarga belum
diverifikasi, tambahan tetap menunggu persetujuan. Diagram semua permohonan
menunggu di capstone asli dipertahankan sebagai dokumen akademik; rekonsiliasi
baru ada pada [matriks M06–M07](evidence/m06/REQUIREMENTS.md) dan laporan Drive.
M07 hanya dibuka setelah M06 passed, SMTP delivery belum dapat diklaim.

## Sumber yang dibaca

- [Tugas 03 — Perancangan solusi](https://docs.google.com/document/d/1024MDDGIh6xQFAdJwLH4PXGsWnsEMgC6mU4bCqOVQBo/edit), revisi 6 Oktober.
- [Tugas 04 — WBS](https://docs.google.com/document/d/1RfxvC_uQNg5Tt97yguz92L_YMfDYkgSU0v5pAF2KCJs/edit), revisi 8 Oktober.
- [Logbook 1](https://docs.google.com/document/d/1Tspz3K1ponxXaiDxGS8xdd0Ju0D0xYstdaxNxinb8iA/edit).
- [Logbook 2 — berkas kelompok](https://docs.google.com/document/d/19hGDE3M-XasaP2F0PpwJ1gM9z7mFa39w/edit).
- [Logbook 3 — berkas kelompok](https://docs.google.com/document/d/1NEbtBrv5agbW2nD8WF-mbrVZY2vRH7Sk/edit).
- [Brainstorm terdahulu](https://docs.google.com/document/d/1OcClk1SGGb-TDPWIDBAK1tg-kvclDNalDRkpV2Z3ivM/edit), secara eksplisit bukan spesifikasi final.
- AGENTS.md, PRD.md, BUSINESS_RULES.md, DECISIONS.md, ROADMAP.md,
  TESTING.md, migration dan source aplikasi pada branch tahap aktif.

Identitas mahasiswa, nomor induk, data warga, atau foto Drive tidak disalin
ke repository. Dokumen sumber tidak diedit.

## Yang konsisten

Website publik tanpa login; mobile untuk warga dan pengurus; Laravel 13,
Flutter, REST HTTPS, PostgreSQL Neon; backend menjadi pemilik aturan/data.
Iuran Rp75.000 per rumah per bulan dibayar offline, snapshot tarif per periode,
tanpa payment gateway. Anonimitas berlaku terhadap warga lain, pengurus tetap
melihat pelapor; kewenangan admin setara; data penting diarsipkan.
M03 menambah ketahanan pengiriman efek samping tanpa mengubah aturan tersebut.

WBS menekankan hasil yang dapat diperiksa, satu PIC dan dependensi. Pembagian
Claude/backend, Grok/infra, Kimi/review dan Codex/integrasi mengikuti prinsip
itu. Penanggung jawab AI bukan pengganti PIC mahasiswa atau persetujuan mitra.

## Selisih yang dilaporkan

| Selisih | Sumber dan kondisi sekarang | Penanganan |
| --- | --- | --- |
| Lokasi administrasi | Brainstorm lama mengusulkan seluruh administrasi di web. Logbook 2, Tugas 03 dan AGENTS terbaru menempatkan layanan pengurus pada mobile; web operasional hanya Artikel/Dokumentasi. | Ikuti aturan terbaru; desain web accepted dipertahankan. |
| Persetujuan registrasi utama | User flow Tugas 03 menggambarkan seluruh permohonan menunggu persetujuan. BUSINESS_RULES sekarang mengizinkan registrasi utama valid aktif dengan data unverified; akun tambahan tetap pending approval. | Laporkan sebagai selisih bisnis nyata. Tidak diubah diam-diam pada M03–M05; rekonsiliasi dokumen/alur saat M06 dibuka. |
| Masa token | Brainstorm lama mengusulkan 30 hari; BUSINESS_RULES memakai satu token aktif, rotasi manual/mingguan, token lama langsung dicabut. | Keputusan terbaru mengikat; dokumen lama historis. |
| Tipe nominal | Tugas 03/ERD menyebut DECIMAL(14,2); migration memakai bigint rupiah bulat. | Penyesuaian ADR17; tidak mengubah tarif atau snapshot. Perbedaan tipe perlu dicantumkan pada laporan akademik. |
| Relasi akun–warga | Tugas 03 menetapkan warga_id wajib. Migration akun mengizinkan null untuk bootstrap/permohonan sebelum pendataan. | Catat penyesuaian schema existing; verifikasi invariant akun aktif dan akun tambahan pada M06/M07. |
| Nama dan tabel operasi | ERD menyebut audit_log; implementasi audit_logs, outbox_events, idempotency_requests, failed_jobs dan system_settings menambah fondasi operasi. M03 menambah failed_at untuk parkir consumer gagal. | Nama/domain direkonsiliasi ADR17 dan ERD.md; tidak mengklaim schema identik 31 tabel. |
| Penyimpanan foto | Tugas 03/Logbook 1 menargetkan cloud storage. Staging memakai volume api-storage sesuai ADR18, backup offsite belum M16. | Keterbatasan existing dilaporkan; integrasi media/cloud bukan gate M05 dan belum dianggap selesai. |
| Push | Tugas 03 membolehkan push sesudah layanan utama. Roadmap menempatkan integrasi push pada M10; FCM belum tersedia. | Tidak mengklaim push siap pada M05. |
| Dokumen metode | Tugas 03 menyebut metode Prototype mengikuti Logbook 3. Versi berkas Logbook 3 yang dibaca masih berisi isian metode/ERD/prototype kosong. | Tugas 03 memberi rancangan terisi; versi Logbook 3 ini perlu rekonsiliasi tim. Tidak menyimpulkan semua salinan Logbook 3 kosong. |
| Satu backend bisnis | Tugas 03 menyebut Laravel MVC/API; monorepo mempertahankan shell Laravel website dan aplikasi rest-api terpisah. | Selaras jika hanya rest-api memiliki database bisnis; website shell untuk render/ekspor sesuai ARCHITECTURE/AGENTS. |
| Detail UI M05 | Agar lima tab/status tetap muat pada ukuran teks besar, label navigasi dibatasi1,3 dan chip status1,5; isi layar mengikuti skala sistem. Grid ringkasan berubah menjadi baris yang tingginya mengikuti teks, status rumah pindah ke bawah subjudul, badge agenda mengecil bila perlu. | Pilihan tata letak kecil, bukan perubahan aturan bisnis; widget test layar320/360 dan skala1,3/2,0 serta screenshot emulator wajib. |
| Aset/komponen M05 | Logo mobile lama digambar manual; kini SVG gapura resmi identik website, ikon Android dikonversi dari aset itu, font OFL dibundel menggantikan unduhan runtime. Detail fitur masih Navigator, routing tingkat atas go_router; core tetap file sederhana. | Mengikuti brand dan rancangan teknis, tanpa redesign website. Monochrome icon Android13, seluruh layar detail dan UAT belum diklaim. |
| Badge debug M05 | Screenshot emulator menunjukkan banner STAGING diagonal beririsan dengan jam status bar; form/konten tetap terbaca. | Catatan kosmetik nonprod pada LIVE_RESULTS.md; badge tidak tampil pada prod. Tidak mengubah desain website atau menambah fitur bisnis. |

Selisih ini tidak menjadi izin membuka M06–M16. Daftar akan diperbarui jika
review CI/staging/mobile menemukan selisih baru. Bukti dan status penerimaan
tetap dicatat pada [PROGRESS.md](PROGRESS.md).
