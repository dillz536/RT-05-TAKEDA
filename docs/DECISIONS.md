# Keputusan dan ADR

## Prioritas sumber

1. Instruksi langsung terbaru pemilik.
2. Kebutuhan bisnis yang terverifikasi dari diskusi/logbook.
3. Baseline frontend yang diterima dan handoff terbaru.
4. Keputusan rekayasa v1 dalam paket ini.
5. Dokumen/asisten lama yang belum terverifikasi.

Konflik tidak diselesaikan dengan menyalin keputusan lama yang tampak paling teknis. SQLite/Drizzle/web-only pernah muncul dalam rancangan lama, tetapi paket kini mengikuti permintaan eksplisit website+mobile+REST API+Neon+Redis+Hostinger.

## Kebutuhan produk dipertahankan

| ID | Keputusan | Dasar |
| --- | --- | --- |
| P01 | Nama RT05 TAKEDA | Nama final proyek |
| P02 | Website publik tanpa akun, layanan warga mobile | Logbook 2 dan diskusi |
| P03 | Iuran Rp75.000/rumah/bulan, offline, tanpa gateway | Koreksi eksplisit pembayaran |
| P04 | Pengurus admin setara | Logbook 2/diskusi |
| P05 | Akun tambahan approval dan link keluarga/rumah | Logbook 2 |
| P06 | Token 6 digit acak dan rotasi | Logbook 2/diskusi |
| P07 | Anonim ke warga, terlihat admin | Logbook 2/diskusi |
| P08 | Arsip/audit/transparansi/histori ≥12 bulan | Logbook 2/diskusi |
| P09 | Approved frontend dipertahankan; CMS Artikel/Dokumentasi | Handoff repo terbaru |
| P10 | Paket konteks dan milestone dikirim di chat, tidak edit GitHub pada sesi desain | Instruksi 10 Oktober 2026 |
| P11 | Domain morriz.tech | Koreksi typo pemilik 10 Oktober 2026 |
| P12 | Jumlah developer tidak membatasi rancangan | Koreksi pemilik |
| P13 | Vercel tetap preview; production di subdomain morriz.tech (rancangan takeda.morriz.tech) pada VPS Hostinger | Instruksi pemilik di sesi M01, 10 Oktober 2026 |
| P14 | Pemilik menawarkan akses panel Hostinger/DNS lewat Chrome dan emulator Android untuk tahap berikutnya | Instruksi pemilik di sesi M01; dipakai pada M04/M05 dengan konfirmasi per tindakan eksternal |
| P15 | Kerjakan semampunya lintas milestone dengan izin VPS/Docker/PC; hemat SSD; desain mobile boleh kolaborasi Google Stitch | Instruksi pemilik sesi 3, 10 Oktober 2026 |
| P16 | Codex mengoordinasikan Claude Code, Grok, dan Kimi dengan ownership terpisah; selesaikan satu tahap dan gate sebelum maju, checkpoint bila limit atau listrik mati | Instruksi pemilik 11 Oktober 2026; mempersempit eksekusi serentak P15. Rencana di COORDINATION.md |
| P17 | Target lanjutan sampai M05 secara bertahap; cocokkan dengan planning dan Drive capstone, laporkan setiap perbedaan; tidak membuka seluruh roadmap dalam satu hari | Instruksi pemilik terbaru 11 Oktober 2026 |
| P18 | Buka M06 lalu M07 setelah M06 passed; akun utama langsung aktif dengan keluarga belum_verifikasi, tambahan menunggu persetujuan; rumah existing tidak auto-merge; email menggunakan password aplikasi tanpa Google OAuth; album tambahan sekarang; laporan pada satu folder Drive; SMTP harus terbukti sampai inbox; production/data nyata tertahan insiden credential | Rencana pemilik yang diminta diimplementasikan12 Oktober2026; memperluas batas P17 secara bertahap |
| P19 | Lanjutkan sampai M06 dan M07 melalui Orca; andalkan Claude Code untuk backend/database serta Grok untuk mobile/build/infrastruktur, Codex membantu Chrome dan koordinasi. Pemilik mengotorisasi pengelolaan akun existing GitHub, Vercel, Drive, dan VPS Hostinger dalam lingkup ini. Kimi Code memperbarui dokumentasi manusia di folder Drive Capstone/Tugas dan Project existing: user flow, use case, diagram, layar nyata, alasan dan perbandingan perubahan. Credential tetap privat; gate M06 sebelum M07 dan batas data sintetis/production tetap berlaku. | Instruksi langsung pemilik setelah pemulihan sesi12 Oktober2026 |

