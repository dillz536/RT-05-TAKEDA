#!/usr/bin/env bash
# Smoke runtime staging pada project rt05-staging yang sudah jalan.
# Bukan drill CI. Jangan dijalankan di PC pengembangan dan jangan masukkan ke api.yml.
# Tidak memakai pohon /opt/rt05-takeda. Tidak menghapus key antrean dan tidak mengosongkan Redis.
# Restart hanya worker-default dan redis antrean pada project ini.
set -euo pipefail

PROJECT=rt05-staging
ENV_FILE=/root/.rt05/.env.staging
RELEASES=/opt/rt05-releases
LEGACY=/opt/rt05-takeda
SENTINEL_VALUE=persisted

finish_ok=0
trap 'if [ "$finish_ok" != 1 ]; then echo "GAGAL: smoke staging berhenti sebelum PASS." >&2; fi' EXIT

fail() {
  echo "GAGAL: $*" >&2
  exit 1
}

[ "$#" -eq 0 ] || fail "Script ini tidak menerima argumen."
command -v docker >/dev/null || fail "Docker tidak ditemukan."
docker compose version >/dev/null

[ -f "$ENV_FILE" ] || fail "Env staging belum ada. Script tidak membuat secret."
[ ! -L "$ENV_FILE" ] || fail "Env staging adalah symlink."
mode=$(stat -c '%a' "$ENV_FILE")
[ "$mode" = "600" ] || fail "Mode env staging harus 600. Script tidak mengubah izin."
grep -qE '^REDIS_PASSWORD=.+' "$ENV_FILE" || fail "REDIS_PASSWORD wajib nonempty. Nilai tidak dicetak."
grep -qE '^REDIS_CACHE_PASSWORD=.+' "$ENV_FILE" || fail "REDIS_CACHE_PASSWORD wajib nonempty. Nilai tidak dicetak."
grep -qE '^APP_ENV=staging$' "$ENV_FILE" || fail "APP_ENV harus staging. Nilai tidak dicetak."
grep -qE '^RT05_ENV=(staging|stg)$' "$ENV_FILE" || fail "RT05_ENV harus staging atau alias existing stg."
grep -qE '^RT05_HOST=takeda-staging\.morriz\.tech$' "$ENV_FILE" || fail "RT05_HOST harus host staging yang ditetapkan."

resolve_sha() {
  local sha="" img
  if [ -f "$RELEASES/staging-current-sha" ]; then
    sha=$(head -n 1 "$RELEASES/staging-current-sha" | tr -d '[:space:]')
  fi
  if ! printf '%s' "$sha" | grep -qE '^[0-9a-f]{40}$'; then
    img=$(docker ps --filter label=com.docker.compose.project="$PROJECT" \
      --filter label=com.docker.compose.service=api --format '{{.Image}}' | head -n 1 || true)
    sha=$(printf '%s\n' "$img" | grep -oE '[0-9a-f]{40}$' || true)
  fi
  if ! printf '%s' "$sha" | grep -qE '^[0-9a-f]{40}$'; then
    echo "GAGAL: SHA staging aktif tidak diketahui. Tidak memakai $LEGACY." >&2
    return 1
  fi
  printf '%s\n' "$sha"
}

assert_staging_private() {
  local ports
  ports=$(docker ps --filter label=com.docker.compose.project="$PROJECT" --format '{{.Names}} {{.Ports}}')
  if printf '%s\n' "$ports" | grep -E '0\.0\.0\.0:|\[::\]:'; then
    fail "Service rt05-staging mempublikasikan port host."
  fi
}

wait_heartbeats() {
  local deadline=$((SECONDS + 180)) component ok
  echo ">>> Heartbeat empat komponen (batas 180 detik)"
  for component in worker-default worker-media worker-exports scheduler; do
    ok=0
    while [ "$SECONDS" -lt "$deadline" ]; do
      if timeout 15s "${DC[@]}" exec -T "$component" php artisan rt05:heartbeat-check --component "$component" --max-age=300; then
        ok=1
        break
      fi
      sleep 5
    done
    if [ "$ok" != 1 ]; then
      echo "heartbeat $component tidak sehat dalam batas waktu" >&2
      exit 1
    fi
  done
}

