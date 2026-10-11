# Progress — RT05 TAKEDA

Updated: 11 Oktober 2026.

## Status

Paket konteks diimpor pada M01. API/mobile dan staging telah dibangun pada sesi
berikutnya; hasil dan keterbatasan ada di checkpoint di bawah. Status gate tidak
ditentukan hanya oleh tersedianya kode.

| Milestone | Status | Bukti/ketergantungan |
| --- | --- | --- |
| M00 | accepted_baseline | Handoff repo menyatakan desain accepted; M01 rerun QA baseline pada 34e9b21 dan dari website/ (lihat M01) |
| M01 | passed | PR #10 merged (02d92a1); production alias rt05takeda.vercel.app 19/19 HTML + 26 aset cocok setelah merge |
| M02 | passed | PR #11 merged (321db0d) |
| M03 | in_progress | Tahap aktif: mengganti dispatcher inbox sinkron dengan Redis consumer/replay dan menguji recovery; belum ada bukti gate recovery lulus |
| M04 | in_progress | Staging deploy/smoke sesi 5–6 sudah berhasil; patch PR #14 merged c644b84; gate M03, live write/heartbeat/restart masih perlu bukti |
| M05 | in_progress | Analyze/13 tes historis dan emulator warga/pengurus sesi 4; APK staging terpasang sesi 6, login staging belum dibuktikan |
| M06 | in_progress | Registrasi/login/token/approval + tes CI lulus; SMTP reset belum (log) |
| M07 | in_progress | Rumah/keluarga/akun/permohonan API + layar mobile; tes CI lulus |
| M08 | in_progress | Pengaduan + foto privat + anonim + status; tes CI lulus |
| M09 | in_progress | Aspirasi terpisah; tes CI lulus |
| M10 | in_progress | Agenda/ICS/Google Calendar, pengumuman, inbox; push FCM blocked (tanpa project Firebase) |
| M11 | in_progress | Tarif/tagihan idempotent/catch-up/transparansi; tes CI lulus |
| M12 | in_progress | Pembayaran offline/idempotency/pembalikan/koreksi/kas/CSV; tes CI lulus |
| M13 | planned | CMS API belum operasional |
| M14 | planned | Website real publication belum terintegrasi |
| M15 | planned | UAT/full QA belum dilakukan |
| M16 | planned | Production baru belum dirilis |

M00 memakai label accepted_baseline khusus, bukan status passed hasil tes baru. M01–M16 menggunakan planned/in_progress/passed/blocked.

## Milestone aktif

**M03 — Redis/worker/scheduler/outbox recovery**, branch `codex/m03-async-recovery`.
Pemilik meminta pengerjaan bertahap dengan empat agent (P16), bukan membuka
seluruh roadmap serentak. [COORDINATION.md](COORDINATION.md) mencatat ownership,
gate, pemulihan setelah limit/listrik mati, dan urutan berikutnya.

PR #14 digabung pada 11 Oktober 2026 setelah lima cek SUCCESS dan review patch;
merge commit `c644b84`. `main` kini memuat routing/aset HTTPS yang sudah dipakai
staging. Tidak ada redeploy server dari aksi merge ini.

### M02 — Laravel API dan Neon foundation

