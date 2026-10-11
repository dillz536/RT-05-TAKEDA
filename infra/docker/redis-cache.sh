#!/bin/sh
# Cache, session CMS, dan rate limit. Tanpa persistensi; eviction allkeys-lru.
# TTL kunci ditentukan aplikasi. Port tidak dipetakan ke host.
set -eu
set -- redis-server \
  --bind 0.0.0.0 \
  --port 6379 \
  --save "" \
  --appendonly no \
  --maxmemory 128mb \
  --maxmemory-policy allkeys-lru
if [ -n "${REDIS_PASSWORD:-}" ]; then
  set -- "$@" --requirepass "$REDIS_PASSWORD"
else
  set -- "$@" --protected-mode no
fi
exec "$@"
