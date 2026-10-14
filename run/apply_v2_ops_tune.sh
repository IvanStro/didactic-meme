#!/usr/bin/env bash
# V2 ops tune: 2 reconcile workers, enqueue 30s, purge queues, rotate logs.
set -euo pipefail
HOST="${DEPLOY_HOST:-bot20@bot20.ftp.tools}"
REMOTE="${DEPLOY_REMOTE:-/home/bot20/strim.in.ua}"
KEY="${DEPLOY_SSH_KEY:-$HOME/.ssh/cursor_bot20}"
PHP="${DEPLOY_PHP:-/usr/local/php84/bin/php}"

echo "== apply V2 ops tune =="
ssh -i "$KEY" -o IdentitiesOnly=yes "$HOST" "set -e
  cd '$REMOTE'
  touch .env
  if grep -q '^TRADING_OPS_INTERVAL_SEC=' .env; then
    sed -i.bak_v2ops 's/^TRADING_OPS_INTERVAL_SEC=.*/TRADING_OPS_INTERVAL_SEC=30/' .env
  else
    echo 'TRADING_OPS_INTERVAL_SEC=30' >> .env
  fi
  grep '^TRADING_OPS_INTERVAL_SEC=' .env

  $PHP -r \"
    require 'engine_v2/bootstrap.php';
    require_once 'www/api/_worker_manager.php';
    worker_ensure_paths();
    db()->query(\\\"INSERT INTO app_settings (k,v) VALUES ('worker_target_count','2') ON DUPLICATE KEY UPDATE v='2'\\\");
    \\\$r = worker_restart(2);
    echo 'workers='.count(\\\$r['running']).PHP_EOL;
  \"

  $PHP run/cron_purge_queues.php 14
  $PHP run/cron_rotate_logs.php 5

  pkill -f '/run/trading_ops_daemon.php' 2>/dev/null || true
  sleep 2
  rm -f logs/trading_ops_daemon.pid logs/trading_ops_daemon.lock 2>/dev/null || true
  $PHP run/cron_ensure_trading_ops.php || true
  sleep 4

  $PHP run/v2_status_check.php | head -25
  curl -s https://strim.in.ua/api/health.php | $PHP -r '
    \$j=json_decode(stream_get_contents(STDIN),true);
    echo \"mode=\".(\$j[\"engine_mode\"]??\"?\").\" alive=\".json_encode(\$j[\"engine_alive\"]??null).PHP_EOL;
  '
"

echo "done"
