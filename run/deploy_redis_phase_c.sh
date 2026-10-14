#!/usr/bin/env bash
# Redis phase C: QUEUE_BACKEND=redis + 4 exec workers (без додаткового VPS)
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ID="${DEPLOY_IDENTITY:-$HOME/.ssh/cursor_bot20}"
REMOTE="${DEPLOY_USER:-bot20}@${DEPLOY_HOST:-bot20.ftp.tools}"
RR="/home/bot20/strim.in.ua"
TS="$(date +%Y%m%d_%H%M%S)"
BK="backup_redis_phase_c_${TS}"
SSH=(ssh -o IdentitiesOnly=yes -o ConnectTimeout=25)
[[ -f "$ID" ]] && SSH+=(-i "$ID")
RSYNC_SSH="ssh -o IdentitiesOnly=yes"
[[ -f "$ID" ]] && RSYNC_SSH="$RSYNC_SSH -i $ID"

echo "==> backup $BK"
"${SSH[@]}" "$REMOTE" "mkdir -p /home/bot20/backups/${BK} && cd $RR && cp -a .env engine_v2/exec_worker.php engine_v2/signal_queue.php www/api/_ws_price.php /home/bot20/backups/${BK}/ && echo OK"

cd "$ROOT"
rsync -avz -e "$RSYNC_SSH" \
  engine_v2/exec_worker.php engine_v2/signal_queue.php engine_v2/signal_queue_dedupe.php \
  "$REMOTE:$RR/engine_v2/"
rsync -avz -e "$RSYNC_SSH" www/api/_ws_price.php "$REMOTE:$RR/www/api/"
rsync -avz -e "$RSYNC_SSH" engine_v3/ "$REMOTE:$RR/engine_v3/"
rsync -avz -e "$RSYNC_SSH" \
  run/restart_v2_signal_daemons.sh \
  run/start_exec_workers_sharded.sh \
  run/cron_ensure_signal_exec_workers.php \
  run/redis_queue_compare.php \
  run/v2_status_check.php \
  run/deploy_redis_phase_c.sh \
  run/deploy_logs/2026-05-26_redis_phase_c.md \
  "$REMOTE:$RR/run/"

echo "==> .env"
"${SSH[@]}" "$REMOTE" "cd $RR && (
  grep -q '^QUEUE_BACKEND=' .env && sed -i 's/^QUEUE_BACKEND=.*/QUEUE_BACKEND=redis/' .env || echo 'QUEUE_BACKEND=redis' >> .env
  grep -q '^SIGNAL_EXEC_WORKERS=' .env && sed -i 's/^SIGNAL_EXEC_WORKERS=.*/SIGNAL_EXEC_WORKERS=4/' .env || echo 'SIGNAL_EXEC_WORKERS=4' >> .env
  grep -q '^REDIS_PRICE_BUS=' .env && sed -i 's/^REDIS_PRICE_BUS=.*/REDIS_PRICE_BUS=1/' .env || echo 'REDIS_PRICE_BUS=1' >> .env
  grep QUEUE_BACKEND SIGNAL_EXEC_WORKERS REDIS_PRICE_BUS .env
)"

echo "==> restart V2 + workers"
"${SSH[@]}" "$REMOTE" "cd $RR && export SIGNAL_EXEC_WORKERS=4 && bash run/restart_v2_signal_daemons.sh"

echo "==> verify"
"${SSH[@]}" "$REMOTE" "cd $RR && /usr/local/php84/bin/php run/verify_redis.php && /usr/local/php84/bin/php run/redis_queue_compare.php && /usr/local/php84/bin/php run/v2_status_check.php | head -25"

curl -sS "https://strim.in.ua/api/health.php" | grep -oE '"stage3":\{[^}]+\}|"ok":[^,]+' | head -3
echo ""
echo "DONE backup=$BK"
