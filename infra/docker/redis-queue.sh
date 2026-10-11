#!/bin/sh
# Antrean RT05. AOF everysec + noeviction. Volume /data harus volume lama redis-data.
# Port tidak dipetakan ke host. Password hanya dari env runtime, tidak dari image.
set -eu
set -- redis-server \
  --bind 0.0.0.0 \
  --port 6379 \
  --appendonly yes \
  --appendfsync everysec \
  --maxmemory 256mb \
  --maxmemory-policy noeviction \
  --dir /data
if [ -n "${REDIS_PASSWORD:-}" ]; then
  set -- "$@" --requirepass "$REDIS_PASSWORD"
else
  set -- "$@" --protected-mode no
fi
exec "$@"
