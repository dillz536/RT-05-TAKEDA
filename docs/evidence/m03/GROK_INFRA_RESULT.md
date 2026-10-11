# Hasil infra M03 — Grok

- Tanggal: 11 Oktober 2026
- Branch kerja: `codex/m03-async-recovery` pada `c644b84` (PR #14 sudah menyatu). Tidak ada commit, push, atau deploy dari sesi ini.
- Lingkup: `infra/**`, `.github/workflows/api.yml`, `.github/workflows/infra.yml`, dan laporan ini. `rest-api/` milik Claude tidak diedit.

## Yang disusun

Antrean tetap service `redis` dengan volume `redis-data` (AOF everysec, noeviction, 256mb). Cache adalah service `redis-cache` (tanpa volume, allkeys-lru, 128mb, tanpa AOF). Compose production tidak memetakan port Redis. Compose lokal membuka Redis hanya di `127.0.0.1:6379` (antrean) dan `127.0.0.1:6380` (cache) agar PHP di host bisa memeriksa tanpa mempublikasikan port.

Tiga worker memakai image API yang sama: `notifications,default` timeout 60 detik, `media` dan `exports` timeout 120 detik, `tries=5`, `sleep=2`, `stop_grace_period` 180 detik. Healthcheck memanggil `rt05:heartbeat-check --component` untuk `worker-default`, `worker-media`, `worker-exports`, dan `scheduler`.

Env compose mengikuti kontrak yang sudah diimplementasikan Claude:

| Variabel | Nilai |
| --- | --- |
| `REDIS_QUEUE_CONNECTION` | `queue` |
| `REDIS_QUEUE_HOST` / `REDIS_HOST` | service `redis` |
| `REDIS_CACHE_HOST` | service `redis-cache` |
| `REDIS_QUEUE_RETRY_AFTER` | `180` |
| `REDIS_PREFIX` | `takeda:<APP_ENV>:` bila kosong; nilai eksplisit lama tetap dipakai |
| `SESSION_CONNECTION` | `cache` |
| `QUEUE_CONNECTION` / `CACHE_STORE` | `redis` |

`REDIS_PASSWORD` dan `REDIS_CACHE_PASSWORD` wajib nonempty pada compose production/staging (`:?`). Lokal dan drill CI boleh tanpa password. Workflow infra mengisi nilai palsu `ci-only-redis` dan `ci-only-cache` hanya agar `docker compose config` dapat dirender. Bukan credential server.

`deploy-vps.sh` menolak worktree kotor, lalu `checkout --detach` ke `origin/main` atau `RT05_REV`. Skrip tidak lagi memakai hard reset. Ready yang bukan `"status":"ready"` mengakhiri skrip dengan exit 1. Heartbeat empat komponen dibatasi 180 detik. Skrip ini tidak dijalankan.

## Drill CI

`.github/workflows/api.yml` memasang extension `redis` dan `pcntl`, Postgres 17 yang sudah ada, lalu:

1. `infra/scripts/ci-redis.sh` — Redis 7.4 pada loopback, cek kebijakan, restart AOF untuk satu list sintetis, dan hapus hanya kunci `rt05ci_queues:*`.
2. `php artisan test` — termasuk `AsyncOutboxTest` (rollback, duplikat, replay) pada Redis palsu/sync. Itu bukan bukti proses worker.
3. `infra/scripts/m03-recovery-ci.sh` — database `*_test` lokal saja, menolak host Neon. Worker dihidupkan dan heartbeat-nya dicek, lalu PID PHP itu saja di-`SIGSTOP`. `prepare` dan `outbox-dispatch` berjalan saat proses itu terjeda, kemudian PID itu di-`SIGKILL`, di-reap, dan worker baru menuntaskan tepat satu inbox. Duplikasi payload memakai `EVAL` di dalam Redis. Restart AOF memakai `appendfsync everysec` (bukan `always`) dengan jeda 2 detik sebelum restart, lalu inbox diverifikasi. Penghapusan hanya `queues:notifications` plus `:delayed`, `:reserved`, dan `:notify`; sentinel harus tetap ada. Replay memakai `rt05:outbox-dispatch` saat `RT05_OUTBOX_STALE_SECONDS=5`. Heartbeat scheduler dibatasi 100 detik.

Skrip recovery tidak memuat perintah hapus seluruh database Redis.

## Bukti yang benar-benar dijalankan

| Perintah | Hasil |
| --- | --- |
| `node infra/scripts/check-m03-compose.mjs` | OK |
| `node infra/scripts/check-repo.mjs` | OK |
| `bash -n` pada `deploy-vps.sh`, `ci-redis.sh`, `m03-recovery-ci.sh`, dan tiga skrip Redis | OK |
| Docker daemon Windows | tidak tersambung (`dockerDesktopLinuxEngine` tidak ada). Tidak dipasang dan tidak dijalankan |
| `ci-redis.sh`, `m03-recovery-ci.sh`, `php artisan test`, Actions | tidak dijalankan |

Karena itu gate kill/restart, AOF job Laravel, queue-loss, replay, dan heartbeat **masih PENDING**. Pemeriksaan statis bukan PASS gate M03.

## Yang belum

- Coordinator yang melakukan commit/push, lalu membaca log Actions `API verification` dan `Infra images`.
- Env server yang sudah ada perlu `REDIS_PASSWORD` dan `REDIS_CACHE_PASSWORD` nonempty sebelum deploy berikutnya. Nilai tidak dibaca dan tidak ditulis di sini. Prefix eksplisit yang sudah tersimpan tetap dihormati; bila variabel itu kosong, default baru adalah `takeda:<APP_ENV>:`.
- Deploy, migrasi server, Neon, DNS, dan rotasi credential tidak dilakukan.
- M04 dan milestone berikutnya tidak dibuka.
