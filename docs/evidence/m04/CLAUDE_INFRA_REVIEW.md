# M04 — review independen kode infra (Claude, pengganti sementara Kimi)

Tanggal: 11 Oktober 2026. Claude menggantikan Kimi sementara karena Kimi terkena kuota 403 (batas 5 jam); sesi Kimi tetap dipertahankan. Review ini bukan review Kimi yang baru.

Dasar review: commit `03b4ac6` di branch `codex/m04-staging-recovery` (PR #16), ditambah patch coordinator yang belum di-commit untuk `RT05_ENV` (lihat F1). Review tidak mengedit infra, mobile, atau Git, dan tidak menyentuh VPS, env asli, atau Neon.

Berkas yang diaudit: `infra/scripts/deploy-staging-immutable.sh`, `staging-runtime-smoke.sh`, `staging-metadata.sh`, `prepare-staging-redis-auth.py`, `test_staging_redis_auth.py`, `infra/compose.production.yml` (+ `compose.vps.yml`, `infra/docker/redis-*.sh`), `.github/workflows/infra.yml`. Konteks: `docs/evidence/m04/PREFLIGHT.md`, `CI_RESULTS.md`.

## Verdict

**Verdict akhir (revalidasi koreksi, bagian "Revalidasi koreksi" di bawah): kode rollout staging layak dijalankan.** F1 dan F2 tertutup; L1, L4, dan sentinel L5 juga tertutup. Tidak ada temuan blocking yang tersisa. Koreksi sudah ter-commit di `62a863f`; CI untuk SHA itu harus lulus sebelum dipakai di VPS. **Bukti live tetap belum ada.** Deploy, smoke runtime, AOF restart, heartbeat VPS, Neon staging, dan rollback belum pernah dijalankan di server target, sehingga gate M04 belum lulus.

Verdict awal (03b4ac6, sebelum koreksi): layak setelah F1 ter-commit; F2 disarankan ditutup.

## Pemeriksaan yang dijalankan (lokal Windows, Git Bash, Python 3.13.5)

| Perintah | Hasil |
| --- | --- |
| `bash -n` deploy-staging-immutable.sh, staging-runtime-smoke.sh, staging-metadata.sh, docker/redis-queue.sh, redis-cache.sh, redis-health.sh | semua OK (deploy dan smoke diulang setelah patch F1: OK) |
| `python -B infra/scripts/test_staging_redis_auth.py -v` | 4 tes OK (preserve byte-for-byte, fill missing, CRLF + hanya key kosong, tolak production/identitas/duplikat) |
| Cek nonsecret env sumber lokal `infra/env/.env.staging` (hitungan/kecocokan pola saja) | LF (0 CR); `APP_ENV=staging` dan `RT05_HOST` cocok; `RT05_ENV=stg` → sumber F1 |
| Grep perintah destruktif (`down`, `volume rm`, `prune`, `FLUSH*`, `DEL`, `docker rm/stop`, `reset --hard`) di tiga skrip + helper Python | tidak ada; satu-satunya `--rm` adalah `compose run --rm migrate` (container one-shot) |

Tidak dijalankan: shellcheck (tidak terpasang), docker compose, deploy, atau smoke apa pun.

## Matriks fokus

| Fokus | Temuan | Status |
| --- | --- | --- |
| Hanya staging | Project `rt05-staging` dan env `/root/.rt05/.env.staging` di-hardcode; argumen `production` ditolak; `APP_ENV=staging` persis; host staging persis; smoke tanpa argumen | OK (dengan F1) |
| Env privat sudah ada; secret tidak ke stdout | Deploy dan smoke menolak env yang tidak ada, symlink, atau mode ≠ 600, dan hanya memakai `grep -q` (nilai tidak dicetak). Redis CLI memakai `REDISCLI_AUTH` di dalam container (quote tunggal, ekspansi di container). Python mencetak nama key/kelas exception saja | OK |
| Python hanya mengisi yang kosong; DB/APP_KEY dipertahankan | Byte selain baris REDIS yang kosong tetap (tes byte-for-byte, CRLF). Penolakan: `APP_ENV` ≠ staging, key duplikat, bukan root, bukan Linux, ada argumen, direktori bukan 700/root, file bukan regular/600/root, `O_NOFOLLOW`. Penggantian atomik (`mkstemp` + `fchmod 600` + `fsync` + cek inode/mtime/size + `os.replace` + fsync direktori) | OK; L1 |
| Rilis tidak tepercaya/kotor ditolak | URL origin bare repo diallowlist; worktree yang sudah ada harus HEAD = SHA dan clean; `/opt/rt05-takeda` tidak pernah dipakai | sebagian: **F2** (SHA fork/PR bisa lolos) |
| API/web/3 worker/scheduler memakai SHA sama | `RT05_IMAGE_TAG=$sha`; build hanya api+web; worker/scheduler/migrate mewarisi image api lewat anchor `<<: *api`; `assert_staging_images` mencocokkan `Config.Image` enam service | OK (tag; L4) |
| Named volume dipertahankan | Tiga volume wajib ada sebelum mutasi (menolak pengganti kosong). Name + CreatedAt sebelum = sesudah. Mount redis/api/gateway diverifikasi. Tidak ada `down -v`/`volume rm` | OK |
| ID/StartedAt container lain tetap | `list_unrelated` menyimpan ID\|nama\|StartedAt semua container di luar project; dibandingkan sesudah deploy dan smoke; gagal snapshot = fail | OK; L3 |
| Rollback kompatibel, fail-closed sebelum mutasi service | Rollback = `deploy_sha prev`. Grep kompatibilitas (image immutable, AUTH queue/cache, topologi worker, helper metadata) terjadi setelah worktree disiapkan dan **sebelum** build/up | OK; L2 (tidak ada rollback otomatis ke legacy) |
| Outbox sintetis pending bertahan lewat AOF dengan attempts sama dan inbox 0 | worker-default dihentikan dulu → prepare (UUID tunggal) → dispatch → verify wajib `state=published … inbox=0 error_code=none` exit 1 → AOF `appendonly yes`/`everysec` + sentinel → restart redis antrean → PONG + sentinel → start worker → verify wajib `state=completed attempts=<sama> inbox=0 error_code=none` | OK; L5 |
| Worker dipulihkan bila asersi gagal | `trap cleanup EXIT` dipasang sebelum `stop`; `worker_stopped=1` diset sebelum stop; cleanup `start worker-default` | OK |
| Tanpa production, container lain, DNS, wipe antrean | Tidak ada perintah destruktif (lihat grep). Restart hanya `redis` dan `worker-default` project ini. `--remove-orphans` terbatas project `rt05-staging` (menghapus service `worker` lama). Port 6379/6380 publik ditolak | OK |
| CI workflow | `infra.yml`: kontrak statis, compose config gabungan, `bash -n` semua skrip, unit test Python, build + smoke image | OK; L6 (tanpa shellcheck/eksekusi logika deploy) |

## Temuan

| ID | Sev | Lokasi | Masalah | Status / perbaikan minimal |
| --- | --- | --- | --- | --- |
| F1 | **High (blocking live)** | `deploy-staging-immutable.sh:80`, `staging-runtime-smoke.sh:33` (03b4ac6) | Skrip mewajibkan `RT05_ENV=staging`, sedangkan env staging yang ditempel ke VPS berasal dari sumber dengan `RT05_ENV=stg`. Preflight menolak (aman, tetapi rollout tertolak); helper Python tidak mengubahnya | Dilaporkan ke coordinator lewat `ask`. **Fixed di working tree** (`require_identity RT05_ENV '(staging\|stg)'` dan `^RT05_ENV=(staging\|stg)$`), label Traefik `rt05-stg` dipertahankan, `bash -n` OK. Belum di-commit saat review ini ditulis; nilai env di VPS tetap perlu dicek coordinator di console |
| F2 | Medium | `deploy-staging-immutable.sh:131-138` | Allowlist URL origin tidak menjamin isi tepercaya. GitHub mengizinkan fetch commit berdasarkan SHA dari jaringan fork maupun head PR pihak luar. SHA seperti itu lolos `fetch` dan `cat-file`, lalu skrip **men-source** `staging-metadata.sh` dari SHA itu sebagai root dan membangun image yang menerima env staging (Neon staging, Redis) | Sebelum `source`/build: fetch ref tepercaya eksplisit (mis. `refs/heads/main` dan branch `codex/*` yang disetujui, cukup dalam) lalu `git merge-base --is-ancestor "$sha" FETCH_HEAD`, atau verifikasi tanda tangan commit. Tolak bila bukan leluhur ref tepercaya. Uji: SHA dari ref acak/PR ditolak sebelum ada mutasi |
| L1 | Low | `prepare-staging-redis-auth.py:21-26` | Key hanya dicocokkan persis di awal baris. Baris `export REDIS_PASSWORD=x` atau yang diawali spasi tidak dikenali, sehingga ditambah baris baru (duplikat semantik). Pemeriksaan izin di `main()` tidak punya tes (hanya helper murni) | Tolak baris `export `/berspasi untuk key yang dijaga; tambah tes `main()` dengan direktori sementara (mock path/uid) bila ingin cakupan izin |
| L2 | Low | `record_previous` (`deploy…:107-119`) | Rollout pertama: image legacy tidak bertag SHA, sehingga `staging-previous-sha` tidak tercatat dan `rollback` tidak tersedia ke kondisi pra-M04. Rollback ke legacy hanya manual dan butuh Redis tanpa AUTH | Catat di runbook: rollback otomatis baru tersedia mulai rollout kedua; langkah manual legacy tertulis dan diuji sebelum dianggap ada |
| L3 | Low | `staging-metadata.sh:3-22` | Container lain yang restart sendiri (crash loop/cron) selama jendela deploy/smoke membuat asersi gagal *setelah* mutasi staging (false negative, fail-closed) | Diterima; dokumentasikan bahwa kegagalan asersi pasca-mutasi tidak berarti RT05 mengganggu service lain, dan cek `docker ps` manual |
| L4 | Low | `assert_staging_images` | Kesamaan SHA dibuktikan lewat tag, bukan image ID | Tambahan opsional: bandingkan `.Image` (ID) api = worker-* = scheduler |
| L5 | Low | `staging-runtime-smoke.sh:138-167` | `docker compose restart` = shutdown bersih (SIGTERM), jadi drill ini membuktikan persistensi restart terkontrol, bukan crash/kill -9. Key `rt05-staging-aof-sentinel` tertinggal permanen tanpa TTL/prefix | Nyatakan batas ini di bukti. Opsional: `SET … EX 86400` agar sentinel kedaluwarsa tanpa operasi hapus |
| L6 | Low | `.github/workflows/infra.yml` | CI hanya memeriksa sintaks dan helper murni; alur deploy/smoke tidak dieksekusi di CI (wajar karena perlu VPS) | Opsional: shellcheck di CI |
| L7 | Info (pre-existing) | `compose.production.yml:32` | Container api runtime ikut menerima `DATABASE_URL_UNPOOLED` (role pemilik), yang dipakai smoke `db-smoke --connection=pgsql_migrations`. Ini bertentangan dengan prinsip least privilege runtime di DEPLOYMENT.md | Di luar scope M04 baru; kandidat perbaikan M16 (DSN direct hanya untuk service `migrate`) |
| L8 | Info | urutan `up redis redis-cache` sebelum service lain | Selama jendela migrate, container lama tanpa AUTH kehilangan Redis (NOAUTH). Outbox tetap pending (desain M03); login staging bisa 500 sesaat | Diterima untuk staging; catat jendela gangguan singkat |

## Revalidasi koreksi (dispatch kedua, cakupan sempit)

Diff yang direview: `git diff 03b4ac6 -- infra` di working tree. Berkas yang berubah: `deploy-staging-immutable.sh`, `staging-runtime-smoke.sh`, `staging-metadata.sh`, `prepare-staging-redis-auth.py`, `test_staging_redis_auth.py`, `infra/README.md`. Audit roadmap tidak diulang.

| ID | Koreksi | Verifikasi | Status |
| --- | --- | --- | --- |
| F1 | `require_identity RT05_ENV '(staging\|stg)'` (deploy) dan `^RT05_ENV=(staging\|stg)$` (smoke). `APP_ENV=staging`, host `takeda-staging.morriz.tech`, dan project `rt05-staging` tetap persis | Diff dibaca; `bash -n` OK; regex tetap ber-anchor `^…$` lewat `require_identity` | **Closed (kode)**; nilai env VPS tetap dicek coordinator di console |
| F2 | `prepare_worktree` tidak lagi fetch SHA arbitrer. Skrip fetch `refs/heads/main` dan mewajibkan `merge-base --is-ancestor`; bila gagal, fetch persis `refs/heads/codex/m04-staging-recovery` lalu cek lagi; selain itu `fail` sebelum `cat-file`, worktree, `source`, maupun build | Simulasi repo bare sementara di scratchpad dengan `set -euo pipefail`: commit leluhur → trusted; SHA tak dikenal → `merge-base` rc=128 → ditolak tanpa menghentikan skrip di dalam `if`, lalu `fail` jelas. Objek fork yang tidak berada di riwayat ref tepercaya tidak pernah di-fetch | **Closed** |
| L1 | Key terjaga dengan prefiks `export ` atau spasi di sekitar key ditolak (`Noncanonical guarded env key`) | `python -B infra/scripts/test_staging_redis_auth.py -v`: **5 tes OK** (tes baru: `export REDIS_PASSWORD=`, ` REDIS_PASSWORD=`, `REDIS_CACHE_PASSWORD =`, tanpa membangkitkan secret) | **Closed**; sisa sangat kecil: `export<TAB>KEY=` tidak dikenali sehingga key ditambahkan. Dampak nihil karena baris tambahan berada paling akhir dan menjadi nilai efektif bagi Redis dan API secara bersamaan |
| L4 | `assert_staging_images`: tag `rt05-api:<sha>`/`rt05-web:<sha>` tetap dicek; image ID (`.Image`) worker-default/media/exports/scheduler wajib sama dengan api; web dicek lewat tag SHA sendiri | Diff dibaca; `api` diproses lebih dulu sehingga `api_id` terisi sebelum dibandingkan; `bash -n` OK | **Closed** |
| L5 (sentinel) | `SET rt05-staging-aof-sentinel persisted EX 86400`; kedaluwarsa sendiri tanpa operasi hapus | TTL tersimpan di AOF sebagai expire absolut, jadi `GET` setelah restart tetap valid dalam 24 jam | **Closed**. Batas tetap: restart terkontrol (SIGTERM), bukan crash; catat di bukti live |
| L7 | Tidak diubah | — | **Terbuka sebagai hardening M16 tersendiri**: DSN direct/owner (`DATABASE_URL_UNPOOLED`) hanya untuk service `migrate`, bukan container api runtime. Bukan penghalang staging M04, dan tidak berarti production sudah lulus |

Catatan F2 (rendah, tidak blocking): setelah branch `codex/m04-staging-recovery` di-merge/dihapus, SHA yang bukan leluhur `main` gagal karena `git fetch` ref yang hilang (fail-closed lewat `set -e`, pesan berasal dari git). Bare repo dari `clone --bare` tidak shallow sehingga ancestry dapat dihitung; bila suatu saat dibuat shallow, cek ini akan menolak (fail-closed), bukan meloloskan.

Pemeriksaan revalidasi: `bash -n` deploy/smoke/metadata OK; 5 tes Python OK; simulasi ancestry seperti di atas. Tidak ada eksekusi docker/VPS/env/Neon.

## Batas bukti yang belum terbukti

- Belum ada eksekusi `deploy-staging-immutable.sh`, `staging-runtime-smoke.sh`, maupun helper Python di VPS. Belum ada bukti SHA/image/volume/StartedAt dari server.
- Belum terbukti: Neon staging read/write lewat `rt05:db-smoke`, heartbeat empat komponen di VPS, persistensi AOF restart nyata, dan rollback (L2).
- CI untuk 03b4ac6 sedang berjalan menurut coordinator. Hasilnya tidak diklaim di sini. Koreksi F1/F2/L1/L4/L5 sudah ter-commit di `62a863f` (`git diff 62a863f -- infra .github` kosong terhadap tree yang direview). CI untuk SHA itu belum diklaim di sini.
- Gate M04 tetap **belum lulus** sampai bukti live di atas dicatat di `docs/evidence/m04/`.
