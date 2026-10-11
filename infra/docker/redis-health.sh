#!/bin/sh
# Health Redis. REDISCLI_AUTH tidak dicetak.
set -eu
if [ -n "${REDIS_PASSWORD:-}" ]; then
  export REDISCLI_AUTH="$REDIS_PASSWORD"
fi
redis-cli ping | grep -q PONG
