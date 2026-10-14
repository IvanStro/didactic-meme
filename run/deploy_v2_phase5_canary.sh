#!/usr/bin/env bash
# V2 фаза 5: canary exec (test2 sid=15, Lenina128 sid=17).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
USER="${DEPLOY_USER:-bot20}"
HOST="${DEPLOY_HOST:-bot20.ftp.tools}"
PORT="${DEPLOY_SSH_PORT:-22}"
ID="${DEPLOY_IDENTITY:-$HOME/.ssh/cursor_bot20}"
REMOTE="${USER}@${HOST}"
SSH=(ssh -p "$PORT" -o IdentitiesOnly=yes -o ConnectTimeout=25 -i "$ID")

TS=$(date +%Y%m%d_%H%M%S)
LABEL="v2_phase5_canary_${TS}"

echo "==> 1. backup"
"${SSH[@]}" "$REMOTE" "set -e
  BK=/home/bot20/backups/${LABEL}
  mkdir -p \"\$BK\"
  cd /home/bot20/strim.in.ua
  for f in engine_v2/engine_mode.php engine_v2/signal_daemon.php engine_v2/enqueue_running_strategies.php www/api/health.php .env; do
    [ -f \"\$f\" ] && cp -a \"\$f\" \"\$BK/\"
  done
  echo backup_dir=\$BK
"

echo "==> 2. rsync"
cd "$ROOT"
rsync -avz --relative -e "${SSH[*]}" \
  "./engine_v2/engine_mode.php" \
  "./engine_v2/signal_queue.php" \
  "./engine_v2/signal_exec_handler.php" \
  "./engine_v2/exec_worker.php" \
  "./engine_v2/signal_daemon.php" \
  "./engine_v2/enqueue_running_strategies.php" \
  "./run/migrations/20260520_create_signal_queue.sql" \
  "./run/apply_signal_queue_migration.php" \
  "./run/start_signal_exec_worker.sh" \
  "./run/cron_ensure_signal_exec_worker.php" \
  "./www/api/health.php" \
  "./run/cron_admtools.txt" \
  "$REMOTE:/home/bot20/strim.in.ua/"

echo "==> 3. migration + .env canary + workers"
"${SSH[@]}" "$REMOTE" 'set -e
  cd /home/bot20/strim.in.ua
  /usr/local/php84/bin/php run/apply_signal_queue_migration.php

  if ! grep -q "^V2_CANARY_STRATEGY_IDS=" .env 2>/dev/null; then
    echo "V2_CANARY_STRATEGY_IDS=15,17,22" >> .env
  fi
  if ! grep -q "^V2_CANARY_EXEC=" .env 2>/dev/null; then
    echo "V2_CANARY_EXEC=1" >> .env
  fi
  grep -E "^V2_CANARY_|^ENGINE_MODE=" .env | tail -5

  chmod +x run/start_signal_exec_worker.sh
  /usr/local/php84/bin/php run/cron_ensure_signal_daemon.php
  /usr/local/php84/bin/php run/cron_ensure_signal_exec_worker.php
  sleep 2

  echo "--- signal_queue counts ---"
  /usr/local/php84/bin/php -r "
    require \"engine_v2/bootstrap.php\";
    require \"engine_v2/signal_queue.php\";
    print_r(signal_queue_counts());
  "

  echo "--- logs ---"
  tail -5 logs/signal_daemon.log
  tail -5 logs/signal_exec_worker.log

  echo "--- health ---"
  curl -sS https://strim.in.ua/api/health.php | /usr/local/php84/bin/php -r "
    \$j=json_decode(stream_get_contents(STDIN),true);
    foreach ([\"engine_mode\",\"signal_daemon\",\"signal_exec\",\"signal_queue\",\"v2_canary\"] as \$k) {
      if (isset(\$j[\$k])) echo \$k.\"=\".json_encode(\$j[\$k], JSON_UNESCAPED_UNICODE).PHP_EOL;
    }
  "
'

echo "==> done backup=backups/${LABEL}"
echo "ADM.TOOLS: додайте cron_ensure_signal_exec_worker (див. run/cron_admtools.txt)"
