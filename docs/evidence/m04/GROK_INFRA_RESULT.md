# Hasil infra M04 — Grok

- Tanggal: 11 Oktober 2026
- Branch kerja: `codex/m04-staging-recovery`. `git rev-parse HEAD` pada saat laporan ini ditulis: `75dee911f2b95423367dfa9adf8feb201377a7ed`. Basis tugas `ea4179e`; merge M03 adalah `27a3e5b`. Tidak ada commit, push, reset, atau deploy dari sesi ini.
- Lingkup yang diubah: `infra/**`, `.github/workflows/infra.yml`, dan laporan ini. `rest-api/`, `website/`, pohon server, env, dan secret tidak diubah.

## Status gate

Gate live M04 **PENDING**, bukan PASS. Skrip baru belum pernah dijalankan. Daemon Docker di PC ini tidak jalan (`npipe:////./pipe/dockerDesktopLinuxEngine` tidak ada) dan tidak dinyalakan. CI pada `75dee91` yang tercatat di [CI_RESULTS.md](CI_RESULTS.md) **tidak** memuat skrip infra M04 ini; workflow infra harus dijalankan lagi setelah commit coordinator.

Kontrak `rt05:staging-smoke` dibaca dari kode Claude, bukan ditebak: `--confirm-staging` wajib, `--phase=prepare|verify`, verify memakai `--event` UUID dan `--timeout`. Tidak ada pertanyaan tambahan ke coordinator. Lihat [CLAUDE_BACKEND_RESULT.md](CLAUDE_BACKEND_RESULT.md).

## Yang disusun

Image API dan web di [infra/compose.production.yml](../../../infra/compose.production.yml):

- `rt05-api:${RT05_IMAGE_TAG:-${RT05_ENV:-local}}`
- `rt05-web:${RT05_IMAGE_TAG:-${RT05_ENV:-local}}`

Worker, scheduler, dan migrate tetap memakai anchor API, jadi mereka mendapat image API yang sama. Bila `RT05_IMAGE_TAG` kosong, tag tetap `RT05_ENV` lalu `local`. `infra/compose.vps.yml` dan `infra/docker/gateway.Caddyfile` tidak diedit.

[infra/scripts/deploy-staging-immutable.sh](../../../infra/scripts/deploy-staging-immutable.sh) hanya staging:

- Argumen satu SHA 40 hex huruf kecil, atau `rollback`.
- Origin tetap `https://github.com/morrispes5/RT-05-TAKEDA.git`. Worktree baru: `/opt/rt05-releases/<SHA>`. Bare clone ada di `/opt/rt05-releases/origin.git`.
- `/opt/rt05-takeda` hanya dibaca lewat `status --porcelain` (nama path, tanpa diff). Tidak ada fetch, checkout, atau reset pada pohon itu.
- Project Compose tetap `rt05-staging`, dengan env `/root/.rt05/.env.staging`. Volume `redis-data`, `api-storage`, dan `apk-downloads` mengikuti nama project yang sama.
- Build hanya `api` dan `web`. `up` dan `migrate` memakai `--no-build`. Tidak ada `docker push`.
- File env harus sudah ada, mode `600`, bukan symlink. Script menolak bila `REDIS_PASSWORD` atau `REDIS_CACHE_PASSWORD` kosong, dan tidak menulis secret. Baris identitas yang diminta, tanpa kutip: `APP_ENV=staging`, `RT05_ENV=staging`, `RT05_HOST=takeda-staging.morriz.tech`, plus `DATABASE_URL`, `DATABASE_URL_UNPOOLED`, `API_APP_KEY`, dan `WEB_APP_KEY` nonempty. Nilai tidak dicetak.
- Preflight mencetak OS, versi Docker/Compose, `df`/`free`, id/nama/status container, dan nama file kotor. Lantai: 5 GiB kosong di `/opt`, MemAvailable 512 MiB. Redis yang memetakan `0.0.0.0` atau `[::]` pada 6379/6380 menolak deploy. Tidak ada `docker inspect`, `docker compose config`, atau `printenv`.
- Ready dibatasi 120 detik dan hanya lulus bila body memuat `"status":"ready"`; body tidak dicetak. Empat heartbeat (`worker-default`, `worker-media`, `worker-exports`, `scheduler`) berbagi batas 180 detik.
- `--remove-orphans` hanya berlaku di project `rt05-staging` (worker tunggal lama ikut diganti bila label project-nya memang itu). Container di luar project dicatat sebelum dan sesudah; deploy gagal bila container yang tadinya `Up` hilang atau berhenti.
- Rollback membaca `/opt/rt05-releases/staging-previous-sha` dan menaikkan SHA itu dengan `up`, tanpa `down -v`, `volume rm`, atau `system prune`. SHA sebelumnya hanya tercatat bila image `api` yang sedang berjalan sudah bertag 40 hex. Deploy pertama dari image tanpa SHA tidak punya rollback skrip; jangan prune image lama.