SHA=$(resolve_sha) || exit 1
export RT05_IMAGE_TAG="$SHA"
DEST="$RELEASES/$SHA"
case "$DEST" in
  "$LEGACY"|"$LEGACY"/*) fail "Compose smoke tidak boleh diambil dari pohon lama." ;;
esac
[ -f "$DEST/infra/compose.production.yml" ] || fail "Worktree SHA aktif tidak ada. Tidak memakai $LEGACY."
[ -f "$DEST/infra/compose.vps.yml" ] || fail "Worktree SHA aktif tidak memuat compose VPS."
cd "$DEST/infra"

DC=(docker compose -p "$PROJECT" --env-file "$ENV_FILE" -f compose.production.yml -f compose.vps.yml)

# shellcheck source=staging-metadata.sh
source "$DEST/infra/scripts/staging-metadata.sh"
assert_staging_images
before=$(list_unrelated)
volumes_before=$(list_staging_volumes)
echo ">>> Container di luar rt05-staging sebelum smoke"
printf '%s\n' "$before"
assert_staging_private

echo ">>> DbSmoke runtime pooled (transaksi di-rollback oleh command)"
"${DC[@]}" exec -T api php artisan --no-ansi --no-interaction rt05:db-smoke --connection=pgsql
echo ">>> DbSmoke direct migrasi (transaksi di-rollback oleh command)"
"${DC[@]}" run --rm --no-deps --pull never migrate php artisan --no-ansi --no-interaction rt05:db-smoke --connection=pgsql_migrations

echo ">>> Stop only worker-default before queuing the synthetic marker"
# Restore this worker on any failed assertion; never delete queued jobs.
cleanup() {
  if [ "${worker_stopped:-0}" = 1 ]; then
    "${DC[@]}" start worker-default || true
  fi
  if [ "$finish_ok" != 1 ]; then
    echo "GAGAL: smoke staging berhenti sebelum PASS." >&2
  fi
}
trap cleanup EXIT
worker_stopped=1
"${DC[@]}" stop worker-default
echo ">>> rt05:staging-smoke prepare"
raw=$("${DC[@]}" exec -T api php artisan --no-ansi --no-interaction rt05:staging-smoke --confirm-staging --phase=prepare)
mapfile -t uuid_lines < <(printf '%s\n' "$raw" | grep -oE '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}' || true)
[ "${#uuid_lines[@]}" -eq 1 ] || fail "prepare tidak mencetak tepat satu UUID."
EVENT_ID=${uuid_lines[0]}
echo "event=$EVENT_ID"

echo ">>> rt05:outbox-dispatch with consumer stopped"
"${DC[@]}" exec -T api php artisan --no-ansi --no-interaction rt05:outbox-dispatch

# Require a published marker, not an arbitrary CLI/DB failure or early completion.
pending_code=0
pending=$("${DC[@]}" exec -T api php artisan --no-ansi --no-interaction rt05:staging-smoke --confirm-staging --phase=verify --event="$EVENT_ID" --timeout=0) || pending_code=$?
[ "$pending_code" = 1 ] || fail "Expected a pending synthetic marker with stopped consumer."
printf '%s\n' "$pending" | grep -Eq '^state=published attempts=[1-9][0-9]* inbox=0 error_code=none$' || fail "Marker not published cleanly before Redis restart."
printf '%s\n' "$pending" | grep '^state='
attempts_before=$(printf '%s\n' "$pending" | grep '^state=' | sed -E 's/.* attempts=([0-9]+) .*/\1/')

echo ">>> Cek AOF antrean lalu tulis sentinel. Cache tidak dihentikan."
if ! "${DC[@]}" exec -T redis /bin/sh -c 'set -eu
export REDISCLI_AUTH="$REDIS_PASSWORD"
redis-cli --no-auth-warning CONFIG GET appendonly | grep -qx yes
redis-cli --no-auth-warning CONFIG GET appendfsync | grep -qx everysec
redis-cli --no-auth-warning SET rt05-staging-aof-sentinel persisted EX 86400 | grep -qx OK'; then
  fail "Kebijakan AOF antrean bukan appendonly/everysec, atau sentinel tidak tertulis."
fi
sleep 2
echo ">>> Restart hanya redis antrean pada project rt05-staging"
"${DC[@]}" restart redis

pong_ok=0
for _ in $(seq 1 24); do
  if "${DC[@]}" exec -T redis /bin/sh -c 'set -eu
export REDISCLI_AUTH="$REDIS_PASSWORD"
redis-cli --no-auth-warning ping' | grep -q PONG; then
    pong_ok=1
    break
  fi
  sleep 5
done
[ "$pong_ok" = 1 ] || fail "Redis antrean tidak menjawab PONG dalam 120 detik."

got=$("${DC[@]}" exec -T redis /bin/sh -c 'set -eu
export REDISCLI_AUTH="$REDIS_PASSWORD"
redis-cli --no-auth-warning GET rt05-staging-aof-sentinel')
normalized=$(printf '%s' "$got" | tr -d '[:space:]')
[ "$normalized" = "$SENTINEL_VALUE" ] || fail "Sentinel AOF tidak bertahan setelah restart redis antrean."
echo "sentinel AOF bertahan"
unset got normalized

echo ">>> Start only worker-default after Redis restart"
"${DC[@]}" start worker-default
worker_stopped=0
completed=$("${DC[@]}" exec -T api php artisan --no-ansi --no-interaction rt05:staging-smoke --confirm-staging --phase=verify --event="$EVENT_ID" --timeout=120)
printf '%s\n' "$completed"
printf '%s\n' "$completed" | grep -Fxq "state=completed attempts=$attempts_before inbox=0 error_code=none" || fail "Marker required replay instead of surviving AOF restart."
wait_heartbeats
assert_staging_images
assert_staging_volumes "$volumes_before"
assert_staging_private
assert_unrelated "$before"

finish_ok=1
echo "PASS staging-runtime-smoke"
