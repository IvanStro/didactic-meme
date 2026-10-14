#!/usr/bin/env bash
set -euo pipefail
# Phase B cleanup deploy + spot funds freeze + scaling tune.
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
HOST="${DEPLOY_HOST:-bot20@bot20.ftp.tools}"
KEY="${DEPLOY_SSH_KEY:-$HOME/.ssh/cursor_bot20}"
REMOTE="${DEPLOY_REMOTE:-/home/bot20/strim.in.ua}"
PHP="${DEPLOY_PHP:-/usr/local/php84/bin/php}"
SSH=(ssh -i "$KEY" -o IdentitiesOnly=yes "$HOST")
SCP=(scp -i "$KEY" -o IdentitiesOnly=yes)

FILES=(
  engine/spot_funds_freeze.php
  engine/engine_logic.php
  engine/signal/eval_strategy.php
  engine_v2/signal_queue.php
  engine_v2/scaling_schedule.php
  www/api/strategies_list_v2.php
  run/cron_spot_funds_unfreeze.php
  run/cron_purge_queues.php
  run/cron_rotate_logs.php
  run/reap_signal_queue_stale.php
  run/cron_admtools.txt
  docs/V2_CLEANUP.md
)

for f in "${FILES[@]}"; do
  echo "upload $f"
  "${SCP[@]}" "$ROOT/$f" "$HOST:$REMOTE/$f"
done

echo "remote: migration + tune + restart..."
"${SSH[@]}" "set -e
  cd '$REMOTE'
  $PHP run/apply_scaling_stage2_migration.php
  $PHP -r \"
    require 'engine_v2/bootstrap.php';
    \\\$db=db();
    \\\$pairs=[
      ['v2_signal_reconcile_sec','30'],
      ['worker_target_count','2'],
    ];
    foreach (\\\$pairs as \\\$p) {
      \\\$k=\\\$p[0]; \\\$v=\\\$p[1];
      \\\$db->query(\\\"INSERT INTO app_settings (k,v) VALUES ('\\\".\\\$db->real_escape_string(\\\$k).\\\"','\\\".\\\$db->real_escape_string(\\\$v).\\\"') ON DUPLICATE KEY UPDATE v=VALUES(v)\\\");
    }
    echo 'settings ok'.PHP_EOL;
  \"
  $PHP run/cron_purge_queues.php 14
  $PHP run/cron_rotate_logs.php 5
  bash run/restart_v2_signal_daemons.sh
  $PHP run/cron_ensure_ws_price.php 2>&1 | tail -2
  $PHP run/cron_ensure_trading_ops.php 2>&1 | tail -2
  sleep 3
  $PHP run/v2_status_check.php | head -18
"

echo "done — add cron */10: php run/cron_spot_funds_unfreeze.php"