- Status: passed (10 Oktober 2026), dengan catatan Postgres lokal di PC pemilik belum tersedia (lihat limitations).
- Branch/commit/PR: `codex/m02-api-neon-foundation` dari main 02d92a1; PR https://github.com/morrispes5/RT-05-TAKEDA/pull/11.
- Scope dan FR/BR: fondasi untuk FR18 (audit), FR23 (health), DATABASE.md/API.md/SECURITY.md konvensi; tidak ada fitur warga.
- Dependency verified: M01 merged; live preview cocok setelah merge (`check-live`: 19/19 HTML, 26 aset, HP PASS, 0 error).
- Runtime: Laravel 13.35.0 (sama dengan website), PHP 8.4.13 + pdo_pgsql/pgsql/intl/sodium (diaktifkan di php.ini lokal; backup `php.ini.bak-rt05-m02`), libpq 16.9, Postgres 17 (CI dan Neon 17.11).
- File berubah: `rest-api/` (skeleton Laravel dipangkas: tanpa frontend/Vite, users default, sessions/cache/jobs tables, rute storage otomatis), `docs/openapi.yaml`, `redocly.yaml`, `.github/workflows/api.yml`, `infra/compose.local.yml`, `infra/sql/grant-runtime-role.sql`, `infra/docker/postgres/init/01-rt05-roles.sql`, `infra/scripts/check-repo.mjs` (DSN lokal & SQL sumber diizinkan), `.gitignore`, docs DECISIONS (ADR13–16), DATABASE, ERD, README, infra/README, PROGRESS.
- Endpoint: `GET /health/live`, `GET /health/ready`, `GET /api/v1` (implemented). 102 operasi lain `x-status: planned` per milestone dan menghasilkan 404 JSON.
- Migration: failed_jobs, audit_logs (append-only trigger), outbox_events, idempotency_requests, system_settings.
- Environment/data: Neon project `rt05-takeda` (raspy-sky-23605923, aws-ap-southeast-1, PG17, gratis) dibuat dengan persetujuan pemilik; branch `production` (kosong) dan `dev` (br-crimson-art-b31mz59n). Data hanya sintetis/rollback; tidak ada data warga.
- Commands dan actual results:

  | Command | Hasil |
  | --- | --- |
  | CI `api.yml` (Postgres 17 service, role dari init SQL): `php artisan test` | 33 passed (370 assertions), 0 risky |
  | CI `rt05:db-smoke --connection=pgsql` / `pgsql_migrations` | rt05_app: tulis+baca ok, sisa 0, DDL denied; rt05_migrator: ok |
  | CI `npx @redocly/cli lint docs/openapi.yaml` | valid, 0 warning (operation-4xx-response off, ADR di redocly.yaml) |
  | Neon dev `composer migrate` (direct, rt05_owner) | 5 migration DONE |
  | Neon dev `rt05:db-grant-runtime-role rt05_app` | hak DML diterapkan |
  | Neon dev `rt05:db-smoke` (pooled, rt05_app) | server 17.11, endpoint pooled, sslmode verify-full, tulis+baca ok, sisa 0, DDL denied |
  | Neon dev `rt05:db-smoke --connection=pgsql_migrations` (direct) | ok, sisa 0 |
  | `APP_ENV=staging rt05:db-smoke` | exit 0 dengan verify-full; dengan URL `sslmode=require` exit 1 "sslmode harus verify-full" |
  | TLS negatif ke Neon | CA palsu: "certificate verify failed"; sslmode=disable: ditolak Neon "connection is insecure" |
  | `php artisan serve` → Neon dev | /health/live 200 ok; /health/ready 200 database ok; /api/v1 200 + request_id; /api/v1/auth/register 404 NOT_FOUND; Cache-Control no-store; body tanpa host/role |
  | Role check | rt05_app anggota neon_superuser: 0 |
  | `node infra/scripts/check-repo.mjs` | OK; uji negatif DSN Neon berpassword tertangkap |

- Evidence path: log CI PR #11 (API verification), tabel di atas.
- Acceptance gates passed: migration Postgres CI + Neon dev direct sukses; runtime pooled read/write sintetis terverifikasi; SSL verify-full terbukti (positif + negatif); client publik tidak menerima DSN (tes no-leak health/error); health/error schema diuji terhadap OpenAPI; role minimal (DDL denied, bukan superuser).
- Acceptance gates blocked/failed: tidak ada untuk gate ROADMAP. Catatan: Postgres lokal pada PC pemilik belum berjalan (Docker Desktop butuh WSL2 yang belum terpasang); tes Postgres dijalankan di CI.
- Security/finance/privacy implications: role runtime tanpa DDL; audit append-only; error tanpa stack trace/host; rute `storage/{path}` bawaan dimatikan; tes menolak database selain *_test lokal sehingga tidak menyentuh Neon.
- External mutation performed dan dasar otorisasi: merge PR #10 (instruksi "oke merge"); pembuatan project Neon rt05-takeda + branch dev + role rt05_app + migration fondasi di branch dev (persetujuan eksplisit di pertanyaan sesi M02); push branch + PR #11. Tidak ada perubahan Vercel/DNS/VPS. Branch production Neon tidak disentuh.
- Known limitations:
  - Tidak ada Postgres di PC pemilik sampai WSL2/Docker atau Postgres portabel disiapkan.
  - `sslrootcert=system` tidak didukung PHP Windows; dev memakai salinan CA bundle Git di `%USERPROFILE%\.rt05\ca-bundle.crt` (path tanpa spasi). Bundle salinan tidak otomatis ter-update.
  - `pg_prepared_statements` via pooler menampilkan statement milik pooler; tes nol-prepared hanya berarti pada koneksi direct.
  - Password Neon ada di `rest-api/.env` lokal (di-gitignore). Credential owner pernah tampil di keluaran tool agent saat diambil; pertimbangkan reset password rt05_owner branch dev setelah sesi.
  - FK actor_id ke users menunggu M06. Redis/queue/session menunggu M03.
