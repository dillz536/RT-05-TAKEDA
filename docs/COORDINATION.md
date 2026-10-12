# Koordinasi RT05 TAKEDA

Instruksi pemilik 11 Oktober 2026: Codex mengelola Claude Code, Grok, dan Kimi;
pekerjaan dilanjutkan dari checkpoint Claude, satu tahap dengan bukti penerimaan
sebelum tahap berikutnya. Target instruksi terbaru adalah sampai M05 secara
bertahap; setiap perbedaan terhadap planning/Drive capstone dilaporkan kepada
pemilik. Dokumen ini adalah checkpoint proyek, bukan layanan
otomasi yang dijamin terus hidup saat komputer atau Orca mati.

## Tahap aktif

**Remediasi keamanan fondasi M04, instruksi12 Oktober.** Branch
`codex/m04-runtime-credential-isolation`: Codex memperbaiki aliran migrator
dan build context; investigator/reviewer independen baca-saja. Source/runtime
patch menunggu CI/rollout; rotasi DB/APP_KEY historis tetap open, tindakan
secret-store sebelumnya ditolak dan tidak diakali. Tidak membuka M06+.

M05 PASS, PR17 merged ba3528d. Source13de24e:
77 tes dan dua APK CI lulus; emulator health HTTPS/offline/retry/brand/text
scaling lulus; review final Kimi PASS. Bukti [LIVE_RESULTS.md](evidence/m05/LIVE_RESULTS.md)
dan [CI_RESULTS.md](evidence/m05/CI_RESULTS.md). Tidak membuka fitur M06+.
PR #16 merged
`3a8be79` setelah lima cek sukses pada `9cad5de`. Staging menjalankan SHA
`12b4642` yang telah diuji; merge dokumentasi tidak memicu redeploy.
M03 lulus kode/CI pada `0d678bb` dan review final Kimi.

PR #14 telah digabung pada commit `c644b84`: perbaikan routing staging dan aset
HTTPS sudah menjadi baseline main. PR #15 merged `27a3e5b` setelah lima cek
SUCCESS pada HEAD `ea4179e`. Branch M05 sebelumnya: `codex/m05-mobile-foundation`.
Implementasi layanan M05–M12 yang sudah ada dipertahankan, tetapi status gate
masing-masing tidak dinaikkan hanya karena kode tersedia. M13–M16 belum dibuka.

| Agent | Tanggung jawab | File yang boleh diedit |
| --- | --- | --- |
| Codex | Rencana, keputusan integrasi, Git, review, tes gabungan, laporan | Dokumen utama dan perubahan integrasi setelah ownership diserahkan |
| Claude Code | Fondasi Flutter diserahkan; review independen CI PASS, sekarang idle | Laporan foundation dan CLAUDE_CI_REVIEW.md; sumber milik Codex |
| Grok | Workflow/guard diserahkan; review independen mobile PASS, sekarang idle | GROK_CI_RESULT.md dan GROK_MOBILE_REVIEW.md; workflow/guard milik Codex |
| Kimi | Audit rencana dan acceptance final M05 PASS diterima; sekarang retained user_requested | KIMI_PLAN_REVIEW.md dan KIMI_ACCEPTANCE_REVIEW.md; sesi sama dipertahankan, bila quota403 kembali tugas baru dialihkan ke Grok |

Semua bekerja pada checkout yang sama dengan ownership file terpisah. Hanya
Codex melakukan switch branch, commit, push, dan merge. Agent meminta perubahan
lintas ownership melalui mailbox coordinator; tidak mengedit diam-diam.
Rencana dan gate tahap ini: [M05_PLAN.md](evidence/m05/M05_PLAN.md).

## Kontrak integrasi M03

- Transaksi domain, audit, dan event tetap sinkron di PostgreSQL.
- Dispatcher mengambil lease, lalu menyerahkan ID event ke antrean Redis.
  `published_at` diisi setelah enqueue; `completed_at` setelah consumer berhasil.
- Consumer membuat inbox dan completion secara atomik; event/penerima tetap unik.
- Lease kedaluwarsa dan event published tanpa completion dapat dipublikasikan
  ulang. Redis gagal tidak menghapus event atau membalik transaksi bisnis.
- Redis antrean memakai AOF/noeviction; Redis cache terpisah memakai TTL/eviction.
  Volume antrean existing tidak diganti dengan volume kosong saat konfigurasi diubah.
- Worker memakai queue sesuai fungsi dan timeout di bawah retry-after 180 detik.
  Heartbeat berasal dari loop worker/scheduler yang benar-benar berjalan.
- Semua drill menggunakan PostgreSQL/Redis lokal CI dan data sintetis. Guard
  database `*_test` lokal tetap wajib; tidak menggunakan Neon production untuk tes.

## Gate sebelum maju

