#!/usr/bin/env bash
# V2 фаза 6: hybrid rollout helpers (ENGINE_MODE=hybrid + V2_HYBRID_EXEC_PCT).
# За замовчуванням на проді лишається shadow + canary 15,17 — зміни .env окремо.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
HOST="${DEPLOY_HOST:-bot20@bot20.ftp.tools}"
REMOTE="${DEPLOY_REMOTE:-/home/bot20/strim.in.ua}"
KEY="${DEPLOY_SSH_KEY:-$HOME/.ssh/cursor_bot20}"
PHP="${DEPLOY_PHP:-/usr/local/php84/bin/php}"

FILES=(
  "./engine_v2/engine_mode.php"
  "./engine_v2/signal_daemon.php"
  "./engine_v2/exec_worker.php"
  "./engine_v2/enqueue_running_strategies.php"
  "./engine/signal/eval_strategy.php"
  "./run/restart_v2_signal_daemons.sh"
  "./engine_v2/scaling_schedule.php"
  "./engine/engine_logic.php"
  "./run/cron_ensure_signal_exec_worker.php"
  "./run/platform_watchdog.php"
  "./run/shadow_compare_report.php"
  "./www/api/health.php"
  "./www/api/strategies_list_v2.php"
  "./run/PHASE6_HYBRID_ROLLOUT.md"
)

echo "== deploy V2 phase 6 (hybrid infra) =="
for f in "${FILES[@]}"; do
  if [[ ! -f "$ROOT/$f" ]]; then
    echo "missing: $f" >&2
    exit 1
  fi
done

for f in "${FILES[@]}"; do
  dest="$REMOTE/${f#./}"
  ssh -i "$KEY" -o IdentitiesOnly=yes "$HOST" "mkdir -p \"\$(dirname '$dest')\""
  scp -i "$KEY" -o IdentitiesOnly=yes "$ROOT/$f" "$HOST:$dest"
  echo "ok $f"
done

ssh -i "$KEY" -o IdentitiesOnly=yes "$HOST" "set -e
  cd '$REMOTE'
  pkill -f engine_v2/worker.php 2>/dev/null || true
  sleep 2
  ./run/restart_trading_ops.sh
  sleep 4
  grep -E '^ENGINE_MODE=|^V2_CANARY_|^V2_HYBRID_' .env 2>/dev/null | tail -8 || true
  $PHP -r \"
    require 'www/vendor/autoload.php';
    Dotenv\\\Dotenv::createImmutable('.')->safeLoad();
    require 'engine_v2/engine_mode.php';
    echo 'mode='.engine_mode().PHP_EOL;
    echo 'signal_exec='.(engine_signal_exec_enabled()?'1':'0').PHP_EOL;
    echo 'hybrid_pct='.engine_hybrid_exec_percent().PHP_EOL;
  \"
  chmod +x run/restart_v2_signal_daemons.sh
  ./run/restart_v2_signal_daemons.sh
  curl -s https://strim.in.ua/api/health.php | $PHP -r '
    \$j=json_decode(stream_get_contents(STDIN),true);
    echo \"health ok=\".(\$j[\"ok\"]??\"?\").\" engine_alive=\".(\$j[\"engine_alive\"]??\"?\").PHP_EOL;
    echo \"engine_mode=\".(\$j[\"engine_mode\"]??\"?\").PHP_EOL;
    echo \"signal_exec=\".json_encode(\$j[\"signal_exec\"]??[]).PHP_EOL;
  '
"

echo "done. See run/PHASE6_HYBRID_ROLLOUT.md for ENGINE_MODE=hybrid steps."
