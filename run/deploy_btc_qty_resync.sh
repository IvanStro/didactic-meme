#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
HOST="${DEPLOY_HOST:-bot20@bot20.ftp.tools}"
KEY="${DEPLOY_SSH_KEY:-$HOME/.ssh/cursor_bot20}"
REMOTE="${DEPLOY_REMOTE:-/home/bot20/strim.in.ua}"
PHP="${DEPLOY_PHP:-/usr/local/php84/bin/php}"
SSH=(ssh -i "$KEY" -o IdentitiesOnly=yes -o ConnectTimeout=30 "$HOST")
SCP=(scp -i "$KEY" -o IdentitiesOnly=yes -o ConnectTimeout=30)

FILES=(
  engine/engine_helpers.php
  engine/engine_logic.php
  www/api/strategies_list_v2.php
)

for f in "${FILES[@]}"; do
  echo "upload $f"
  "${SCP[@]}" "$ROOT/$f" "$HOST:$REMOTE/$f"
done

"${SCP[@]}" "$ROOT/tmp/repair_qty_from_subs.php" "$HOST:/tmp/repair_qty_from_subs.php"
echo "repair..."
"${SSH[@]}" "$PHP /tmp/repair_qty_from_subs.php"
echo "done"