- ADR/doc updates: ADR13–ADR16; DATABASE.md, ERD.md (implementasi M02), rest-api/README, infra/README, README root, openapi.yaml.
- Next milestone: M03 (Redis queue/cache, worker, scheduler, outbox dispatcher). Prasyarat lokal Redis juga butuh Docker/WSL atau Redis di CI.

### M01 — Monorepo foundation

- Status: passed (10 Oktober 2026). Gate lokal, CI GitHub, dan preview Vercel PR terbukti. PR belum di-merge; setelah merge, Root Directory Vercel diubah pemilik (docs/website/VERCEL_ROOT.md).
- Branch/commit/PR: `codex/m01-monorepo-foundation` dari `main` 34e9b212a3948a52e5f705a561691fac10103ae9. Commit: 30e3903 (rename murni 219 file, 100% identik), 8b36540 (adaptasi monorepo), 350390a (perbaikan script paket), 1ccc932 (progress), commit penutupan ini. PR: https://github.com/morrispes5/RT-05-TAKEDA/pull/10.
- Tanggal: 10 Oktober 2026.
- Scope dan FR/BR: FR01 (rute/layout baseline), BR01/BR02 dipertahankan; tidak ada fitur baru.
- Dependency verified: M00. `git status` bersih di `main`; tag `web-frontend-approved-2026-10-10` = HEAD; branch remote `claude/hopeful-ptolemy-acq64b` tidak memuat commit tambahan; tidak ada perubahan pengguna yang belum di-commit. Repo publik, `main` tanpa branch protection.
- Runtime: PHP 8.4.13, Composer 2.8.12, Node 22.16.0, npm 10.9.2, Git 2.54, gh 2.97, Docker 29.6.1 (daemon tidak berjalan saat inspeksi). Flutter/Dart tidak ditemukan. Android SDK (platforms 34–36.1, build-tools 36.x), emulator + AVD Pixel_7a/Pixel_8a, JBR Android Studio tersedia; `java` di PATH = 1.8, `JAVA_HOME` kosong.
- File berubah:
  - Dipindah: source/preview/scripts/tests/config/lockfile → `website/`; dokumen/bukti/screenshot frontend → `docs/website/`; `.github/workflows/frontend.yml` → `website.yml`.
  - Baru: `docs/*.md` (18 dokumen paket), `docs/website/VERCEL_ROOT.md`, `docs/evidence/m01/`, `.github/workflows/repo.yml`, `infra/scripts/check-repo.mjs`, `infra/README.md`, `mobile/README.md`, `rest-api/README.md`, `vercel.json` root (jembatan), `.gitignore` root.
  - Diubah: `README.md`/`AGENTS.md` root (gabungan paket + AGENTS frontend), `website/README.md`, `website/scripts/package-frontend.ps1`, `docs/FRONTEND.md` (koreksi commit/tree), `docs/SOURCES.md` (tautan tag), `docs/DECISIONS.md` (P13, P14, ADR11, ADR12), tautan internal `docs/website/*`.
  - Tetap di root: `.claude/`, `.editorconfig`, `.gitattributes`.
