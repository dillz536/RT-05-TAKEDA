#!/usr/bin/env bash
# Redis 7.4 sintetis untuk CI GitHub. Hanya 127.0.0.1.
# Membuktikan kebijakan antrean/cache, restart AOF, dan penghapusan kunci antrean
# berprefix drill. Tidak menghapus seluruh database. Tidak memakai Neon.
set -euo pipefail

PREFIX=rt05ci_
QUEUE=rt05-ci-redis-queue
CACHE=rt05-ci-redis-cache

if ! command -v docker >/dev/null 2>&1; then
  echo "Docker tidak tersedia. Drill Redis tidak dijalankan di mesin ini."
  exit 2
fi

docker rm -f "$QUEUE" "$CACHE" >/dev/null 2>&1 || true

docker run -d --name "$QUEUE" \
  -p 127.0.0.1:6379:6379 \
  -v rt05-ci-redis-aof:/data \
  redis:7.4-alpine \
  redis-server --bind 0.0.0.0 --port 6379 \
  --appendonly yes --appendfsync everysec \
  --maxmemory 256mb --maxmemory-policy noeviction \
  --protected-mode no --dir /data

docker run -d --name "$CACHE" \
  -p 127.0.0.1:6380:6379 \
  redis:7.4-alpine \
  redis-server --bind 0.0.0.0 --port 6379 \
  --save "" --appendonly no \
  --maxmemory 128mb --maxmemory-policy allkeys-lru \
  --protected-mode no

wait_ping() {
  local name=$1
  local i
  for i in $(seq 1 30); do
    if docker exec "$name" redis-cli ping | grep -q PONG; then
      return 0
    fi
    sleep 1
  done
  echo "Redis $name tidak menjawab" >&2
  docker logs "$name" >&2 || true
  exit 1
}

assert_loopback() {
  local name=$1 published=$2
  local line
  line=$(docker port "$name")
  echo "$line" | grep -q "$published"
  if echo "$line" | grep -q '0.0.0.0'; then
    echo "$name memetakan port di luar loopback" >&2
    exit 1
  fi
}

config_value() {
  docker exec "$1" redis-cli CONFIG GET "$2" | tr -d '\r' | tail -n 1
}

delete_prefixed_queue_keys() {
  local name=$1 prefix=$2 key
  case "$prefix" in
    rt05ci_|rt05test_) ;;
    *)
      echo "prefix antrean ditolak: $prefix" >&2
      exit 1
      ;;
  esac
  while IFS= read -r key; do
    [ -n "$key" ] || continue
    case "$key" in
      "${prefix}queues:"*)
        docker exec "$name" redis-cli DEL "$key" >/dev/null
        ;;
      *)
        echo "menolak kunci di luar antrean: $key" >&2
        exit 1
        ;;
    esac
  done < <(docker exec "$name" redis-cli --scan --pattern "${prefix}queues:*")
}

wait_ping "$QUEUE"
wait_ping "$CACHE"
assert_loopback "$QUEUE" '127.0.0.1:6379'
assert_loopback "$CACHE" '127.0.0.1:6380'

test "$(config_value "$QUEUE" maxmemory-policy)" = "noeviction"
test "$(config_value "$QUEUE" appendonly)" = "yes"
test "$(config_value "$QUEUE" appendfsync)" = "everysec"
test "$(config_value "$CACHE" maxmemory-policy)" = "allkeys-lru"
test "$(config_value "$CACHE" appendonly)" = "no"

# Tunggu flush everysec sebelum restart, mengikuti konfigurasi antrean.
docker exec "$QUEUE" redis-cli DEL "${PREFIX}queues:default" >/dev/null
docker exec "$QUEUE" redis-cli LPUSH "${PREFIX}queues:default" synthetic-job >/dev/null
sleep 2
docker restart "$QUEUE" >/dev/null
wait_ping "$QUEUE"
test "$(docker exec "$QUEUE" redis-cli LRANGE "${PREFIX}queues:default" 0 0 | tr -d '\r')" = "synthetic-job"

docker exec "$QUEUE" redis-cli SET other:keep yes >/dev/null
docker exec "$CACHE" redis-cli SET "${PREFIX}queues:default" cache-must-stay >/dev/null
delete_prefixed_queue_keys "$QUEUE" "$PREFIX"

test "$(docker exec "$QUEUE" redis-cli EXISTS "${PREFIX}queues:default" | tr -d '\r')" = "0"
test "$(docker exec "$QUEUE" redis-cli EXISTS other:keep | tr -d '\r')" = "1"
test "$(docker exec "$CACHE" redis-cli EXISTS "${PREFIX}queues:default" | tr -d '\r')" = "1"

docker exec "$QUEUE" redis-cli DEL other:keep >/dev/null
docker exec "$CACHE" redis-cli DEL "${PREFIX}queues:default" >/dev/null

echo "ci-redis: kebijakan, restart AOF, dan hapus kunci antrean prefix OK"
