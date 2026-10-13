# M07 — deploy staging

13 Oktober 2026. Kandidat `979634fb7ae847f6c743f0ae7e11ec575b1fe722` (branch
`codex/m07-resident-data`, PR #22). CI pada SHA ini sebelum deploy: API `38353725706`,
Infra `38353725776`, Mobile verify `38353725721`, dan hygiene SUCCESS (laporan root). Dua build
APK debug masih berjalan dan ditangani root; deploy API staging tidak menunggu APK.

Lingkup: hanya project Docker `rt05-staging` di VPS lewat SSH yang sudah diotorisasi
(`StrictHostKeyChecking=yes`, `BatchMode`, kunci operator). Tidak ada perubahan DNS, firewall,
port, aplikasi lain, kunci, production, secret, factory, atau tulis Neon langsung.

## Langkah dan bukti

| Waktu (UTC) | Langkah | Hasil |
| --- | --- | --- |
| 06:01 | Snapshot read-only | Staging berjalan `26a0b7e…` (M06). Env `/root/.rt05/.env.staging` mode 600. Disk `/` 16 GiB terpakai dari 96 GiB; memori tersedia ±5,5 GiB. |
| 06:01 | Source guard | Script yang dijalankan = blob `infra/scripts/deploy-staging-immutable.sh` pada SHA kandidat (dibaca penuh sebelum eksekusi: hanya staging, cek env tanpa mencetak nilai, cek ancestry ref yang disetujui, build api/web saja, migrasi lewat service `migrate`, volume dan container lain dipertahankan). Disalin ke `/root/.rt05/deploy-staging-immutable-m07.sh`, mode 700, SHA-256 `1e96f3a3a18ffc7719c5e72b745b68a1c29bc7412ad5de30ba371f7c4423e5bd` sama dengan lokal, `bash -n` OK, tanpa CRLF. |
| 06:02–06:12 | `deploy-staging-immutable-m07.sh 979634f…` | Selesai (`>>> Selesai staging SHA 979634f…`), 0 baris GAGAL. Log privat `/root/rt05-m07-979634f-deploy.log` (mode 600) tidak disalin ke Git. |
| | Preflight | Docker 29.8.2, Compose v5.6.0, Ubuntu 24.04.5 LTS. Env wajib ada dan identitas staging cocok (nilai tidak dicetak). Tidak ada Redis publik. |
| | Build | Hanya image `rt05-api` dan `rt05-web` bertag SHA; tanpa push registry. SHA sebelumnya `26a0b7e…` dicatat sebagai `staging-previous-sha` untuk rollback. |
| | Migrasi | Container `migrate` sementara (role pemilik): `2026_10_13_000100_m07_keanggotaan_tautan_akun … DONE` (±742 ms), additive, backfill di dalam migration. |
| | Rollout | `up -d --no-build --remove-orphans` hanya untuk service rt05-staging. Health ready lulus. Heartbeat worker-default, worker-media, worker-exports, scheduler lulus. Image, volume (nama/CreatedAt), port privat, dan container lain (ID/StartedAt) dipertahankan sesuai assertion script. |
| 06:12 | Verifikasi SHA | `/opt/rt05-releases/staging-current-sha` = `979634f…`. Image `api`, `web`, `worker-default`, `worker-media`, `worker-exports`, `scheduler` = `rt05-*:979634fb7ae847f6c743f0ae7e11ec575b1fe722`, semua healthy. Gateway/Redis memakai image tetap. Env staging tidak berubah (mode 600, ukuran dan mtime sama). |
| 06:14 | Cek runtime non-disruptif | `rt05:db-smoke --connection=pgsql`: endpoint pooled, sslmode verify-full, tulis+baca dalam transaksi ok, 0 baris tersisa setelah rollback, **DDL ditolak** untuk role runtime. `rt05:db-smoke --connection=pgsql_migrations` lewat container `migrate` sementara: direct, verify-full, ok, 0 baris tersisa. Heartbeat 4/4 exit 0. `/health/ready` = ready. Drill restart Redis tidak diulang. |
| 06:14 | `php artisan rt05:residents-backfill` (sekali, container api) | `Keanggotaan ditambahkan: 0; tautan ditambahkan: 0.` Backfill di migration sudah lengkap dan tidak ada registrasi image M06 di jendela antara migrasi dan rollout; perintah idempotent. |

Catatan: `up -d` script me-recreate Redis antrean/cache project ini sebagai bagian dari deploy
(volume AOF dipertahankan); ini bukan drill restart terpisah.

## Rollback

`/root/.rt05/deploy-staging-immutable-m07.sh rollback` memakai `staging-previous-sha`
(`26a0b7e…`). Schema M07 additive: image M06 tetap berjalan. Setelah maju lagi, jalankan
`rt05:residents-backfill` bila image M06 sempat menulis registrasi.

Kompatibilitas ini untuk schema/pembacaan. Hentikan mutasi selama image M06
dipakai: penulisan lama tidak menyinkronkan histori M07. Backfill melengkapi
registrasi yang belum mempunyai histori, bukan memperbaiki perubahan relasi
yang sudah ada. Rollback aplikasi tidak menjalankan down migration atau
menghapus tabel histori live. APK M06 tidak memenuhi versi wajib mutasi M07.

Penerimaan API live: [LIVE_RESULTS.md](LIVE_RESULTS.md).