## ADR rekayasa v1

### ADR01 — Modular monolith dan empat komponen

Status: dipilih untuk eksekusi v1.
website/mobile/rest-api/docs pada satu Git repository; infra/ untuk dukungan.
Laravel API memiliki domain/database; web mempunyai shell renderer saja.
Alasan: mempertahankan frontend, mengurangi duplikasi bisnis, deploy terpisah tanpa microservices.

### ADR02 — Stack

Laravel 13/Eloquent/PostgreSQL Neon, Blade/Tailwind existing, Flutter Android.
Flutter adalah pilihan rekayasa paket ini; handoff sebelumnya belum menetapkan framework.
Redis queue/cache dan Docker Hostinger. Tidak memakai Express/Nest atau database bisnis kedua.
Versi pasti dipin setelah kompatibilitas diverifikasi; tidak mengubah lockfile accepted untuk mengejar versi terbaru.

### ADR03 — Same-origin API

Host takeda.morriz.tech, API /api/v1, csrf route ke API.
Alasan: menyederhanakan cookie/CSRF/CORS CMS dan konfigurasi mobile.
Staging host/data/secrets terpisah. Host ini rancangan; belum klaim DNS telah aktif.

### ADR04 — Auth

Sanctum bearer per device mobile; cookie session+CSRF CMS.
API role/status policy selalu menjadi otoritas. Tidak menggunakan Neon Auth tambahan pada v1; Neon hanya database untuk arsitektur ini.
Token registrasi bukan API token.

### ADR05 — Keuangan sinkron dan immutable

DB transaction/row locks/constraints/idempotency untuk offline payment.
V1 full-month settlement; partial/overpay ditolak karena kebijakannya belum disepakati.
Koreksi reversal/replacement, kas signed entries, audit.
Alasan: menjaga rekam asal dan mencegah retry/concurrency menggandakan uang.

### ADR06 — Redis+outbox

Antrean dan cache terpisah. Outbox durable di Postgres, jobs at-least-once, consumer idempotent.
Payment commit tidak bergantung push/email sukses.
Polling outbox tiap menit dapat menahan compute Neon tetap aktif; evaluasi biaya/interval setelah pengukuran. Jangan menjanjikan scale-to-zero sambil rutin mengakses DB.

### ADR07 — Storage

VPS persistent volume + offsite encrypted backup; filesystem adapter memudahkan pindah ke S3 bila kebutuhan muncul.
Private upload terlebih dahulu; publik hanya published media. Tidak menambah MinIO/S3 berbayar untuk mulai.

### ADR08 — Android first

Satu aplikasi warga/pengurus; APK signed melalui HTTPS. iOS/Play Store lanjutan.
Runtime Flutter berada di HP; VPS menyediakan layanan dan artifact download.

### ADR09 — Iterasi dan staging awal

M00 baseline +16 milestone lanjutan. Staging fondasi M04, production M16.
Gate berdasarkan perilaku nyata, bukan jumlah developer/jumlah LOC.

### ADR10 — Batas administratif v1

Akun utama dapat mengedit keluarga sendiri, tambahan memakai layanan setelah approval.
Grant admin hanya operator command; seluruh admin aplikasi hak setara.
House billing start/enabled manual; tidak menghapus tunggakan ketika penghuni pindah.
Detail ini dipilih sebagai default terbatas, bukan diklaim persetujuan mitra baru.

### ADR11 — Migrasi monorepo dengan git mv di repository asal

Tanggal/status: 10 Oktober 2026, diterapkan pada M01.
Keputusan: frontend dipindah ke `website/` dengan `git mv` di repository `morrispes5/RT-05-TAKEDA`, bukan ZIP/subtree ke repo baru. Dokumentasi frontend ke `docs/website/`, workflow ke `.github/workflows/website.yml` (working-directory `website`), pemeriksaan repo di `.github/workflows/repo.yml`.
Alasan: Git history dan release tag tetap satu; `git log --follow` tetap bekerja; tidak ada `.git` bersarang.
Terdampak: semua perintah web dijalankan dari `website/`; `scripts/package-frontend.ps1` kini mengarsipkan subtree `website/` dengan prefix ZIP `web/` yang sama.

