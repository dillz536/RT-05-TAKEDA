#!/usr/bin/env bash
# Deploy RT05 TAKEDA ke VPS Hostinger (Coolify sudah terpasang). Dijalankan PEMILIK di Konsol web/SSH:
#   curl -fsSL https://raw.githubusercontent.com/morrispes5/RT-05-TAKEDA/main/infra/scripts/deploy-vps.sh -o /root/rt05-deploy.sh
#   bash /root/rt05-deploy.sh staging        # atau: production
# Pertama kali, script meminta isi env (tempel isi file infra/env/.env.<env> dari PC, lalu Ctrl-D).
# Aman diulang: menolak worktree kotor, lalu checkout detach ke origin/main atau RT05_REV.
# Worktree yang masih berubah tidak ditimpa.
# Sebelum menjalankan ulang pada server yang sudah ada, tambahkan REDIS_PASSWORD dan
# REDIS_CACHE_PASSWORD nonempty ke env server (nilai tidak dicetak). Prefix kanonik
# takeda:<APP_ENV>: dipakai bila REDIS_PREFIX tidak diisi; nilai prefix lama yang eksplisit tetap dipakai.
# Tidak menyentuh service/container lain, port 80/443, firewall, atau DNS.
set -euo pipefail

ENV_NAME="${1:-}"
case "$ENV_NAME" in staging|production) ;; *) echo "Pakai: $0 staging|production"; exit 1 ;; esac

DIR=/opt/rt05-takeda
REPO=https://github.com/morrispes5/RT-05-TAKEDA.git
PROJECT="rt05-$ENV_NAME"
SECRETS=/root/.rt05
ENV_FILE="$SECRETS/.env.$ENV_NAME"

command -v docker >/dev/null || { echo "Docker tidak ditemukan."; exit 1; }
docker network inspect coolify >/dev/null 2>&1 || { echo "Jaringan docker 'coolify' tidak ada (proxy Coolify belum jalan)."; exit 1; }

if [ -d "$DIR/.git" ]; then
  if [ -n "$(git -C "$DIR" status --porcelain)" ]; then
    echo "Worktree $DIR kotor. Deploy ditolak; tidak ada reset."
    exit 1
  fi
  git -C "$DIR" fetch --depth 1 origin main
  if [ -n "${RT05_REV:-}" ]; then
    git -C "$DIR" fetch --depth 1 origin "$RT05_REV"
    git -C "$DIR" checkout --detach "$RT05_REV"
  else
    git -C "$DIR" checkout --detach origin/main
  fi
else
  git clone --depth 1 "$REPO" "$DIR"
  if [ -n "${RT05_REV:-}" ]; then
    git -C "$DIR" fetch --depth 1 origin "$RT05_REV"
    git -C "$DIR" checkout --detach "$RT05_REV"
  fi
fi

mkdir -p "$SECRETS" && chmod 700 "$SECRETS"
if [ ! -s "$ENV_FILE" ]; then
  echo ">>> Tempel isi .env.$ENV_NAME (dari PC: RT-05-TAKEDA/infra/env/.env.$ENV_NAME), lalu tekan Ctrl-D:"
  (umask 077; cat > "$ENV_FILE")
fi
chmod 600 "$ENV_FILE"
require_env() {
  if ! grep -qE "^${1}=.+" "$ENV_FILE"; then
    echo "Env $1 wajib dan tidak boleh kosong."
    exit 1
  fi
}
require_env DATABASE_URL
require_env DATABASE_URL_UNPOOLED
require_env REDIS_PASSWORD
require_env REDIS_CACHE_PASSWORD
require_env API_APP_KEY

cd "$DIR/infra"
DC=(docker compose -p "$PROJECT" --env-file "$ENV_FILE" -f compose.production.yml -f compose.vps.yml)
"${DC[@]}" config --format json | python3 scripts/check_database_isolation.py

echo ">>> Build image (beberapa menit pada build pertama)"
"${DC[@]}" build
echo ">>> Redis antrean dan cache, lalu migrasi database (endpoint direct)"
"${DC[@]}" up -d redis redis-cache
"${DC[@]}" run --rm migrate
echo ">>> Menjalankan layanan"
"${DC[@]}" up -d --remove-orphans gateway api web worker-default worker-media worker-exports scheduler redis redis-cache
echo ">>> Health ready (batas 60 detik, tanpa mencetak body)"
ready_ok=0
for _ in $(seq 1 12); do
  ready=$("${DC[@]}" exec -T api php -r 'echo @file_get_contents("http://127.0.0.1/health/ready") ?: "";' || true)
  if printf '%s' "$ready" | grep -q '"status":"ready"'; then
    ready_ok=1
    break
  fi
  sleep 5
done
if [ "$ready_ok" != 1 ]; then
  echo "health ready gagal"
  exit 1
fi
echo ">>> Heartbeat worker/scheduler (batas 180 detik)"
deadline=$((SECONDS + 180))
for component in worker-default worker-media worker-exports scheduler; do
  ok=0
  while [ "$SECONDS" -lt "$deadline" ]; do
    if "${DC[@]}" exec -T "$component" php artisan rt05:heartbeat-check --component "$component" --max-age=300; then
      ok=1
      break
    fi
    sleep 5
  done
  if [ "$ok" != 1 ]; then
    echo "heartbeat $component tidak sehat dalam batas waktu"
    exit 1
  fi
done
"${DC[@]}" ps
HOST=$(grep '^RT05_HOST=' "$ENV_FILE" | cut -d= -f2-)
echo ">>> Selesai. Buka https://$HOST (sertifikat HTTPS bisa butuh 1–2 menit pertama)."
