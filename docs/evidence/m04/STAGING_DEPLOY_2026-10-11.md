# Handoff deploy staging — 11 Oktober 2026

## Hasil

Staging aktif di https://takeda-staging.morriz.tech. Pemeriksaan luar VPS pada 07:31 WIB: beranda, profil, `/api/v1`, `/health/live`, dan `/health/ready` HTTP 200. Readiness: `{"status":"ready","checks":{"database":"ok"}}`. Chrome memuat CSS, JS, logo, dan foto melalui HTTPS; klik Profil RT berhasil.

Deploy pertama dimulai melalui tab Konsol web Hostinger yang sudah dibuka pemilik. Perintah `curl -fsSL` harus dijalankan di Ubuntu VPS, bukan alias `curl` PowerShell Windows. Script yang sudah diunduh cocok dengan SHA-256 lokal `0287352c0e97bdb05150e60ad2cd73f250885f1ae2fa4f0da91d2504945eb741`.

Build lima image berhasil sekitar 311 detik. Script exit 0. Migrasi staging melaporkan `Nothing to migrate`; command pemberian hak DML runtime berhasil. Enam container staging berjalan, API/web/Redis healthy, worker/scheduler Up, restart count semuanya 0. Status ini tidak membuktikan seluruh alur bisnis atau persistence setelah reboot.

## Patch yang perlu dibawa ke main

Branch lokal: `codex/m04-staging-routing`, baseline `53a8b10c9156fa6dcbd57d623863efb238f39d64`. Server di `/opt/rt05-takeda` menjalankan baseline dengan empat file berikut yang diperbaiki:

- `infra/compose.vps.yml`: labels memakai list `key=value`. Compose menginterpolasi YAML values; variabel pada mapping keys sebelumnya tetap literal, sehingga router merujuk service yang tidak tersedia. Acuan: [Docker interpolation](https://docs.docker.com/reference/compose-file/interpolation/).
- `infra/docker/gateway.Caddyfile`: proxy privat dipercaya agar `X-Forwarded-Proto` HTTPS dipertahankan melalui gateway. Parsing IP menggunakan `trusted_proxies_strict`. Acuan: [Caddy trusted proxies](https://caddyserver.com/docs/caddyfile/options#trusted-proxies).
- `website/bootstrap/app.php`: trusted proxies dari environment, dengan default kosong; mengikuti mekanisme API yang sudah ada. Acuan: [Laravel trusted proxies](https://laravel.com/framework/docs/13.x/requests#configuring-trusted-proxies).
- `infra/compose.production.yml`: environment website mendapat APP_ENV serta TRUSTED_PROXIES privat. Nama file dipakai runbook staging juga; deployment production tidak dijalankan.

Patch perlu ditinjau dan disatukan ke main sebelum menjalankan ulang `deploy-vps.sh`, karena script melakukan `git reset --hard origin/main` pada checkout server. Menjalankan script dari main lama akan mengembalikan 503 dan URL aset HTTP.

Backup source sebelum patch ada pada `/root/rt05-compose.vps.before-routing-fix.yml` serta `/root/rt05-before-https-*`. Backup ini hanya konfigurasi/source, bukan backup database.

## Validasi

- `docker compose ... config -q`: lulus; label gateway hasil render bernama `rt05-stg`, tanpa placeholder literal.
- `docker exec rt05-staging-gateway-1 caddy validate --config /etc/caddy/Caddyfile`: valid.
- Build ulang website: exit 0 (~11 detik).
- `php artisan test --compact` dari website lokal: 9 passed, 155 assertions.
- `node infra/scripts/check-repo.mjs` dan `git diff --check`: lulus sebelum dokumentasi final; hasil akhir dicatat pada PROGRESS.
- HTML beranda/profil tidak memiliki URL `http://takeda-staging.morriz.tech` setelah perbaikan. CSS/JS dan gambar yang terlihat memuat melalui HTTPS.

Hanya stack `rt05-staging` dimutasi. Layanan lain tetap terlihat Up 7 days; proxy Coolify tidak direstart. DNS, firewall, port host, dan deployment production tidak diubah pada sesi ini.

## Tindak lanjut rahasia

Env staging ditransfer langsung dari file gitignored PC ke `/root/.rt05/.env.staging`, terminal echo off, mode file 600/direktori 700. Namun satu pembacaan AX mencatat isi input terminal ke keluaran alat, meskipun layar terminal menyembunyikannya. Clipboard sudah dibersihkan dan isi env tidak dimasukkan ke dokumen, screenshot, atau Git.

Rotasi password database role runtime/migrator dan APP_KEY staging diperlukan. Dokumen sesi sebelumnya menyatakan password runtime dev sama dengan staging; koordinasikan keduanya agar koneksi tidak putus. APP_KEY rotation harus mempertimbangkan nilai terenkripsi/data demo. Rotasi belum dilakukan pada sesi deploy ini.

## Batas verifikasi

SMTP/FCM, signing APK, CMS backend M13/M14, UAT, backup/restore, Neon write, job heartbeat, dan restart/reboot persistence tidak diuji ulang. Laporan ini menyatakan deploy serta smoke staging berhasil, bukan kelulusan M04/M15/M16 keseluruhan atau kesiapan production.