[infra/scripts/staging-runtime-smoke.sh](../../../infra/scripts/staging-runtime-smoke.sh) terpisah dari `ci-redis.sh` dan `m03-recovery-ci.sh`, dan tidak dimasukkan ke `api.yml`. Urutannya, pada worktree SHA aktif, bukan pada `/opt/rt05-takeda`:

1. `rt05:db-smoke --connection=pgsql` lalu `--connection=pgsql_migrations` (rollback ada di dalam command).
2. `rt05:staging-smoke --confirm-staging --phase=prepare`; stdout harus memuat tepat satu UUID.
3. `rt05:outbox-dispatch`.
4. Restart hanya `worker-default`, lalu empat heartbeat dalam 180 detik.
5. `rt05:staging-smoke --confirm-staging --phase=verify --event=<uuid> --timeout=180`.
6. Pada Redis antrean saja: `appendonly=yes` dan `appendfsync=everysec`, `SET rt05-staging-aof-sentinel`, jeda 2 detik, restart service `redis` project `rt05-staging`, tunggu PONG paling lama 120 detik, nilai sentinel harus tetap `persisted`. Nilai salah tidak dicetak. Cache tidak direstart. Tidak ada penghapusan key antrean dan tidak ada pengosongan keyspace.
7. Heartbeat empat komponen diulang. Id container di luar project dibandingkan. Baris `PASS staging-runtime-smoke` hanya dicetak setelah semua langkah lulus.

## Bukti yang dijalankan di PC ini

| Perintah | Hasil |
| --- | --- |
| `bash -n` kedua skrip baru (Git Bash) | lulus |
| `node infra/scripts/check-m03-compose.mjs` | `OK: kontrak statis M03 infra (Redis terpisah, worker, heartbeat, prefix drill, gateway PR14) dan skrip staging M04.` |
| `node infra/scripts/check-repo.mjs` | `OK: 480 berkas dilacak; tanpa file terlarang, pola secret, .git bersarang, atau tautan relatif rusak; vercel.json setara.` |
| `docker version` | gagal: daemon tidak ada. Tidak diinstal dan tidak dinyalakan. |
| Skrip deploy dan smoke | tidak dijalankan |

## Blocker untuk coordinator

[PREFLIGHT.md](PREFLIGHT.md) sudah mencatat `REDIS_PASSWORD=missing` dan `REDIS_CACHE_PASSWORD=missing` pada env staging (mode 600). Deploy akan berhenti di preflight sampai kedua nilai nonempty diisi pemilik. Sesi ini tidak membuat, mencetak, atau merotasi secret.

Pohon server `/opt/rt05-takeda` tetap kotor pada `infra/compose.production.yml`, `infra/compose.vps.yml`, `infra/docker/gateway.Caddyfile`, dan `website/bootstrap/app.php`. Preflight server juga menyebut HEAD pohon itu `53a8b10c9156fa6dcbd57d623863efb238f39d64`. Jangan menjalankan `deploy-vps.sh` di sana. SHA yang di-deploy harus sudah ter-push dan sudah memuat perubahan tag image ini; origin saat ini belum memuat skrip baru.

Setelah push, di konsol Hostinger:

```bash
bash infra/scripts/deploy-staging-immutable.sh <sha40>
bash infra/scripts/staging-runtime-smoke.sh
```

Rollback, hanya bila berkas SHA sebelumnya valid:

```bash
bash infra/scripts/deploy-staging-immutable.sh rollback
```

M05 tidak dimulai.
