#!/usr/bin/env bash
# Deploy staging RT05 TAKEDA dari SHA yang sudah ada di origin tepercaya.
# Dijalankan coordinator di konsol Hostinger. Jangan dijalankan di PC pengembangan.
# Hanya staging. Tidak membuat atau mengubah secret. Tidak menyentuh production.
# Pohon /opt/rt05-takeda tidak di-fetch, tidak ditimpa, dan tidak dijadikan direktori rilis.
set -euo pipefail

export GIT_TERMINAL_PROMPT=0

ORIGIN=https://github.com/morrispes5/RT-05-TAKEDA.git
RELEASES=/opt/rt05-releases
BARE="$RELEASES/origin.git"
LEGACY=/opt/rt05-takeda
PROJECT=rt05-staging
ENV_FILE=/root/.rt05/.env.staging
SERVICES=(gateway api web worker-default worker-media worker-exports scheduler redis redis-cache)

finish_ok=0
trap 'if [ "$finish_ok" != 1 ]; then echo "GAGAL: deploy staging berhenti sebelum selesai." >&2; fi' EXIT

fail() {
  echo "GAGAL: $*" >&2
  exit 1
}

[ "$#" -eq 1 ] || fail "Pakai: $0 <sha40> atau $0 rollback"
[ "$1" != "production" ] || fail "Script ini hanya untuk staging."

require_env() {
  if ! grep -qE "^${1}=.+" "$ENV_FILE"; then
    echo "Env $1 wajib dan tidak boleh kosong." >&2
    exit 1
  fi
}

require_identity() {
  if ! grep -qE "^${1}=${2}$" "$ENV_FILE"; then
    echo "Env $1 harus persis nilai staging yang ditetapkan. Nilai tidak dicetak." >&2
    exit 1
  fi
}

preflight() {
  command -v docker >/dev/null || fail "Docker tidak ditemukan."
  command -v git >/dev/null || fail "Git tidak ditemukan."
  docker version --format 'server={{.Server.Version}} client={{.Client.Version}}'
  docker compose version
  echo ">>> OS"
  uname -srmo
  if [ -r /etc/os-release ]; then
    # shellcheck disable=SC1091
    . /etc/os-release
    echo "${PRETTY_NAME:-unknown}"
  fi
  echo ">>> Headroom"
  df -P -h / /opt || df -P -h /
  free -m || true
  avail_kb=$(df -P -k /opt | awk 'NR==2 {print $4}')
  [ -n "${avail_kb:-}" ] || fail "Headroom disk /opt tidak terbaca."
  if [ "$avail_kb" -lt 5242880 ]; then
    fail "Disk tersedia di /opt di bawah 5 GiB ($avail_kb KiB)."
  fi
  mem_kb=$(awk '/MemAvailable/ {print $2}' /proc/meminfo)
  [ -n "${mem_kb:-}" ] || fail "MemAvailable tidak terbaca."
  if [ "$mem_kb" -lt 524288 ]; then
    fail "MemAvailable di bawah 512 MiB ($mem_kb KiB)."
  fi
  docker network ls --format '{{.Name}}' | grep -qx coolify || fail "Jaringan docker coolify tidak ada."
  [ -f "$ENV_FILE" ] || fail "Env staging belum ada di /root/.rt05/.env.staging. Script tidak membuat secret."
  [ ! -L "$ENV_FILE" ] || fail "Env staging adalah symlink. Script tidak mengubahnya."
  mode=$(stat -c '%a' "$ENV_FILE")
  [ "$mode" = "600" ] || fail "Mode env staging harus 600 (sekarang $mode). Script tidak mengubah izin atau isinya."
  require_env DATABASE_URL
  require_env DATABASE_URL_UNPOOLED
  require_env API_APP_KEY
  require_env WEB_APP_KEY
  require_env REDIS_PASSWORD
  require_env REDIS_CACHE_PASSWORD
  require_identity APP_ENV staging
  # Existing staging uses stg for its Traefik labels; preserve that routing name.
  require_identity RT05_ENV '(staging|stg)'
  require_identity RT05_HOST 'takeda-staging\.morriz\.tech'
  echo ">>> Container berjalan (id, nama, status)"
  docker ps --format '{{.ID}} {{.Names}} {{.Status}}'
  echo ">>> Project rt05-staging (id, nama, service, status)"
  docker ps -a --filter label=com.docker.compose.project=rt05-staging \
    --format '{{.ID}} {{.Names}} {{.Label "com.docker.compose.service"}} {{.Status}}'
  if docker ps --format '{{.Names}} {{.Ports}}' | grep -E '0\.0\.0\.0:(6379|6380)|\[::\]:(6379|6380)'; then
    fail "Redis terpublikasi pada interface publik. Deploy ditolak."
  fi
  if [ -d "$LEGACY/.git" ]; then
    echo ">>> Nama path kotor di $LEGACY (tanpa diff). Pohon ini tidak ditimpa."
    git -c safe.directory="$LEGACY" -C "$LEGACY" status --porcelain \
      || echo "Status pohon lama tidak terbaca. Pohon itu tidak ditimpa."
  else
    echo ">>> $LEGACY tidak diubah (bukan worktree yang dibaca)."
  fi
}