- Endpoint/migration/config baru: tidak ada endpoint/migration. Config: workflow `website.yml` (working-directory `website`, path filter `website/**`), `repo.yml`.
- Environment/data: lokal Windows; tanpa database; tanpa data warga.
- Commands dan actual results:

  | Command (cwd) | Hasil |
  | --- | --- |
  | Baseline di root, commit 34e9b21: `npm.cmd run build:preview`, `git status -- preview` | 19 rute diekspor; `preview/` tanpa diff |
  | Baseline: `php artisan test`, `php vendor/bin/pint --test` | 9 tes/155 assertions passed; Pint passed |
  | `node scripts/check-live.mjs https://rt05takeda.vercel.app` (sebelum migrasi) | 19/19 HTML exact, 26 aset cocok, 404 untuk rute tak dikenal, HP PASS, 0 error browser |
  | `curl -I` live | 200, `X-Robots-Tag: noindex`, `/pengurus/konten` 307 → `/pengurus` |
  | `website/`: `npm.cmd run build:preview` | 19 rute; 67 blob `website/preview/` identik dengan blob `preview/` baseline |
  | `website/`: `php artisan test`; Pint | 9/155 passed; passed |
  | `website/`: `php artisan route:list` | Rute tetap `/`, `/profil`, `/fasilitas`, `/dokumentasi/{slug}`, `/artikel/{slug}`, `/kontak`, `/pengurus/*`; tidak ada prefix `/website` |
  | `website/`: `npm.cmd run test:preview` (Playwright Chromium) | 95 kasus responsif, 41 Axe, 33 tautan internal, 0 error browser; interaksi, editor (artikel/album/persistensi/isolasi/recovery/text safety/reset, 0 network write), slideshow 3 detik semua PASS |
  | `website/`: `npm.cmd audit` | 0 vulnerabilities |
  | Clone bersih branch: `node infra/scripts/check-repo.mjs`, composer/npm install, build, test, Pint, `git diff --exit-code -- preview` | Semua lulus; preview clean |
  | Clone bersih: `scripts/package-frontend.ps1` | 201/201 file `website/` cocok blob Git; ZIP berawalan `web/`, tanpa `docs/`/`mobile/` |
  | Uji negatif `check-repo.mjs` (DSN berpassword, `.git` bersarang, tautan rusak) | Exit 1, ketiganya tertangkap; dibersihkan |
  | Parse YAML `.github/workflows/*.yml` (paket `yaml` di scratchpad) | Valid; `website.yml` 13 step working-directory `website`; `repo.yml` 3 step |
  | `git log --follow -- website/README.md` | History berlanjut ke commit sebelum migrasi |
  | `git push -u origin codex/m01-monorepo-foundation`; `gh pr create` | PR #10 dibuat, mergeable/clean |
  | GitHub Actions run 38045547706 (Repository checks / hygiene) | pass |
  | GitHub Actions run 38045547743 (Website verification / verify) | pass: 19 rute, 95 responsif, 41 Axe, 0 error browser |
  | Vercel deployment PR (dpl_7TmjL5n7RZkFdBiQF2uchf558nEQ), Root Directory belum diubah | Ready; dilayani lewat jembatan `vercel.json` root |
  | Fetch + SHA-256 seluruh 67 file preview PR dari Chrome pemilik (preview dilindungi SSO Vercel) | 66 exact; `/` cocok setelah membuang script toolbar Vercel Live yang disisipkan Vercel; 404 untuk rute tak dikenal; `/pengurus/konten` redirect; `X-Robots-Tag: noindex` |

- Evidence path: `docs/evidence/m01/local-qa.json`, `docs/evidence/m01/live-web-before-migration.json`, `docs/evidence/m01/pr10-ci-and-preview.json`.
- Acceptance gates passed: struktur normal tanpa `.git` bersarang; routes/desain unchanged; preview export sesuai source; test web relevan lulus; workflow valid (dijalankan di GitHub Actions); credential tidak ikut (check-repo); dokumen/prompt dipasang; PROGRESS memuat commit baseline; konfigurasi Vercel eksternal yang belum diubah dicatat terpisah (VERCEL_ROOT.md).
- Acceptance gates blocked/pending: tidak ada untuk M01. Tindak lanjut di luar gate: merge PR #10, ubah Root Directory Vercel, hapus jembatan `vercel.json` root.
- Security/finance/privacy implications: tidak ada data warga/credential. `.gitignore` root menolak `.env*`, kunci, signing, dump.
- External mutation performed dan dasar otorisasi: push branch `codex/m01-monorepo-foundation` dan pembuatan PR #10 atas instruksi langsung pemilik ("push aja terus bikin PR nya"). Tidak ada merge, perubahan setelan Vercel, DNS, atau VPS. Baca saja: GitHub API, Vercel API, HTTP GET preview, fetch preview PR melalui Chrome pemilik.
- Known limitations:
  - Proyek Vercel preview tidak terlihat dari connector agent pada team `morriz`; Root Directory aktual belum dibaca. Jembatan `vercel.json` root menjaga preview sampai pemilik mengubahnya (docs/website/VERCEL_ROOT.md).
  - Commit 30e3903 sendiri berisi workflow dengan path lama; branch dinilai sebagai satu PR.
  - Preview PR Vercel memakai Deployment Protection; `scripts/check-live.mjs` dari luar mendapat 302 SSO. Verifikasi preview PR memakai sesi Chrome pemilik. Alias production `rt05takeda.vercel.app` publik dan tetap dapat diperiksa dengan `check-live`.
  - Connector Vercel agent tidak berizin membaca deployment proyek `morrizshkki/rt05takeda` (403).
  - Flutter SDK belum terpasang (prasyarat M05).
