#!/usr/bin/env bash
# Drill worker nyata M03 pada Postgres *_test dan Redis loopback CI.
# Memakai rt05:async-smoke, rt05:outbox-dispatch, dan rt05:heartbeat-check.
# Tidak menghapus seluruh database Redis. Tidak membaca .env dan tidak menyentuh Neon.
set -euo pipefail

artisan() {
  command php artisan --no-ansi --no-interaction "$@"
}

ROOT=$(cd "$(dirname "$0")/../.." && pwd)
QUEUE_CONTAINER=rt05-ci-redis-queue
PREFIX=${REDIS_PREFIX:-takeda:ci:}
KEY_BASE="${PREFIX}queues:notifications"
QUEUE_KEYS=(
  "$KEY_BASE"
  "${KEY_BASE}:delayed"
  "${KEY_BASE}:reserved"
  "${KEY_BASE}:notify"
)
SENTINEL="${PREFIX}sentinel"
LOG=/tmp/rt05-m03-worker.log
worker_pid=
scheduler_pid=

export APP_ENV="${APP_ENV:-ci}"
export QUEUE_CONNECTION=redis
export CACHE_STORE=redis
export SESSION_DRIVER=redis
export SESSION_CONNECTION=cache
export REDIS_CLIENT="${REDIS_CLIENT:-phpredis}"
export REDIS_QUEUE_CONNECTION=queue
export REDIS_QUEUE_RETRY_AFTER=180
export REDIS_PREFIX="$PREFIX"
export REDIS_HOST="${REDIS_HOST:-127.0.0.1}"
export REDIS_PORT="${REDIS_PORT:-6379}"
export REDIS_QUEUE_HOST="${REDIS_QUEUE_HOST:-127.0.0.1}"
export REDIS_QUEUE_PORT="${REDIS_QUEUE_PORT:-6379}"
export REDIS_QUEUE_DB="${REDIS_QUEUE_DB:-0}"
export REDIS_CACHE_HOST="${REDIS_CACHE_HOST:-127.0.0.1}"
export REDIS_CACHE_PORT="${REDIS_CACHE_PORT:-6380}"
export REDIS_CACHE_DB="${REDIS_CACHE_DB:-1}"
export RT05_OUTBOX_STALE_SECONDS="${RT05_OUTBOX_STALE_SECONDS:-5}"
export RT05_HEARTBEAT_STORE="${RT05_HEARTBEAT_STORE:-coordination}"
export RT05_HEARTBEAT_WRITE_INTERVAL="${RT05_HEARTBEAT_WRITE_INTERVAL:-1}"

case "${DATABASE_URL:-}" in
  *://*@127.0.0.1:*/*_test|*://*@127.0.0.1:*/*_test\?*|*://*@localhost:*/*_test|*://*@localhost:*/*_test\?*|*://*@postgres:*/*_test|*://*@postgres:*/*_test\?*) ;;
  *)
    echo "DATABASE_URL ditolak: drill hanya database *_test pada host lokal/CI."
    exit 1
    ;;
esac
case "${DATABASE_URL}" in
  *neon.tech*) echo "DATABASE_URL ditolak: host Neon."; exit 1 ;;
esac

stop_pid() {
  local pid=${1:-}
  if [ -n "$pid" ] && kill -0 "$pid" 2>/dev/null; then
    kill -CONT "$pid" 2>/dev/null || true
    kill -TERM "$pid" 2>/dev/null || true
    wait "$pid" 2>/dev/null || true
  fi
}

cleanup() {
  stop_pid "${worker_pid:-}"
  stop_pid "${scheduler_pid:-}"
  worker_pid=
  scheduler_pid=
}
trap cleanup EXIT

start_worker() {
  stop_worker
  : >"$LOG"
  (cd "$ROOT/rest-api"; exec php artisan --no-ansi --no-interaction queue:work redis --queue=notifications,default --sleep=1 --tries=5 --timeout=60 --max-time=180) >>"$LOG" 2>&1 &
  worker_pid=$!
}