assert_staging_private() {
  local ports
  ports=$(docker ps --filter label=com.docker.compose.project="$PROJECT" --format '{{.Names}} {{.Ports}}')
  if printf '%s\n' "$ports" | grep -E '0\.0\.0\.0:|\[::\]:'; then
    fail "Service rt05-staging mempublikasikan port host."
  fi
}

record_previous() {
  local target="$1"
  local img sha
  img=$(docker ps --filter label=com.docker.compose.project="$PROJECT" \
    --filter label=com.docker.compose.service=api --format '{{.Image}}' | head -n 1 || true)
  sha=$(printf '%s\n' "$img" | grep -oE '[0-9a-f]{40}$' || true)
  if [ -n "$sha" ] && [ "$sha" != "$target" ]; then
    printf '%s\n' "$sha" > "$RELEASES/staging-previous-sha"
    echo ">>> SHA image api sebelumnya dicatat untuk rollback."
  else
    echo ">>> Tidak ada tag SHA pada api yang sedang berjalan. Rollback SHA belum tersedia."
  fi
}

prepare_worktree() {
  local sha="$1"
  local dest="$RELEASES/$sha"
  case "$dest" in
    "$LEGACY"|"$LEGACY"/*) fail "Direktori rilis tidak boleh berada di pohon lama." ;;
  esac
  mkdir -p "$RELEASES"
  if [ ! -d "$BARE" ]; then
    git -c safe.directory="$RELEASES" clone --bare "$ORIGIN" "$BARE"
  fi
  actual=$(git -c safe.directory="$BARE" -C "$BARE" remote get-url origin)
  case "$actual" in
    https://github.com/morrispes5/RT-05-TAKEDA.git|https://github.com/morrispes5/RT-05-TAKEDA|git@github.com:morrispes5/RT-05-TAKEDA.git)
      ;;
    *) fail "Origin bare repo bukan repositori tepercaya. Remote tidak diubah." ;;
  esac
  # A GitHub object SHA may also belong to an unreviewed fork/PR. Require
  # ancestry of main or the exact coordinator-owned M04 branch before source/build.
  git -c safe.directory="$BARE" -C "$BARE" fetch --no-tags origin refs/heads/main
  local approved_head trusted=0
  approved_head=$(git -c safe.directory="$BARE" -C "$BARE" rev-parse FETCH_HEAD)
  if git -c safe.directory="$BARE" -C "$BARE" merge-base --is-ancestor "$sha" "$approved_head"; then
    trusted=1
  else
    git -c safe.directory="$BARE" -C "$BARE" fetch --no-tags origin refs/heads/codex/m04-staging-recovery
    approved_head=$(git -c safe.directory="$BARE" -C "$BARE" rev-parse FETCH_HEAD)
    if git -c safe.directory="$BARE" -C "$BARE" merge-base --is-ancestor "$sha" "$approved_head"; then
      trusted=1
    fi
  fi
  [ "$trusted" = 1 ] || fail "SHA is not an ancestor of an approved RT05 ref."
  git -c safe.directory="$BARE" -C "$BARE" cat-file -e "${sha}^{commit}"
  if [ -e "$dest" ]; then
    if [ ! -f "$dest/.git" ] && [ ! -d "$dest/.git" ]; then
      fail "Path rilis sudah ada dan bukan worktree git. Path tidak ditimpa."
    fi
    got=$(git -c safe.directory="$dest" -C "$dest" rev-parse HEAD)
    [ "$got" = "$sha" ] || fail "Worktree rilis ada tetapi HEAD bukan SHA diminta. Path tidak ditimpa."
  else
    git -c safe.directory="$BARE" -C "$BARE" worktree add --detach "$dest" "$sha"
  fi
  got=$(git -c safe.directory="$dest" -C "$dest" rev-parse HEAD)
  [ "$got" = "$sha" ] || fail "HEAD rilis bukan SHA yang diminta."
  if [ -n "$(git -c safe.directory="$dest" -C "$dest" status --porcelain)" ]; then
    fail "Worktree rilis kotor. Tidak di-reset dan tidak dipakai."
  fi
  [ -f "$dest/infra/compose.production.yml" ] || fail "SHA tidak memuat compose production."
  [ -f "$dest/infra/compose.vps.yml" ] || fail "SHA tidak memuat compose VPS."
}

deploy_sha() {
  local sha="$1"
  printf '%s' "$sha" | grep -qE '^[0-9a-f]{40}$' || fail "Argumen harus SHA 40 hex huruf kecil."
  preflight
  local before
  prepare_worktree "$sha"
  local dest="$RELEASES/$sha"
  # Refuse incompatible source/rollback before changing running services.
  grep -Fq 'image: rt05-api:${RT05_IMAGE_TAG:' "$dest/infra/compose.production.yml" || fail "SHA lacks immutable API images."
  grep -Fq 'image: rt05-web:${RT05_IMAGE_TAG:' "$dest/infra/compose.production.yml" || fail "SHA lacks immutable web images."
  grep -Fq 'REDIS_PASSWORD: ${REDIS_PASSWORD:?' "$dest/infra/compose.production.yml" || fail "SHA lacks required queue AUTH."
  grep -Fq 'REDIS_PASSWORD: ${REDIS_CACHE_PASSWORD:?' "$dest/infra/compose.production.yml" || fail "SHA lacks required cache AUTH."
  grep -q '^  worker-default:' "$dest/infra/compose.production.yml" || fail "SHA lacks compatible worker topology."
  [ -f "$dest/infra/scripts/staging-metadata.sh" ] || fail "SHA lacks staging metadata guards."
  # shellcheck source=staging-metadata.sh
  source "$dest/infra/scripts/staging-metadata.sh"
  before=$(list_unrelated)
  local volumes_before
  volumes_before=$(list_staging_volumes)
  echo ">>> Unrelated containers before (ID/name/StartedAt)"
  printf '%s\n' "$before"
  echo ">>> Staging volumes before"
  printf '%s\n' "$volumes_before"
  record_previous "$sha"
  export RT05_IMAGE_TAG="$sha"
  # shellcheck disable=SC2174
  cd "$dest/infra"
  local -a DC
  DC=(docker compose -p "$PROJECT" --env-file "$ENV_FILE" -f compose.production.yml -f compose.vps.yml)
  echo ">>> Build hanya api dan web. Tidak ada push registry."
  "${DC[@]}" build api web
  echo ">>> Redis antrean dan cache, lalu migrasi direct, tanpa menghapus volume."
  "${DC[@]}" up -d --no-build redis redis-cache
  "${DC[@]}" run --rm --no-build migrate
  # Orphan hanya di dalam project rt05-staging (worker tunggal lama, bila label-nya project ini).
  "${DC[@]}" up -d --no-build --remove-orphans "${SERVICES[@]}"
  echo ">>> Health ready (batas 120 detik, body tidak dicetak)"
  local ready_ok=0 ready ready_deadline=$((SECONDS + 120))
  while [ "$SECONDS" -lt "$ready_deadline" ]; do
    ready=$(timeout 10s "${DC[@]}" exec -T api php -r 'echo @file_get_contents("http://127.0.0.1/health/ready", false, stream_context_create(["http" => ["timeout" => 5]])) ?: "";' || true)
    if printf '%s' "$ready" | grep -q '"status":"ready"'; then
      ready_ok=1
      break
    fi
    sleep 5
  done
  if [ "$ready_ok" != 1 ]; then
    echo "health ready gagal" >&2
    exit 1
  fi
  echo ">>> Heartbeat worker/scheduler (batas 180 detik)"
  local deadline=$((SECONDS + 180)) component ok
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
  assert_staging_private
  assert_staging_images
  assert_staging_volumes "$volumes_before"
  assert_unrelated "$before"
  printf '%s\n' "$sha" > "$RELEASES/staging-current-sha"
  "${DC[@]}" ps
  echo ">>> Selesai staging SHA $sha. Smoke runtime terpisah: infra/scripts/staging-runtime-smoke.sh"
  finish_ok=1
}

if [ "$1" = "rollback" ]; then
  [ -f "$RELEASES/staging-previous-sha" ] || fail "Tidak ada SHA sebelumnya. Volume tidak diubah."
  prev=$(head -n 1 "$RELEASES/staging-previous-sha" | tr -d '[:space:]')
  printf '%s' "$prev" | grep -qE '^[0-9a-f]{40}$' || fail "Berkas SHA sebelumnya tidak valid. Volume tidak diubah."
  echo ">>> Rollback staging ke SHA sebelumnya, tanpa menghapus volume."
  deploy_sha "$prev"
else
  deploy_sha "$1"
fi