- ADR/doc updates: ADR11, ADR12, P13, P14; koreksi FRONTEND.md (34e9b21 adalah commit, tree 37510826bfedb447ea572f0b72cd5577f7bd0270).
- Next milestone: M02 (Laravel API + Neon dev). Tutup M01 setelah push/PR: CI hijau dan preview Vercel PR cocok dengan `check-live`.

## Template laporan milestone

### MNN — Nama

- Status:
- Branch/commit/PR:
- Tanggal:
- Scope dan FR/BR:
- Dependency verified:
- File berubah:
- Endpoint/migration/config baru:
- Environment/data:
- Commands:
- Actual results:
- Evidence path:
- Acceptance gates passed:
- Acceptance gates blocked/failed:
- Security/finance/privacy implications:
- External mutation performed dan dasar otorisasi:
- Known limitations:
- ADR/doc updates:
- Next milestone:

### Sesi 3 (10 Oktober 2026) — layanan inti, mobile, artefak deploy

- Instruksi pemilik: kerjakan semampunya lintas milestone (izin VPS/Docker/PC), hemat SSD, desain mobile boleh kolaborasi Google Stitch. Dicatat sebagai P15.
- Branch `codex/m06-m12-layanan-inti`, PR https://github.com/morrispes5/RT-05-TAKEDA/pull/12 (belum merge).
- Logbook #1–#4, Tugas 03/04, ERD v1.1 (31 tabel) dibaca dari Google Drive. Tabel domain mengikuti nama ERD v1.1 (ADR17 rencana): akun, token_registrasi, permohonan_akun, rumah, pemilik_rumah, keluarga, warga, penghunian, kategori/pengaduan/foto/riwayat, aspirasi/riwayat, agenda, pengumuman, notifikasi, preferensi, tarif/tagihan/pembayaran/alokasi iuran, transaksi_kas/riwayat. Penyesuaian v1: rupiah bigint, outbox/idempotency/audit_logs.
- API: 82 route implemented (OpenAPI 1.0.0-m12, 33 planned: konten publik CMS M13/M14, media terpisah, device push, app-version).
- Bukti:
  - CI API (Postgres 17, role runtime): 71 tes lulus termasuk identitas, keuangan (snapshot tarif, multi-bulan, parsial ditolak, idempotency replay/409, alokasi ganda ditolak DB, pembalikan sekali, koreksi atomik, ledger immutable), layanan (anonimitas feed/detail/riwayat/notifikasi, edit pemilik, versi 409, foto re-encode & spoof 415, outbox dedup).
  - Smoke HTTP lokal → Neon dev: 24/24 lulus (login, keluarga, iuran Rp75.000, transparansi tanpa PII, saldo kas 530.000, anonim, 403 warga, akun menunggu, token no-store, AMOUNT_MISMATCH, replay, 409, pembalikan, inbox, Google Calendar).
  - CI Infra images: compose valid, image API + website build, smoke gateway (/health/live, /api/v1, /profil, 404 JSON) lulus.
  - Mobile: `flutter analyze` bersih, `flutter test` 13 lulus (lokal); CI build APK debug berjalan.
- Bug nyata ditemukan & diperbaiki: catch-up tagihan melewatkan bulan berjalan (konversi Asia/Jakarta→UTC); timestamptz(0) membulatkan ke atas sehingga outbox tertahan; FK self-reference sebelum PK; Sanctum provider tidak terdaftar karena --no-scripts.
- Lingkungan PC: Flutter di C:\Users\USER\dev\flutter (1,4 GB, Android saja). WSL/Docker lokal sengaja TIDAK dipasang (hemat SSD); tes Postgres/Redis/Docker di GitHub CI. Emulator Pixel_8a dinyalakan.
- Neon dev di-reset (migrate:fresh, branch dev sintetis) dan di-seed `rt05:demo-seed`; password akun demo hanya ada di `/tmp/demo-seed.txt` lokal (Git Bash), tidak di repo.
- VPS: Hostinger KVM 2 srv2030339.hstgr.cloud 187.77.113.136, Ubuntu 24.04 + Coolify. SSH dari agent ditolak pengaman otomatis; port 8000 (panel Coolify) tidak terjangkau dari luar. DNS morriz.tech: @ → 2.57.91.91, www CNAME; record `takeda` BELUM dibuat (sesi terhenti karena batas pemakaian).
- Blocked/tersisa: DNS takeda + takeda-staging; deploy Coolify (butuh URL/akses panel Coolify; credential production dimasukkan pemilik sendiri ke Coolify); branch Neon production role rt05_app; SMTP; FCM; signing key rilis; website CMS ke API (M13/M14); UAT.

### Sesi 4 (10 Oktober 2026) — emulator, DNS, siap deploy