### ADR12 — Jembatan vercel.json root selama transisi

Tanggal/status: 10 Oktober 2026, sementara.
Keputusan: `website/vercel.json` kanonik (`outputDirectory: preview`). `vercel.json` root (`outputDirectory: website/preview`) dipertahankan sampai pemilik mengubah Root Directory proyek Vercel menjadi `website`; `infra/scripts/check-repo.mjs` menjaga kesetaraan keduanya.
Alasan: M01 tidak boleh mengubah integrasi Vercel eksternal, tetapi merge ke `main` tidak boleh memutus preview live.
Penghapusan: setelah Root Directory diubah dan `check-live` lulus, hapus file root dalam PR terpisah. Instruksi: docs/website/VERCEL_ROOT.md.

### ADR13 — Neon: satu project, branch per lingkungan, role runtime via SQL

Tanggal/status: 10 Oktober 2026, diterapkan M02 (dev).
Keputusan: project Neon `rt05-takeda` (id raspy-sky-23605923, aws-ap-southeast-1, Postgres 17, paket gratis) dibuat atas persetujuan pemilik di sesi M02. Branch `production` (default, kosong sampai M16) dan `dev` (sintetis). Staging dibuat sebagai branch terpisah pada M04. Role `rt05_owner` = pemilik schema/migration (endpoint direct); role `rt05_app` = runtime DML (endpoint pooled), dibuat lewat SQL agar bukan anggota `neon_superuser`. Hak runtime berasal dari satu sumber `infra/sql/grant-runtime-role.sql`.
Alasan: isolasi data/credential per branch tanpa layanan berbayar; least privilege runtime. Production dapat dipindah ke project terpisah lewat ADR bila kuota/isolasi menuntut.
Catatan: Neon menolak verifier SCRAM pada CREATE ROLE ("only supports plaintext passwords"); password acak dikirim lewat TLS dan tidak dicetak.

### ADR14 — Pooler Neon tanpa named prepared statement

Tanggal/status: 10 Oktober 2026, diterapkan M02.
Trigger: `rt05:db-smoke` di endpoint pooled gagal 25P02 — pdo_pgsql mengirim DEALLOCATE SQL yang ditolak PgBouncer mode transaksi.
Keputusan: `PGSQL_ATTR_DISABLE_PREPARES=true` untuk kedua koneksi; parameter tetap terpisah (PQexecParams), bukan emulasi string. Tes CI memastikan `pg_prepared_statements` tetap 0 pada koneksi direct.

### ADR15 — TLS verify-full dan CA bundle

Tanggal/status: 10 Oktober 2026, diterapkan M02.
Keputusan: staging/production wajib `sslmode=verify-full` + `sslrootcert`; `/health/ready` (503 misconfigured) dan `rt05:db-smoke` gagal tertutup bila tidak. Parameter URL Neon mengalahkan env, sehingga kebijakan diperiksa pada konfigurasi akhir (`TlsPolicy`). PHP Windows tidak mendukung `sslrootcert=system`; dev Windows memakai salinan CA bundle pada path tanpa spasi.
Bukti: CA asli tersambung; CA palsu ditolak "certificate verify failed"; tanpa TLS ditolak Neon.

### ADR17 — Nama tabel domain mengikuti ERD v1.1 tim

Tanggal/status: 10 Oktober 2026, diterapkan M06–M12.
Trigger: ERD v1.1 (31 tabel, Drive "ERD RT 05 TAKEDA (CAPSTONE PROJECT)") ditemukan; DATABASE.md mewajibkan rekonsiliasi.
Keputusan: tabel domain memakai nama ERD v1.1 (akun, rumah, keluarga, warga, penghunian, pengaduan, aspirasi, tagihan_iuran, pembayaran_iuran, alokasi_pembayaran, transaksi_kas, notifikasi, dst). Penyesuaian rekayasa: rupiah bigint (bukan decimal), status tagihan eksplisit, pembalikan pembayaran (bukan edit), sequence nomor bukti, token terenkripsi + digest, akun.jenis utama/tambahan, permohonan_akun menyimpan nama/alamat diajukan. Tabel operasi (audit_logs, outbox_events, idempotency_requests, system_settings, failed_jobs) tetap nama Inggris; audit_logs ≙ audit_log ERD. Konten publik (profil_rt, pengurus_rt, fasilitas, artikel, album_galeri, foto_galeri) menyusul M13. Path API mengikuti docs/API.md (Inggris), field JSON mengikuti kolom (Indonesia).

