#!/usr/bin/env bash
# Redis phase B: dual queue (push MySQL+Redis, pop MySQL) + price read from Redis
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ID="${DEPLOY_IDENTITY:-$HOME/.ssh/cursor_bot20}"
REMOTE="${DEPLOY_USER:-bot20}@${DEPLOY_HOST:-bot20.ftp.tools}"
REMOTE_ROOT="/home/bot20/strim.in.ua"
TS="$(date +%Y%m%d_%H%M%S)"
BK="backup_redis_phase_b_${TS}"

SSH=(ssh -o IdentitiesOnly=yes -o ConnectTimeout=25)
[[ -f "$ID" ]] && SSH+=(-i "$ID")
RSYNC_SSH="ssh -o IdentitiesOnly=yes"
[[ -f "$ID" ]] && RSYNC_SSH="$RSYNC_SSH -i $ID"

echo "==> backup"
"${SSH[@]}" "$REMOTE" "mkdir -p /home/bot20/backups/${BK} && cd ${REMOTE_ROOT} && cp -a .env www/api/_ws_price.php engine_v2/signal_queue.php /home/bot20/backups/${BK}/ && echo BACKUP=${BK}"

echo "==> rsync"
cd "$ROOT"
rsync -avz -e "$RSYNC_SSH" www/api/_ws_price.php "$REMOTE:${REMOTE_ROOT}/www/api/"
rsync -avz -e "$RSYNC_SSH" engine_v2/signal_queue.php engine_v2/signal_queue_dedupe.php "$REMOTE:${REMOTE_ROOT}/engine_v2/"
rsync -avz -e "$RSYNC_SSH" engine_v3/ "$REMOTE:${REMOTE_ROOT}/engine_v3/"
rsync -avz -e "$RSYNC_SSH" run/redis_queue_compare.php run/deploy_redis_phase_b.sh "$REMOTE:${REMOTE_ROOT}/run/"

echo "==> .env QUEUE_BACKEND=dual"
"${SSH[@]}" "$REMOTE" "grep -q '^QUEUE_BACKEND=' ${REMOTE_ROOT}/.env && sed -i 's/^QUEUE_BACKEND=.*/QUEUE_BACKEND=dual/' ${REMOTE_ROOT}/.env || echo 'QUEUE_BACKEND=dual' >> ${REMOTE_ROOT}/.env"

echo "==> compare + health"
"${SSH[@]}" "$REMOTE" "cd ${REMOTE_ROOT} && /usr/local/php84/bin/php run/redis_queue_compare.php"
curl -sS "https://strim.in.ua/api/health.php" | grep -o '"stage3":{[^}]*}' || true
echo ""
echo "DONE backup=${BK} — торгівля через MySQL pop (dual push only)"
