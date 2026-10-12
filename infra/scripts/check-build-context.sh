#!/usr/bin/env bash
# Regression test in the disposable GitHub runner only; no real env/DB/host service access.
set -euo pipefail
[ "${GITHUB_ACTIONS:-}" = true ] || { echo "CI runner required" >&2; exit 1; }
ROOT=$(git rev-parse --show-toplevel)
cd "$ROOT"
TMP=$(mktemp -d "${RUNNER_TEMP:?}/rt05-context.XXXXXX")
IMAGE=rt05ci-context-audit
CONTAINER=""
CREATED=()
cleanup() {
  [ -z "$CONTAINER" ] || docker rm "$CONTAINER" >/dev/null
  docker image rm "$IMAGE" >/dev/null 2>&1 || true
  for path in "${CREATED[@]}"; do rm -f -- "$path"; done
  rm -rf -- "$TMP"
}
trap cleanup EXIT
for path in rest-api/.env.rt05-ci-canary website/.env.rt05-ci-canary infra/env/.env.rt05-ci-canary \
  rest-api/bootstrap/cache/rt05-ci-canary.php website/bootstrap/cache/rt05-ci-canary.php; do
  [ ! -e "$path" ] && [ ! -L "$path" ] || { echo "Canary path already exists; refusing overwrite" >&2; exit 1; }
  mkdir -p "$(dirname "$path")"
  printf 'synthetic-build-context-canary\n' > "$path"
  CREATED+=("$path")
done
cat > "$TMP/Dockerfile" <<'DOCKERFILE'
FROM scratch
COPY rest-api/ /api/
COPY website/ /web/
COPY infra/env/ /env/
DOCKERFILE
docker build -q -f "$TMP/Dockerfile" -t "$IMAGE" . >/dev/null
CONTAINER=$(docker create "$IMAGE" unused)
docker export "$CONTAINER" | tar -tf - > "$TMP/paths"
if grep -q 'rt05-ci-canary' "$TMP/paths"; then
  echo "FAIL: secret/cache canary entered build context" >&2; exit 1
fi
for path in api/config/database.php web/public/images/logo/rt05-mark.svg \
  api/.env.example web/.env.example env/production.env.example; do
  grep -Fxq "$path" "$TMP/paths" || { echo "FAIL: legitimate source/example excluded" >&2; exit 1; }
done
echo "PASS: BuildKit excludes three synthetic env files and two cached PHP files, retaining source/brand/examples"
