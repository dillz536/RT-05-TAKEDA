# infra/ — dukungan operasi

**Status: M03 infra tersusun, bukti CI belum dijalankan dari PC ini.** Aktif: `scripts/check-repo.mjs`, `scripts/check-m03-compose.mjs`, `scripts/ci-redis.sh`, `scripts/m03-recovery-ci.sh`, `compose.local.yml` (Postgres 17 di 127.0.0.1:54329; Redis antrean 127.0.0.1:6379 dan cache 127.0.0.1:6380), `compose.production.yml` (antrean `redis` + volume `redis-data`, cache `redis-cache`, tiga worker, scheduler, password wajib). Lihat [docs/DEPLOYMENT.md](../docs/DEPLOYMENT.md) dan [docs/ASYNC_JOBS.md](../docs/ASYNC_JOBS.md).

| Lokasi | Isi | Milestone |
| --- | --- | --- |
| `scripts/check-repo.mjs` | File terlarang, pola secret, `.git` bersarang, tautan relatif Markdown, paritas `vercel.json` | M01 (aktif) |
| `docker/` | Dockerfile API/web/worker | M03–M04 |
| `nginx/` | web-nginx, api-nginx, routing `/api/v1` | M04 |
| `compose.local.yml` | Postgres 17 di 127.0.0.1:54329; Redis 7.4 antrean/cache tanpa port host. SMTP capture belum | M02/M03 |
| `sql/grant-runtime-role.sql` | Hak DML role runtime; sumber tunggal untuk lokal, CI, dan Neon | M02 aktif |
| `docker/postgres/init/` | Role rt05_migrator/rt05_app dan database rt05_dev/rt05_test lokal | M02 aktif |
| `compose.staging.yml` | `takeda-staging.morriz.tech` | M04 |
| `compose.production.yml` | `takeda.morriz.tech` | M16 |

```powershell
node infra/scripts/check-repo.mjs
```

Tidak ada IP server, credential, atau record DNS nyata di folder ini. Perubahan VPS/DNS hanya dalam scope yang diotorisasi pemilik.
