# Sumber dan batas bukti

## Proyek

| Sumber | Kegunaan | Batas |
| --- | --- | --- |
| Logbook#1_Kelompok8.docx, lampiran sesi | Masalah awal, stakeholder, baseline Laravel/Blade/PostgreSQL | Cakupan awal berkembang pada Logbook 2 |
| Logbook#2_Kelompok8.docx, lampiran sesi | Website publik/mobile, registrasi, data warga, layanan/iuran/kas/arsip | Beberapa detail finance diklarifikasi pada percakapan |
| Screenshot checklist Logbook 2 | Persyaratan luaran akademik | Bukan spesifikasi software lengkap |
| Keputusan diskusi proyek yang berhasil ditarik | Token, pembayaran offline Rp75.000, anonimitas, mobile | Tidak mengklaim seluruh tujuh chat tersedia utuh |
| Instruksi 10 Oktober 2026 | Paket MD, milestone, Neon/Redis/worker/Hostinger, developer-independent | Tidak mengotorisasi perubahan GitHub pada sesi dokumen |
| Koreksi domain 10 Oktober 2026 | morriz.tech | Domain sebelumnya merupakan typo |

## Repository terinspeksi

### Dokumen capstone dibaca ulang 11 Oktober 2026

- [Tugas 03 Kelompok 8 — Dokumen Perancangan Solusi RT 05 Takeda](https://docs.google.com/document/d/1024MDDGIh6xQFAdJwLH4PXGsWnsEMgC6mU4bCqOVQBo/edit): dibaca melalui plugin Google Drive, versi modified 6 Oktober 2026. Kebutuhan platform, otoritas backend dan pencatatan iuran offline dipakai untuk memeriksa konsistensi, bukan mengganti keputusan v1 yang lebih baru.
- [Tugas 04 Kelompok 8 — WBS](https://docs.google.com/document/d/1RfxvC_uQNg5Tt97yguz92L_YMfDYkgSU0v5pAF2KCJs/edit): dibaca melalui plugin Google Drive, versi modified 8 Oktober 2026. Deliverable mempunyai owner dan kriteria penerimaan; ilustrasi jam/biaya bukan hasil pengukuran RT05.

Tidak ada edit, perubahan sharing, atau penyalinan identitas anggota dari Drive
ke repository pada pembacaan ini. ERD v1.1 ditemukan sebagai metadata; isi PDF
tidak dibaca ulang pada tahap M03 karena tidak ada perubahan domain bisnis.

- Repository: https://github.com/morrispes5/RT-05-TAKEDA
- Website preview: https://rt05takeda.vercel.app
- Handoff: https://github.com/morrispes5/RT-05-TAKEDA/blob/web-frontend-approved-2026-10-10/docs/FRONTEND_HANDOFF.md (sejak M01: docs/website/FRONTEND_HANDOFF.md)
- Agent instructions: https://github.com/morrispes5/RT-05-TAKEDA/blob/web-frontend-approved-2026-10-10/AGENTS.md (sejak M01 digabung ke AGENTS.md root)
- Preview behavior: https://github.com/morrispes5/RT-05-TAKEDA/blob/web-frontend-approved-2026-10-10/docs/CONTENT_PREVIEW.md (sejak M01: docs/website/CONTENT_PREVIEW.md)
- Historical QA: https://github.com/morrispes5/RT-05-TAKEDA/blob/web-frontend-approved-2026-10-10/docs/QA_STITCH_PREVIEW.md (sejak M01: docs/website/QA_STITCH_PREVIEW.md)
- Release: https://github.com/morrispes5/RT-05-TAKEDA/releases/tag/web-frontend-approved-2026-10-10

Tautan memakai tag release agar tetap valid setelah dokumen dipindah pada M01.

Inspeksi source mencakup routes, controller/content helper, composer/package,
Blade halaman utama/profil/fasilitas/kontak, preview repository/editor,
slideshow, Vercel config, dan docs handoff. Snapshot Git tree:
34e9b212a3948a52e5f705a561691fac10103ae9.

Paket ini tidak membangun ulang frontend, tidak menguji ulang deployment live, dan tidak memverifikasi server/DNS/provider.
Penelusuran halaman live melalui web retrieval tidak berhasil; status desain/preview dibaca dari source dan handoff. M01/M04 menguji runtime aktual.

## Dokumentasi resmi

Pembacaan lanjutan 11 Oktober: Logbook 1/2/3 dan brainstorm historis juga
dibaca melalui connector Drive. Daftar URL dan perbedaan terhadap keputusan
repo ada pada [CAPSTONE_ALIGNMENT.md](CAPSTONE_ALIGNMENT.md). Logbook 3 yang
dibaca masih template; tidak dianggap sebagai persetujuan metode/hasil tes.

- Laravel Sanctum: https://laravel.com/docs/13.x/sanctum
- Laravel queues: https://laravel.com/docs/13.x/queues
- Laravel scheduling: https://laravel.com/docs/13.x/scheduling
- Laravel database: https://laravel.com/docs/13.x/database
- Flutter architecture: https://docs.flutter.dev/app-architecture
- Redis persistence: https://redis.io/docs/latest/operate/oss_and_stack/management/persistence/
- Neon documentation index: https://neon.com/docs/llms.txt
- Neon Laravel migrations: https://neon.com/docs/guides/laravel-migrations
- Neon connection pooling: https://neon.com/docs/connect/connection-pooling
- Neon secure connection: https://neon.com/docs/connect/connect-securely

Dokumentasi Sanctum/queue/scheduler/Flutter/Redis dan indeks Neon diperiksa
selama penyusunan. Panduan Laravel migrations Neon ditemukan melalui sumber
resmi. Retrieval langsung halaman pooling/secure Neon terkendala renderer
text/markdown; desain koneksi juga mengikuti panduan Neon/PostgreSQL yang
tersedia melalui skill resmi. Agent implementasi memeriksa kembali konfigurasi
driver/SSL/runtime aktual sebelum memakai production.

Tidak ada angka harga/limit plan Neon/Hostinger yang diasumsikan tetap. Tidak ada
klaim kapasitas/PITR/provider delivery yang belum diuji.

## Cara menyitir di laporan akademik

Dokumen teknis ini bukan pengganti kajian jurnal pada logbook. Jika menulis
laporan kampus, pakai referensi jurnal asli yang sudah diverifikasi beserta
metadata lengkap. Jangan membuat author/tahun/hasil pengukuran dari paket ini.