- PR #12 merged (00cd078) setelah API, Infra images, Mobile, Repository checks hijau.
- Emulator Pixel_8a: APK CI terpasang; login warga demo → beranda (iuran Oktober Belum dibayar), iuran (Agustus/September Lunas), layanan (pengaduan anonim "Identitas disembunyikan"), akun/keluarga; login pengurus → ringkasan (saldo Rp530.000, 3 rumah, 1 permohonan) → catat iuran Oktober A-1 → saldo Rp605.000, rekap 1 lunas/2 belum. Bukti: docs/evidence/mobile/01–08.
- DNS: A takeda dan takeda-staging → 187.77.113.136 dibuat lewat hPanel (izin pemilik); resolusi 8.8.8.8 terverifikasi; HTTPS ke host menjawab 503 dari proxy Coolify (belum ada aplikasi).
- Neon: branch staging (dari dev, data demo) dibuat; role rt05_app di branch production dibuat via SQL (bukan neon_superuser). Env server ditulis ke infra/env/.env.staging dan .env.production (gitignored, tidak dicetak).
- Deploy: infra/compose.vps.yml + infra/scripts/deploy-vps.sh (ADR18). Menunggu pemilik menjalankan script di Konsol web Hostinger (agent tidak menjalankan perintah shell di server).
- Keterbatasan: staging memakai password rt05_app yang sama dengan dev (ALTER ROLE ditolak di branch turunan); push FCM, SMTP, CMS website→API (M13/M14), signing key rilis, UAT belum.

### Sesi 5 (11 Oktober 2026) — deploy staging lewat Konsol web

- Otorisasi langsung pemilik: jalankan instruksi deploy staging dari Claude melalui Chrome dan beri kabar setelah selesai. Target hanya `takeda-staging.morriz.tech` / project `rt05-staging`.
- Baseline source server/lokal: `53a8b10c9156fa6dcbd57d623863efb238f39d64`. Perbaikan lokal disimpan pada branch `codex/m04-staging-routing`; server menjalankan baseline tersebut dengan patch empat file yang sama. Patch belum berada pada `origin/main`.
- Build pertama lima image berhasil (~311 detik), deploy script exit 0. Migrasi: `Nothing to migrate`; hak DML role runtime diterapkan. Env server berada di `/root/.rt05/.env.staging`, mode 600; direktori mode 700.
- Dua masalah live ditemukan dan diperbaiki: label Traefik berbentuk mapping tidak menginterpolasi nama label sehingga HTTPS menjawab 503; chain proxy tidak mempertahankan HTTPS sehingga URL aset HTTP diblokir browser. Label kini memakai list `key=value`; gateway mempercayai proxy privat; website menerima `TRUSTED_PROXIES` dan APP_ENV staging.
- File implementasi: `infra/compose.vps.yml`, `infra/compose.production.yml`, `infra/docker/gateway.Caddyfile`, `website/bootstrap/app.php`. Hanya gateway/web staging dibuat ulang setelah perbaikan; tidak ada operasi mutasi pada container layanan lain, DNS, firewall, atau production.
- Verifikasi 07:31 WIB: HTTPS valid tanpa bypass; `/health/live`, `/health/ready`, `/`, `/profil`, `/api/v1` semuanya HTTP 200; ready mengembalikan database ok. Browser Chrome memuat CSS/JS/gambar HTTPS dan navigasi profil berhasil. API/web/Redis healthy; worker/scheduler Up; enam container staging restart count 0.
- Lokal: `php artisan test --compact` lulus 9 tes/155 assertion; pemeriksaan repo dan `git diff --check` lulus. Caddy validation, Compose config, build web ulang (~11 detik) berhasil.
- Insiden rahasia: setelah env ditempel dengan terminal echo off, pembacaan AX browser masih mencatat nilai textarea dalam keluaran alat. Tidak ada nilainya pada source atau evidence tersimpan; clipboard dibersihkan. Rotasi password role database dan APP_KEY staging diperlukan sebagai tindak lanjut; password runtime dev yang sama perlu ditangani bersama. Rotasi belum dilakukan.
- Handoff lengkap: [docs/evidence/m04/STAGING_DEPLOY_2026-10-11.md](evidence/m04/STAGING_DEPLOY_2026-10-11.md). Jangan menjalankan ulang script yang melakukan reset ke `origin/main` sebelum patch disatukan ke main, karena akan mengembalikan dua bug deploy.
- Batas hasil: deploy/smoke staging selesai; gate M04 menyeluruh (Neon write, job/heartbeat, restart persistence) dan M15/M16 belum dinilai ulang pada sesi ini.