### ADR18 — Deploy VPS di belakang proxy Coolify existing

Tanggal/status: 10 Oktober 2026.
Keputusan: stack RT05 (gateway Caddy, api/worker/scheduler/migrate FrankenPHP, web FrankenPHP, Redis) dijalankan sebagai project Compose sendiri yang menempel ke Traefik Coolify lewat label (jaringan `coolify`), bukan proxy kedua di 80/443. Dipicu panel Coolify :8000 tidak terjangkau dan akses shell agent ditolak; deploy dijalankan pemilik dengan `infra/scripts/deploy-vps.sh`. Credential tidak pernah diketik agent ke form web.
Media upload memakai volume `api-storage` (backup offsite masih M16).

### ADR19 — Hemat disk PC pengembang

Tanggal/status: 10 Oktober 2026, instruksi pemilik.
Keputusan: tidak memasang WSL2/Docker di PC; tes Postgres/Redis/Docker di GitHub Actions. Flutter SDK ramping (Android saja, 1,4 GB) di C:\Users\USER\dev\flutter; APK dibangun di CI (artifact) lalu dipasang ke emulator dengan adb.

### ADR16 — Tabel users ditunda ke M06

Tanggal/status: 10 Oktober 2026, diterapkan M02.
Keputusan: migration default Laravel (users/sessions/cache/jobs bigint) dihapus. users UUID dengan role/status dibuat M06; `audit_logs.actor_id` dan `idempotency_requests.actor_id` mendapat FK pada migration M06. Sesi/cache/queue memakai Redis (M03), bukan tabel database.

## Input operasional yang belum tersedia

### Otorisasi lanjutan pemilik 11 Oktober 2026

Pemilik menyediakan Chrome pada Hostinger dan mengizinkan penggunaan terminal,
emulator Android, serta akses yang diperlukan untuk deployment. Coordinator
boleh melanjutkan staging setelah gate tahap aktif lulus tanpa meminta ulang
izin akses tersebut. Urutan bertahap P16 tetap berlaku; otorisasi akses tidak
menjadi bukti gate production/UAT/signing/credential telah selesai. Penolakan
pengaman sebelumnya tetap dicatat dan tidak diakali.

| Input | Ditangani pada | Dampak |
| --- | --- | --- |
| Neon project/branches/credentials actual | M02/M04 | Tersedia untuk dev sejak M02 (ADR13); staging/production branch belum dibuat |
| VPS access/proxy/resources actual | M04 | Images/runbook bisa dibuat; deploy live perlu akses |
| DNS morriz.tech dan otorisasi record | M04/M16 | Tidak menebak IP atau mengubah apex |
| SMTP credentials | M06/M10 | Adapter local tersedia; reset real belum passed |
| FCM project/service account | M10 | Inbox jalan; push real belum passed |
| Offsite backup destination/key | M16 | Production recovery gate belum passed |
| Android app ID/signing key | M05/M15 | Pin app ID tanpa konflik; release perlu key aman |
| Kontak nyata/privacy retention mitra | M15 | Tidak memasukkan data nyata sebelum notice final |
| ERD/prototype lama lengkap | M02 | Reconcile bila tersedia; tidak mengarang isi file |
| Flutter SDK di PC pengembang | M05 | Tidak ditemukan pada inspeksi M01; Android SDK, AVD Pixel_7a/Pixel_8a, dan JBR Android Studio tersedia |
| Akses proyek Vercel preview dari connector agent | M01/M14 | Proyek tidak terlihat di team `morriz`; perubahan Root Directory dilakukan pemilik di dashboard |

## Template perubahan

ADR-NN — Judul
Tanggal/status:
Trigger:
Keputusan sebelumnya:
Keputusan baru:
Alasan/tradeoff:
Dokumen/endpoint/migration/mobile/web terdampak:
Rencana kompatibilitas dan pengujian:
Sumber instruksi pemilik:

Catat sumber apa adanya; keputusan agent tidak dilabeli sebagai persetujuan RT yang belum diberikan.
