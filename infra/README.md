# infra/ — dukungan operasi

**Status: M03 infra tersusun. M04 menambah tag `RT05_IMAGE_TAG` plus [scripts/deploy-staging-immutable.sh](scripts/deploy-staging-immutable.sh) dan [scripts/staging-runtime-smoke.sh](scripts/staging-runtime-smoke.sh).** Kedua skrip staging belum dijalankan dari PC ini; gate live tetap di server. Aktif: `scripts/check-repo.mjs`, `scripts/check-m03-compose.mjs`, `scripts/ci-redis.sh`, `scripts/m03-recovery-ci.sh`, `compose.local.yml` (Postgres 17 di 127.0.0.1:54329; Redis antrean 127.0.0.1:6379 dan cache 127.0.0.1:6380), `compose.production.yml` (antrean `redis` + volume `redis-data`, cache `redis-cache`, tiga worker, scheduler, password wajib). Lihat [docs/DEPLOYMENT.md](../docs/DEPLOYMENT.md) dan [docs/ASYNC_JOBS.md](../docs/ASYNC_JOBS.md).

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

## Staging M04

Gunakan satu commit SHA 40 hex yang sudah dipush, lolos CI dan direview.
`scripts/deploy-staging-immutable.sh <sha>` membuat worktree baru pada
`/opt/rt05-releases/<sha>`, memakai project `rt05-staging`, dan mempertahankan
pohon server lama yang kotor. File env existing `/root/.rt05/.env.staging`
tetap privat; jangan mencetak isinya atau keluaran Compose config.
`APP_ENV` wajib `staging`, host wajib `takeda-staging.morriz.tech`; `RT05_ENV`
menerima `staging` atau alias existing `stg` agar nama router Traefik lama
dipertahankan. Project dan volume tetap `rt05-staging`.
SHA harus merupakan leluhur `main` atau branch coordinator persis
`codex/m04-staging-recovery`; objek SHA dari fork/PR sembarang ditolak sebelum
worktree dieksekusi atau image dibangun. Branch rilis baru perlu keputusan
allowlist eksplisit, bukan menerima seluruh ref `codex/*`.

Jika dua credential Redis staging belum ada, jalankan file yang direview
`python3 scripts/prepare-staging-redis-auth.py` di VPS. Helper menolak argumen,
platform selain Linux/root, env selain staging, symlink, direktori yang tidak
privat, atau env yang bukan mode 600. Hanya key Redis kosong/hilang diisi
secara atomik; semua nilai DB/APP_KEY dan credential yang sudah ada tetap.
Tidak ada backup plaintext atau nilai secret pada stdout.
Key guarded berprefix `export` atau whitespace ditolak agar tidak terbentuk
duplikat semantik; lima tes sintetis memeriksa parser, bukan file env VPS.

Setelah rollout, `bash scripts/staging-runtime-smoke.sh` memeriksa Neon
pooled/direct dengan rollback, menghentikan satu worker staging, menerbitkan
marker kosong, lalu membuktikan marker dan sentinel bertahan saat Redis AOF
restart. Worker dipulihkan juga saat assertion gagal. Same-SHA image, mount
volume, dan ID/StartedAt layanan lain diperiksa melalui metadata terpilih.
API/worker/scheduler juga harus menggunakan image ID yang sama. Sentinel
berumur 24 jam. Restart ini terkontrol; drill crash worker ada pada CI M03.
Tes CI/helper sintetis tidak membuktikan operasi VPS ini.

Rollback script menerima hanya SHA dengan kontrak image immutable, dua Redis
AUTH, topology worker dan metadata guard yang kompatibel. Deploy pertama
dari stack lama tanpa tag SHA belum mempunyai target rollback yang memenuhi
kontrak ini: pertahankan image/pohon lama dan lakukan pemulihan terencana,
bukan menurunkan Redis ke konfigurasi tanpa AUTH. Tidak ada rollback schema
otomatis atau penghapusan volume.