### Sesi 6 (11 Oktober 2026) — verifikasi staging, APK staging, temuan rahasia

- Verifikasi dari PC: `/health/ready` 200 (database ok), `/health/live`, `/api/v1`, `/`, `/profil` 200; `POST /api/v1/auth/mobile-login` tanpa body → 422, `GET /api/v1/auth/me` tanpa token → 401. `https://takeda.morriz.tech` masih sertifikat default Traefik (production belum di-deploy).
- Patch routing sesi 5 dibawa ke main lewat PR #14 (branch `codex/m04-staging-routing`).
- APK debug dengan `API_BASE_URL=https://takeda-staging.morriz.tech` dibangun via workflow_dispatch (run 38099378306, sukses), dipasang ke emulator Pixel_8a; layar login tampil. Bukti: docs/evidence/mobile/09-login-staging.png. Login ke staging belum diuji agent (credential ke host non-lokal diketik pemilik sendiri). Catatan: kunci debug CI berbeda per run, jadi APK lama harus di-uninstall sebelum memasang yang baru.
- Temuan: password `rt05_owner` di `.env.staging` identik dengan `.env.production` (role disalin saat branch Neon dibuat), sehingga insiden sesi 5 juga mengenai credential owner production. Rotasi oleh agent ditolak pengaman (penulisan secret store); **blocked → pemilik**: reset password `rt05_owner` di branch production (dan staging/dev) serta buat APP_KEY production baru sebelum deploy production. Langkah ada di docs/DEPLOYMENT.md (Rotasi credential).

## Checkpoint sesi

### Sesi 7 (11 Oktober 2026) — koordinasi dan pemulihan M03 setelah listrik mati

- Instruksi terbaru: Codex mengelola Claude Code, Grok, dan Kimi dengan ownership
  file terpisah, satu milestone beserta gate sebelum maju; target sampai M05
  bertahap, semua perbedaan planning/Drive capstone dilaporkan. Pemilik menyediakan
  Chrome Hostinger dan mengizinkan terminal/emulator serta akses deployment.
  Otorisasi dicatat di DECISIONS dan COORDINATION; tidak meminta ulang akses.
- Run Orca `run_2d500f79d407` dipulihkan dan coordinator diikat ulang pada runtime
  setelah listrik mati. Sesi Claude yang sudah dipulihkan pemilik dipakai kembali;
  tidak mereset konteks, provisioning, database, atau pekerjaan pengguna.
- PR #14 diverifikasi lima cek SUCCESS, ditinjau, lalu merged dengan HEAD
  `8a723f9` ke merge commit `c644b84`. Branch `codex/m03-async-recovery`
  dibuat dari baseline itu. Server tidak berubah akibat merge lokal/GitHub.
- Pembagian: Claude `rest-api/` (dispatcher, job, failure parking, heartbeat dan
  tes); Grok `infra/` serta workflow API/infra (Redis terpisah, worker, recovery
  drill); Kimi laporan penerimaan independen; Codex Git, kontrak, dokumen dan
  verifikasi gabungan. Tidak ada agent lain mengubah Git pada tahap ini.
- Audit awal Kimi menemukan dispatcher sinkron tanpa Redis job, satu Redis
  untuk antrean/cache, serta bukti restart yang belum ada. Backend dan infra
  diserahkan dengan worker_done diterima. Laporan di `docs/evidence/m03/`.
- Kontrak: `ProcessOutboxEvent` membawa UUID, inbox/completion atomik di Postgres;
  lease/stale replay; event gagal diparkir lewat `failed_at` dan retry per UUID.
  Config queue `retry_after=180`, `after_commit=true`; connection queue/cache
  terpisah; probe internal `rt05:heartbeat-check`. ERD/ASYNC_JOBS diperbarui;
  tidak ada endpoint HTTP baru sehingga kontrak OpenAPI tidak berubah.
- Validasi yang sudah dijalankan: `node infra/scripts/check-repo.mjs` lulus
  (470 berkas pada checkpoint integrasi); `git diff --check` lulus;
  `website/: php artisan test --compact` lulus 9 tes/155 assertion.
  Website tidak diubah pada branch M03, sehingga tidak ada ekspor preview baru.
- Backend lokal: Pint passed; `RT05_SKIP_TEST_MIGRATIONS=1 php vendor/bin/phpunit
  tests/Feature/HeartbeatTest.php tests/Unit` passed 10 tes/50 assertion tanpa
  koneksi database. Tes Postgres/Redis penuh menunggu CI; angka ini bukan
  kelulusan gate recovery.