| Gate M03 | Bukti yang diperlukan |
| --- | --- |
| Enqueue dan consumer terpisah | Inbox belum ada setelah enqueue; ada sesudah worker memproses |
| Duplikat | Dua delivery menghasilkan tepat satu inbox per penerima |
| Kill/restart worker | Event tertunda diproses setelah worker hidup kembali |
| Lease/replay | Event stale published tanpa completion diproses ulang |
| Redis AOF restart | Job tertunda tetap ada setelah restart instance CI |
| Kehilangan antrean | Outbox memulihkan job yang dihapus hanya dari queue test |
| Rollback domain | Tidak ada event/inbox tersisa dari transaksi yang dibatalkan |
| Isolasi/heartbeat | Antrean dan cache berbeda; tidak ada port Redis publik; heartbeat dapat diuji |

Hasil CI, laporan agent, dan review coordinator dicatat di
[PROGRESS.md](PROGRESS.md). Kode atau mock tidak membuktikan drill real Redis;
[CAPSTONE_ALIGNMENT.md](CAPSTONE_ALIGNMENT.md) mencatat selisih Drive/planning.
CI lokal juga tidak membuktikan deployment VPS. Jika gate M03 belum lulus,
tetap `in_progress` atau `blocked` dengan alasan yang spesifik.

## Urutan sesudah tahap aktif

| Tahap | Fokus setelah dependency terbukti |
| --- | --- |
| M04 | Deploy staging fondasi, Neon read/write sintetis, heartbeat dan persistence di VPS |
| M05 | API staging dari Android, HTTPS, state/error dan dasar aksesibilitas |
| M06–M09 | Tutup gate identitas, keluarga, pengaduan/media, lalu aspirasi secara berurutan |
| M10 | Agenda/inbox serta integrasi SMTP/FCM nyata dengan input yang disediakan |
| M11–M12 | Tagihan, concurrency transaksi offline, reversal, rekonsiliasi dan ekspor |
| M13 | Backend konten publik dan mobile pengurus |
| M14 | CMS/publikasi website melalui API dengan desain baseline tetap |
| M15 | Integrasi, perangkat nyata, UAT mitra dan kandidat APK signed |
| M16 | Production, backup/restore, rollback, monitoring dan handover |

Tabel ini adalah urutan tindak lanjut, bukan izin menjalankan seluruh roadmap
serentak. Detail penerimaan tetap [ROADMAP.md](ROADMAP.md) dan
[TESTING.md](TESTING.md). Provider, signing key, data mitra, dan hasil UAT tidak
dikarang. Deployment production menunggu gate serta penyelesaian insiden
credential pada checkpoint sebelumnya.

## Izin, plugin, limit dan pemulihan

- Izin pemilik mencakup koordinasi ketiga agent, pembacaan konteks, implementasi,
  tes lokal/CI dan penanganan izin rutin sesuai tahap aktif. Coordinator menilai
  tindakan konkret sebelum menyetujui prompt izin; tidak memilih semua opsi
  persetujuan secara membabi buta.
- Pemilik juga menyediakan Chrome Hostinger dan mengizinkan terminal/emulator
  serta akses deployment. Sesudah gate M03 lulus, lanjut staging M04 dengan
  verifikasi nyata; tidak meminta ulang izin akses yang sudah diberikan.
- Penolakan pengaman otomatis tidak diakali. Insiden credential sebelumnya tetap
  tercatat sampai ada bukti rotasi; keberhasilan build bukan bukti credential aman.
- Plugin digunakan sesuai kebutuhan: GitHub untuk PR/CI; Google Drive untuk
  dokumen capstone; alat lain ketika diperlukan tahap aktif. Plugin terpasang
  tidak otomatis terautentikasi atau memberi semua agent akses yang sama.
- Dokumen capstone Tugas 03 dan Tugas 04 Kelompok 8 telah dibaca via Google Drive
  pada sesi ini. WBS menuntut deliverable dengan ownership dan bukti, bukan daftar
  kode yang diklaim selesai. Dokumen kampus tidak ditulis ulang pada sesi ini.
- Jika kuota agent habis, simpan file/status, perintah terakhir, gate yang belum
  diuji dan langkah berikutnya. Instruksi terbaru pemilik (11 Oktober 2026):
  tugas Kimi yang terkena limit dialihkan ke Grok melalui Dispatch baru,
  setelah kepemilikan file lama dihentikan dan checkpoint dibaca. Sesi Kimi
  dipertahankan; tidak mengganti akun atau memalsukan completion.
- Setelah listrik/Orca mati: periksa `status`, `terminal list`, `run-use`,
  `task-list`, mailbox, Git dan checkpoint; ambil handle terminal baru. Jangan
  memakai ID Dispatch lama untuk completion baru atau mengulang deploy/migration.
- Run koordinasi: `run_2d500f79d407`. Handle terminal dan Dispatch bersifat runtime;
  gunakan hasil discovery terbaru, bukan nilai yang tersimpan pada dokumen ini.

## Laporan per tahap

Catat perubahan/file/commit, task tiap agent, perintah dan hasil nyata, link CI,
review independen, gate passed/blocked, batas lingkungan, serta tahap berikutnya.
Sesi berakhir pada checkpoint yang bisa dilanjutkan tanpa mengulang pekerjaan.
