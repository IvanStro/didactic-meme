#!/usr/bin/env bash
# Деплой trading ops (supervisor + worker manager) на bot20.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
USER="${DEPLOY_USER:-bot20}"
HOST="${DEPLOY_HOST:-bot20.ftp.tools}"
PORT="${DEPLOY_SSH_PORT:-22}"
ID="${DEPLOY_IDENTITY:-$HOME/.ssh/cursor_bot20}"
REMOTE="${USER}@${HOST}"
SSH=(ssh -p "$PORT" -o IdentitiesOnly=yes -o ConnectTimeout=25 -o ServerAliveInterval=10 -o ServerAliveCountMax=3)
[[ -f "$ID" ]] && SSH+=(-i "$ID")

echo "==> rsync trading ops + worker manager"
cd "$ROOT"
rsync -avz --relative -e "${SSH[*]}" \
  "./run/trading_ops_daemon.php" \
  "./run/cron_ensure_trading_ops.php" \
  "./run/platform_watchdog.php" \
  "./run/start_trading_ops_daemon.sh" \
  "./run/restart_trading_ops.sh" \
  "./run/worker_auto_tick.php" \
  "./engine_v2/enqueue_daemon.php" \
  "./engine_v2/enqueue_running_strategies.php" \
  "./engine_v2/job_handler.php" \
  "./engine_v2/scaling_schedule.php" \
  "./engine_v2/scaling_binance_cache.php" \
  "./engine_v2/scaling_tick_mode.php" \
  "./engine/engine_logic.php" \
  "./engine/engine_helpers.php" \
  "./engine/signal/eval_strategy.php" \
  "./run/fix_duplicate_positions.php" \
  "./run/repair_orphan_qty_to_ladder.php" \
  "./run/cron_purge_queues.php" \
  "./run/cron_rotate_logs.php" \
  "./run/reap_signal_queue_stale.php" \
  "./run/v2_status_check.php" \
  "./www/api/_worker_manager.php" \
  "$REMOTE:/home/bot20/strim.in.ua/"

echo "==> remote: chmod + cron_ensure (повний перезапуск: run/restart_trading_ops.sh на сервері)"
"${SSH[@]}" "$REMOTE" 'cd /home/bot20/strim.in.ua && chmod +x run/start_trading_ops_daemon.sh run/restart_trading_ops.sh 2>/dev/null || true && /usr/local/php84/bin/php run/cron_ensure_trading_ops.php || true && echo "--- supervisor pid ---" && (test -f logs/trading_ops_daemon.pid && op=$(tr -d " \n" < logs/trading_ops_daemon.pid) && ps -p "$op" -o pid=,etimes=,args= 2>/dev/null || echo "no supervisor") && echo "--- workers ---" && pgrep -af "/home/bot20/strim\.in\.ua/engine_v2/worker\.php" 2>/dev/null | head -20 || true && echo deploy_remote_ok'

echo "==> done"
echo "Cron ADM.TOOLS (без cd — див. run/cron_admtools.txt):"
echo "  /usr/local/php84/bin/php /home/bot20/strim.in.ua/run/cron_ensure_trading_ops.php >> /home/bot20/strim.in.ua/logs/cron_ensure.log 2>&1"
