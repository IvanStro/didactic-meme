#!/usr/bin/env bash
# Redis phase A: backup → rsync → composer predis → .env → verify
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
USER="${DEPLOY_USER:-bot20}"
HOST="${DEPLOY_HOST:-bot20.ftp.tools}"
PORT="${DEPLOY_SSH_PORT:-22}"
ID="${DEPLOY_IDENTITY:-$HOME/.ssh/cursor_bot20}"
REMOTE="${USER}@${HOST}"
REMOTE_ROOT="/home/bot20/strim.in.ua"
TS="$(date +%Y%m%d_%H%M%S)"
BK="backup_redis_phase_a_${TS}"
LOG="$ROOT/run/deploy_logs/2026-05-26_redis_phase_a.md"

SSH=(ssh -p "$PORT" -o IdentitiesOnly=yes -o PubkeyAuthentication=yes -o PasswordAuthentication=no -o ConnectTimeout=25)
[[ -f "$ID" ]] && SSH+=(-i "$ID")
RSYNC_SSH="ssh -p $PORT -o IdentitiesOnly=yes -o PubkeyAuthentication=yes -o PasswordAuthentication=no"
[[ -f "$ID" ]] && RSYNC_SSH="$RSYNC_SSH -i $ID"

echo "==> backup on server"
"${SSH[@]}" "$REMOTE" "set -e
  BK=/home/bot20/backups/${BK}
  mkdir -p \"\$BK\"
  cd ${REMOTE_ROOT}
  for f in www/api/_ws_price.php www/api/health.php engine_v2/signal_queue.php engine_v2/signal_queue_dedupe.php run/cron_ensure_ws_price.php www/composer.json .env; do
    [ -f \"\$f\" ] && cp -a \"\$f\" \"\$BK/\"
  done
  [ -d engine_v3 ] && cp -a engine_v3 \"\$BK/engine_v3_prev\" 2>/dev/null || true
  echo BACKUP_DIR=\$BK
"

echo "==> rsync files"
cd "$ROOT"
rsync -avz -e "$RSYNC_SSH" \
  engine_v2/signal_queue.php \
  engine_v2/signal_queue_dedupe.php \
  "$REMOTE:${REMOTE_ROOT}/engine_v2/"

rsync -avz -e "$RSYNC_SSH" \
  www/api/_ws_price.php \
  www/api/health.php \
  www/composer.json \
  "$REMOTE:${REMOTE_ROOT}/www/api/"

rsync -avz -e "$RSYNC_SSH" www/composer.json "$REMOTE:${REMOTE_ROOT}/www/"

rsync -avz -e "$RSYNC_SSH" \
  run/cron_ensure_ws_price.php \
  run/deploy_redis_phase_a.sh \
  run/verify_redis.php \
  run/redis_queue_smoke.php \
  "$REMOTE:${REMOTE_ROOT}/run/"

rsync -avz -e "$RSYNC_SSH" \
  run/infra/stage3.env.example \
  run/deploy_logs/2026-05-26_redis_phase_a.md \
  "$REMOTE:${REMOTE_ROOT}/run/"

rsync -avz -e "$RSYNC_SSH" engine_v3/ "$REMOTE:${REMOTE_ROOT}/engine_v3/"

echo "==> composer predis"
"${SSH[@]}" "$REMOTE" "cd ${REMOTE_ROOT}/www && (
  if command -v composer >/dev/null 2>&1; then composer require predis/predis:^2.2 --no-interaction
  elif [ -f composer.phar ]; then /usr/local/php84/bin/php composer.phar require predis/predis:^2.2 --no-interaction
  else /usr/local/php84/bin/php -r \"copy('https://getcomposer.org/installer', 'composer-setup.php');\" && /usr/local/php84/bin/php composer-setup.php && /usr/local/php84/bin/php composer.phar require predis/predis:^2.2 --no-interaction
  fi
) 2>&1 | tail -8"

echo "==> .env redis block"
"${SSH[@]}" "$REMOTE" "grep -q '^REDIS_SOCKET=' ${REMOTE_ROOT}/.env 2>/dev/null || cat >> ${REMOTE_ROOT}/.env <<'ENVEOF'

# --- Redis stage3 phase A (2026-05-26) ---
REDIS_SOCKET=/home/bot20/.system/redis.sock
REDIS_PRICE_BUS=1
QUEUE_BACKEND=mysql
REDIS_SIGNAL_SHARDS=16
ENVEOF
"

echo "==> verify"
"${SSH[@]}" "$REMOTE" "cd ${REMOTE_ROOT} && /usr/local/php84/bin/php run/verify_redis.php"
"${SSH[@]}" "$REMOTE" "cd ${REMOTE_ROOT} && /usr/local/php84/bin/php run/cron_ensure_ws_price.php | tail -1"

echo "==> health"
curl -sS "https://strim.in.ua/api/health.php" | head -c 600
echo ""
echo "DONE. Backup: ${BK} — append to ${LOG}"
