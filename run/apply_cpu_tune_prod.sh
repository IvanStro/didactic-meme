#!/usr/bin/env bash
# Зменшення CPU на bot20: 1 supervisor, 4 workers, enqueue 15s, ротація worker-логів.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
HOST="${DEPLOY_HOST:-bot20@bot20.ftp.tools}"
REMOTE="${DEPLOY_REMOTE:-/home/bot20/strim.in.ua}"
KEY="${DEPLOY_SSH_KEY:-$HOME/.ssh/cursor_bot20}"
PHP="${DEPLOY_PHP:-/usr/local/php84/bin/php}"

echo "== apply CPU tune on $HOST =="
ssh -i "$KEY" -o IdentitiesOnly=yes "$HOST" "set -e
  cd '$REMOTE'
  ENV_FILE='.env'
  touch \"\$ENV_FILE\"
  if grep -q '^TRADING_OPS_INTERVAL_SEC=' \"\$ENV_FILE\"; then
    sed -i.bak_cpu_tune 's/^TRADING_OPS_INTERVAL_SEC=.*/TRADING_OPS_INTERVAL_SEC=15/' \"\$ENV_FILE\"
  else
    echo 'TRADING_OPS_INTERVAL_SEC=15' >> \"\$ENV_FILE\"
  fi
  grep '^TRADING_OPS_INTERVAL_SEC=' \"\$ENV_FILE\" || true

  $PHP -r \"
    require 'engine_v2/bootstrap.php';
    \\\$db = db();
    \\\$db->query(\\\"INSERT INTO app_settings (k,v) VALUES ('worker_target_count','4') ON DUPLICATE KEY UPDATE v='4'\\\");
    echo 'worker_target_count=4'.PHP_EOL;
  \"

  echo '--- trading_ops before ---'
  pgrep -af '/run/trading_ops_daemon.php' 2>/dev/null || true
  pids=\$(pgrep -f '/run/trading_ops_daemon.php' 2>/dev/null || true)
  if [ -n \"\$pids\" ]; then
    for p in \$pids; do
      cmd=\$(ps -p \"\$p\" -o args= 2>/dev/null || true)
      echo \"\$cmd\" | grep -q 'trading_ops_daemon.php' || continue
      kill -TERM \"\$p\" 2>/dev/null || true
    done
    sleep 2
    for p in \$pids; do kill -0 \"\$p\" 2>/dev/null && kill -KILL \"\$p\" 2>/dev/null || true; done
  fi
  rm -f logs/trading_ops_daemon.pid logs/trading_ops_daemon.lock 2>/dev/null || true

  for f in logs/worker-slot-*.log; do
    [ -f \"\$f\" ] || continue
    sz=\$(wc -c < \"\$f\" 2>/dev/null || echo 0)
    if [ \"\$sz\" -gt 5000000 ]; then
      : > \"\$f\"
      echo \"truncated \$f (was \$sz bytes)\"
    fi
  done

  $PHP -r \"
    require 'engine_v2/bootstrap.php';
    require_once 'www/api/_worker_manager.php';
    worker_ensure_paths();
    \\\$r = worker_restart(4);
    echo 'worker_restart stopped='.count(\\\$r['stopped']).' running='.count(\\\$r['running']).PHP_EOL;
  \"
  ./run/restart_trading_ops.sh &
  cronpid=\$!
  sleep 8
  kill -0 \"\$cronpid\" 2>/dev/null && kill \"\$cronpid\" 2>/dev/null || true
  wait \"\$cronpid\" 2>/dev/null || true
  echo '--- trading_ops after ---'
  pgrep -af trading_ops_daemon.php 2>/dev/null || true
  echo '--- workers ---'
  pgrep -af 'engine_v2/worker.php' 2>/dev/null | wc -l
  pgrep -af 'engine_v2/worker.php' 2>/dev/null | head -8 || true

  curl -s https://strim.in.ua/api/health.php | $PHP -r '
    \$j=json_decode(stream_get_contents(STDIN),true);
    echo \"engine_alive=\".(\$j[\"engine_alive\"]??\"?\").\" lag=\".(\$j[\"lag_seconds\"]??\"?\").PHP_EOL;
    echo \"workers=\".json_encode(\$j[\"workers\"]??[]).PHP_EOL;
  '
"

echo "done — перевір ADM.TOOLS CPU завтра; cron_ensure лишається щохвилини (страховка)."