stop_worker() {
  stop_pid "${worker_pid:-}"
  worker_pid=
}

redis_cli() {
  docker exec "$QUEUE_CONTAINER" redis-cli "$@"
}

wait_redis() {
  local i
  for i in $(seq 1 30); do
    if redis_cli ping | grep -q PONG; then
      return 0
    fi
    sleep 1
  done
  echo "Redis antrean CI tidak menjawab" >&2
  exit 1
}

prepare_event() {
  local line id
  line=$(cd "$ROOT/rest-api" && artisan rt05:async-smoke --phase=prepare --with-inbox | tr -d '\r' | sed -n 's/^event_id=//p')
  id=$(printf '%s\n' "$line" | tail -n 1)
  if ! printf '%s' "$id" | grep -Eq '^[0-9a-fA-F-]{36}$'; then
    echo "event_id dari async-smoke tidak valid" >&2
    exit 1
  fi
  printf '%s' "$id"
}

dispatch_outbox() {
  (cd "$ROOT/rest-api" && artisan rt05:outbox-dispatch)
}

verify_event() {
  local id=$1 timeout=$2
  (cd "$ROOT/rest-api" && artisan rt05:async-smoke --phase=verify --event="$id" --timeout="$timeout" --expect-inbox=1)
}

psql_scalar() {
  psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -Atc "$1"
}

assert_inbox() {
  local id=$1
  local count completed
  count=$(psql_scalar "select count(*) from notifikasi where event_id = '${id}'")
  completed=$(psql_scalar "select case when completed_at is not null and failed_at is null then 'yes' else 'no' end from outbox_events where id = '${id}'")
  if [ "$count" != 1 ] || [ "$completed" != yes ]; then
    echo "assert inbox gagal count=$count completed=$completed" >&2
    exit 1
  fi
}

delete_notification_queue_keys() {
  local key
  for key in "${QUEUE_KEYS[@]}"; do
    case "$key" in
      "${PREFIX}queues:notifications"|"${PREFIX}queues:notifications:delayed"|"${PREFIX}queues:notifications:reserved"|"${PREFIX}queues:notifications:notify")
        redis_cli DEL "$key" >/dev/null
        ;;
      *)
        echo "kunci antrean di luar daftar notifications: $key" >&2
        exit 1
        ;;
    esac
  done
}

if ! command -v docker >/dev/null 2>&1; then
  echo "Docker tidak tersedia. Drill worker tidak dijalankan di mesin ini."
  exit 2
fi
if ! docker exec "$QUEUE_CONTAINER" redis-cli ping | grep -q PONG; then
  echo "Container $QUEUE_CONTAINER belum jalan. Jalankan infra/scripts/ci-redis.sh lebih dulu." >&2
  exit 1
fi

cd "$ROOT/rest-api"
artisan migrate --database=pgsql_migrations --force

echo ">>> kill/restart: jeda PID PHP ini, commit, lalu proses ulang"
start_worker
heartbeat_ok=0
for _ in $(seq 1 20); do
  if artisan rt05:heartbeat-check --component=worker-default --max-age=300; then
    heartbeat_ok=1
    break
  fi
  sleep 1
done
if [ "$heartbeat_ok" != 1 ]; then
  echo "heartbeat worker-default gagal" >&2
  tail -n 40 "$LOG" >&2 || true
  exit 1
fi
kill -STOP "$worker_pid"
deferred=$(prepare_event)
dispatch_outbox
test "$(psql_scalar "select count(*) from notifikasi where event_id = '${deferred}'")" = 0
test "$(psql_scalar "select count(*) from outbox_events where id = '${deferred}' and published_at is not null and completed_at is null")" = 1
kill -KILL "$worker_pid"
wait "$worker_pid" 2>/dev/null || true
worker_pid=
start_worker
verify_event "$deferred" 60
assert_inbox "$deferred"
stop_worker

