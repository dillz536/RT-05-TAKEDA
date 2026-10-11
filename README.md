# RT05 TAKEDA — monorepo

Sistem informasi dan layanan warga RT 05 RW 07 Taman Kedaung, Ciputat. Pemilik: Morriz. Domain: morriz.tech.

Website publik sudah diterima pada 10 Oktober 2026 (M00) dan live sebagai preview statis di https://rt05takeda.vercel.app. Sejak M01 repository ini menjadi monorepo: website, aplikasi mobile, REST API, dokumentasi, dan dukungan infrastruktur berada di satu Git history.

## Struktur

| Folder | Isi | Status |
| --- | --- | --- |
| [website/](website/README.md) | Laravel 13 + Blade + Tailwind 4 + Vite; website publik dan UI Pengurus Artikel/Dokumentasi yang diterima | Baseline accepted, dipindah utuh pada M01 |
| [mobile/](mobile/README.md) | Flutter Android warga/pengurus | Implementasi awal dan APK debug tersedia; penerimaan tiap domain tetap mengikuti PROGRESS |
| [rest-api/](rest-api/README.md) | Laravel REST API, pemilik database Neon dan aturan bisnis | Fondasi serta layanan awal tersedia; recovery asinkron M03 sedang diverifikasi |
| [infra/](infra/README.md) | Script, Docker, Caddy, Compose | Staging awal tersedia; gate worker/recovery dan deployment dicatat di PROGRESS |
| [docs/](docs/PRD.md) | Konteks lintas modul; dokumentasi frontend di [docs/website/](docs/website/FRONTEND_HANDOFF.md) | Aktif |
| `.github/workflows/` | CI API, website, mobile, image infra dan pemeriksaan repo | Aktif |

## Target

| Bagian | Target |
| --- | --- |
| Website | Laravel 13, Blade, Tailwind 4; pertahankan frontend yang diterima |
| REST API | Laravel 13, Eloquent, Sanctum; pemilik seluruh aturan bisnis |
| Mobile | Flutter/Dart; satu aplikasi dengan tampilan warga dan pengurus |
| Database | PostgreSQL melalui Neon; lingkungan terisolasi |
| Redis | Instance antrean dan cache terpisah |
| Asinkron | Worker Laravel, scheduler, transactional outbox |
| Hosting | VPS Hostinger, Docker, reverse proxy HTTPS |
| Domain | Production `takeda.morriz.tech`, staging `takeda-staging.morriz.tech`; `/api/v1` untuk API |
| Preview | Vercel tetap preview statis frontend selama pengembangan |
| Distribusi mobile | APK Android bertanda tangan; bukan proses Flutter pada VPS |

Iuran Rp75.000 per rumah per bulan, dibayar offline dan dicatat pengurus. Tidak ada payment gateway. Perubahan teknis dicatat sebagai ADR di [docs/DECISIONS.md](docs/DECISIONS.md).

## Mulai cepat — website

Prasyarat: PHP 8.3+ (CI 8.4), Composer, Node.js 22.12+. Jalankan dari folder `website/`:

```powershell
Set-Location website
composer install
Copy-Item .env.example .env
php artisan key:generate
npm.cmd ci
npm.cmd run build:preview
npm.cmd run preview
```

Buka http://127.0.0.1:8123. Rute tetap `/profil`, `/artikel`, dan seterusnya; `website/` bukan prefix URL. Jangan menjalankan `composer setup` untuk frontend karena menjalankan migrasi. Detail pemeriksaan ada di [website/README.md](website/README.md).

Pemeriksaan repo (secret, file terlarang, `.git` bersarang, tautan relatif, paritas Vercel):

```powershell
node infra/scripts/check-repo.mjs
```

## Peta baca

| Dokumen | Tujuan |
| --- | --- |
| [AGENTS.md](AGENTS.md) | Instruksi agent dan batas keselamatan |
| [docs/PRD.md](docs/PRD.md) | Produk, cakupan, alur, dan penerimaan |
| [docs/BUSINESS_RULES.md](docs/BUSINESS_RULES.md) | Aturan bisnis dan invariant |
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | Runtime, modul, dan aliran data |
| [docs/MONOREPO.md](docs/MONOREPO.md) | Struktur, migrasi repo, dan workflow |
| [docs/FRONTEND.md](docs/FRONTEND.md) | Baseline web dan integrasi CMS |
| [docs/DATABASE.md](docs/DATABASE.md) | Koneksi Neon, skema, transaksi, migrasi |
| [docs/ERD.md](docs/ERD.md) | Relasi dan daftar tabel rancangan |
| [docs/API.md](docs/API.md) | Kontrak HTTP dan autentikasi |
| [docs/MOBILE.md](docs/MOBILE.md) | Layar, struktur Flutter, build |
| [docs/ASYNC_JOBS.md](docs/ASYNC_JOBS.md) | Redis, worker, outbox, scheduler |
| [docs/SECURITY.md](docs/SECURITY.md) | Privasi, otorisasi, secrets, abuse |
| [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) | Hostinger, DNS, Docker, backup, rollback |
| [docs/TESTING.md](docs/TESTING.md) | Pengujian perilaku, UAT, rilis |
| [docs/ROADMAP.md](docs/ROADMAP.md) | M00–M16; hasil dan syarat selesai |
| [docs/CODEX_PROMPTS.md](docs/CODEX_PROMPTS.md) | Prompt tiap milestone dan lanjut sesi |
| [docs/DECISIONS.md](docs/DECISIONS.md) | Sumber keputusan dan ADR |
| [docs/PROGRESS.md](docs/PROGRESS.md) | Status aktual dan bukti per milestone |
| [docs/COORDINATION.md](docs/COORDINATION.md) | Pembagian Codex/Claude/Grok/Kimi, tahap aktif, gate dan checkpoint pemulihan |
| [docs/SOURCES.md](docs/SOURCES.md) | Sumber proyek dan dokumentasi resmi |
| [docs/website/](docs/website/FRONTEND_HANDOFF.md) | Handoff, preview konten, Stitch, QA, bukti frontend, [instruksi Vercel](docs/website/VERCEL_ROOT.md) |

## Status

Lihat [docs/PROGRESS.md](docs/PROGRESS.md). M00 accepted baseline; M01/M02 passed.
Tahap aktif M03 memverifikasi Redis worker dan recovery outbox. Staging awal,
layanan API dan APK debug telah tersedia dari sesi sebelumnya; penerimaan lengkap
domain, provider SMTP/FCM, rilis signed, backup dan production masih memerlukan
bukti. [Rencana koordinasi](docs/COORDINATION.md) mengatur pekerjaan satu tahap
sebelum maju ke milestone berikutnya.

Jumlah developer tidak menentukan rancangan. Pekerjaan dapat dibagi per issue/domain, dengan kontrak API serta dependency milestone sebagai acuan bersama.
