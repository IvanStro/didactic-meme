#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
USER="${DEPLOY_USER:-bot20}"
HOST="${DEPLOY_HOST:-bot20.ftp.tools}"
PORT="${DEPLOY_SSH_PORT:-22}"
ID="${DEPLOY_IDENTITY:-$HOME/.ssh/cursor_bot20}"
REMOTE="${USER}@${HOST}"
SSH=(ssh -p "$PORT" -o IdentitiesOnly=yes -o ConnectTimeout=25 -i "$ID")

echo "==> rsync V2 phase 1"
cd "$ROOT"
rsync -avz --relative -e "${SSH[*]}" \
  "./www/api/_ws_price.php" \
  "./www/api/health.php" \
  "./www/api/price_cache.php" \
  "./services/ws/ws_price_cache.py" \
  "./run/cron_ensure_ws_price.php" \
  "./run/start_ws_price_cache.sh" \
  "./run/cron_singleton.php" \
  "./run/platform_watchdog.php" \
  "./run/cron_ensure_trading_ops.php" \
  "./run/cron_ensure_ws_price.php" \
  "./run/shadow_compare_report.php" \
  "./engine_v2/engine_mode.php" \
  "./engine/signal/eval_strategy.php" \
  "./engine_v2/job_handler.php" \
  "./engine/engine_logic.php" \
  "./www/api/strategies_list_v2.php" \
  "$REMOTE:/home/bot20/strim.in.ua/"

echo "==> rsync dashboard"
rsync -avz -e "${SSH[*]}" \
  "./www/dashboard_saas.html" \
  "$REMOTE:/home/bot20/strim.in.ua/www/"

echo "==> remote start WS daemon"
"${SSH[@]}" "$REMOTE" "cd /home/bot20/strim.in.ua && chmod +x run/start_ws_price_cache.sh && /usr/local/php84/bin/php run/cron_ensure_ws_price.php && sleep 2 && cat services/ws/ws_price_cache.pid"

echo "==> done"
