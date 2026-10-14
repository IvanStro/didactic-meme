#!/usr/bin/env bash
set -euo pipefail
# V2 SUB sell instant reaction fix — deploy + restart signal daemons.
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
HOST="${DEPLOY_HOST:-bot20@bot20.ftp.tools}"
KEY="${DEPLOY_SSH_KEY:-$HOME/.ssh/cursor_bot20}"
REMOTE="${DEPLOY_REMOTE:-/home/bot20/strim.in.ua}"
PHP="${DEPLOY_PHP:-/usr/local/php84/bin/php}"
SSH=(ssh -i "$KEY" -o IdentitiesOnly=yes "$HOST")
SCP=(scp -i "$KEY" -o IdentitiesOnly=yes)

FILES=(
  engine/engine_logic.php
  engine/signal/eval_strategy.php
  engine_v2/signal_queue.php
  engine_v2/scaling_schedule.php
)

for f in "${FILES[@]}"; do
  echo "upload $f"
  "${SCP[@]}" "$ROOT/$f" "$HOST:$REMOTE/$f"
done

echo "restart signal daemons..."
"${SSH[@]}" "cd '$REMOTE' && bash run/restart_v2_signal_daemons.sh"

echo "verify eval (sid=22 SUI)..."
"${SSH[@]}" "cd '$REMOTE' && $PHP -r '
require \"engine_v2/bootstrap.php\";
require \"engine/engine_logic.php\";
require \"engine/signal/eval_strategy.php\";
\$db=db();
\$st=\$db->query(\"SELECT id,user_id,symbol,fba,tp_whole,cb_whole FROM strategies WHERE id=22\")->fetch_assoc();
\$px=1.0492;
\$e=signal_eval_strategy(\$db,22,\$st[\"symbol\"],(int)\$st[\"user_id\"],\$px,(float)\$st[\"tp_whole\"],(float)\$st[\"cb_whole\"],(float)\$st[\"fba\"],null);
echo json_encode(\$e, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;
'"

echo "done"