- Infra statis `check-m03-compose.mjs` dan `bash -n` lulus. Sesudah handoff,
  Codex memperbaiki kepemilikan PID worker/scheduler dengan exec PHP, memeriksa
  inbox kosong/outbox committed sebelum SIGKILL, menunggu dua delivery drain,
  mengikuti AOF everysec, dan menambah path filter CI skrip recovery.
- Dokumen capstone Tugas 03/04 dibaca melalui Google Drive; sumber dicatat di
  SOURCES; Logbook 1/2/3 dan brainstorm turut dibandingkan. Semua selisih yang
  teridentifikasi dicatat di CAPSTONE_ALIGNMENT.md, termasuk flow approval
  registrasi utama dan tipe nominal. Chrome Hostinger dapat diinspeksi melalui computer-use; emulator
  Pixel_8a dinyalakan, `adb shell getprop sys.boot_completed` mengembalikan 1.
  Belum ada login emulator atau redeploy staging pada checkpoint ini.
- Batas bukti: PHP/Postgres/Redis recovery belum dinyatakan lulus. Docker/WSL
  lokal tetap tidak dipasang sesuai ADR19; pengujian integrasi nyata memakai
  Postgres/Redis sintetis di GitHub CI. Tidak menjalankan tes terhadap Neon.
- Kimi aktif mengaudit meskipun composed startup Orca keliru menandai
  `agent_prompt_stalled`; laporan tersimpan. Coordinator memulihkan tracking
  tanpa menggandakan atau merestart agent yang masih bekerja.
- Langkah berikutnya: review independen dengan CI API/infra
  nyata, kemudian staging M04 jika gate M03 telah terbukti. Insiden credential
  sesi 5–6 tetap open; production/UAT/signing/SMTP/FCM bukan hasil tahap ini.

10 Oktober 2026 (M01): checkout lokal pemilik berada di `Documents/RT X TAKEDA/RT-05-TAKEDA/`; folder induk `RT X TAKEDA/` berisi paket MD asli (tidak di-commit). PR #10 merged. Sesi M02: branch `codex/m02-api-neon-foundation`, PR #11. Neon dev sudah dimigrasi (5 migration fondasi). `rest-api/.env` lokal menunjuk Neon dev. php.ini lokal: pdo_pgsql/pgsql/intl/sodium diaktifkan. Tidak ada migration, image, atau job. Input belum tersedia: credential Neon, akses VPS/DNS (ditawarkan pemilik via Chrome untuk M04), SMTP, FCM, signing key, Flutter SDK.

Catat Git status, perubahan pengguna yang belum commit, migration yang sudah applied,
image/tag deploy, jobs pending, dan credential input missing tanpa menuliskan nilainya.

Jangan mengulangi provisioning/migration/deploy yang sudah berhasil hanya karena sesi baru.
Selesaikan perubahan pengguna dengan hati-hati; tidak reset/force-push.

## Bug/risiko

| ID | Severity | Domain | Repro/bukti | Milestone | Status |
| --- | --- | --- | --- | --- | --- |
| R01 | medium | website/release | `package-frontend.ps1` setelah dipindah ke `website/` menghasilkan ZIP kosong yang lolos verifikasi (filesVerified 0), lalu CRLF pada arsip subtree | M01 | fixed (350390a), diverifikasi 201/201 |
| R02 | low | docs | FRONTEND.md/SOURCES.md menyebut 34e9b21 sebagai Git tree; sebenarnya commit | M01 | fixed |
| R03 | medium | deploy preview | Root Directory Vercel belum diubah; tanpa jembatan root, merge akan memutus preview | M01 | mitigated (ADR12), menunggu pemilik |
| R04 | medium | mobile | Flutter SDK tidak terpasang | M05 | open |
| R05 | high | database | pdo_pgsql named prepares + pooler Neon → transaksi gugur 25P02 pada tulis pertama | M02 | fixed (ADR14), terverifikasi di Neon pooled |
| R06 | medium | dev env | Docker Desktop tidak jalan: WSL2 belum terpasang (Windows Home) | M02/M03 | open, menunggu pemilik |
| R07 | low | security | Credential owner Neon dev tampil di keluaran tool agent saat pengambilan connection string | M02 | open: reset password rt05_owner (dev) disarankan |

## Bukti historis frontend

Lihat docs/website/FRONTEND_HANDOFF.md setelah M01. Angka tes di handoff adalah bukti commit terdahulu, bukan otomatis hasil untuk HEAD yang baru.