echo ">>> duplikat job Redis tetap satu inbox"
duplicate=$(prepare_event)
dispatch_outbox
copied=$(redis_cli EVAL "local v=redis.call('LINDEX', KEYS[1], 0); if not v then return 0 end; redis.call('LPUSH', KEYS[1], v); return 1" 1 "$KEY_BASE" | tr -d '\r')
if [ "$copied" != 1 ]; then
  echo "job notifications tidak ada sebelum duplikasi" >&2
  redis_cli --scan --pattern "${PREFIX}queues:*" >&2 || true
  exit 1
fi
start_worker
verify_event "$duplicate" 60
# Tunggu kedua delivery selesai, bukan berhenti sesudah delivery pertama.
drained=0
for _ in $(seq 1 30); do
  pending=$(redis_cli LLEN "$KEY_BASE" | tr -d '\r')
  reserved=$(redis_cli ZCARD "${KEY_BASE}:reserved" | tr -d '\r')
  if [ "$pending" = 0 ] && [ "$reserved" = 0 ]; then drained=1; break; fi
  sleep 1
done
test "$drained" = 1
assert_inbox "$duplicate"
stop_worker

echo ">>> AOF everysec: tunggu flush, restart, lalu inbox"
if [ "$(redis_cli CONFIG GET appendfsync | tr -d '\r' | tail -n 1)" != everysec ]; then
  echo "appendfsync bukan everysec" >&2
  exit 1
fi
redis_cli SET "$SENTINEL" keep >/dev/null
aof_event=$(prepare_event)
dispatch_outbox
before=$(redis_cli LLEN "$KEY_BASE" | tr -d '\r')
if [ "${before:-0}" -lt 1 ]; then
  echo "LLEN antrean sebelum restart = $before" >&2
  exit 1
fi
sleep 2
docker restart "$QUEUE_CONTAINER" >/dev/null
wait_redis
after=$(redis_cli LLEN "$KEY_BASE" | tr -d '\r')
sentinel=$(redis_cli GET "$SENTINEL" | tr -d '\r')
if [ "${after:-0}" -lt 1 ] || [ "$sentinel" != keep ]; then
  echo "AOF tidak mempertahankan job atau sentinel (llen=$after sentinel=$sentinel)" >&2
  exit 1
fi
start_worker
verify_event "$aof_event" 60
assert_inbox "$aof_event"
stop_worker

echo ">>> queue-loss: hapus hanya kunci notifications, sentinel tetap, lalu replay stale"
loss_event=$(prepare_event)
dispatch_outbox
redis_cli SET "$SENTINEL" keep >/dev/null
delete_notification_queue_keys
if [ "$(redis_cli EXISTS "$KEY_BASE" | tr -d '\r')" != 0 ]; then
  echo "kunci antrean notifications masih ada" >&2
  exit 1
fi
if [ "$(redis_cli GET "$SENTINEL" | tr -d '\r')" != keep ]; then
  echo "sentinel hilang saat penghapusan kunci antrean" >&2
  exit 1
fi
sleep 10
dispatch_outbox
start_worker
verify_event "$loss_event" 60
assert_inbox "$loss_event"
stop_worker

echo ">>> heartbeat scheduler (batas 100 detik)"
(cd "$ROOT/rest-api"; exec php artisan --no-ansi --no-interaction schedule:work) >>"$LOG" 2>&1 &
scheduler_pid=$!
scheduler_ok=0
for _ in $(seq 1 20); do
  if artisan rt05:heartbeat-check --component=scheduler --max-age=300; then
    scheduler_ok=1
    break
  fi
  sleep 5
done
stop_pid "$scheduler_pid"
scheduler_pid=
if [ "$scheduler_ok" != 1 ]; then
  echo "heartbeat scheduler gagal dalam batas waktu" >&2
  exit 1
fi

echo "m03-recovery: kill/restart, duplikat, AOF, queue-loss, heartbeat OK"
