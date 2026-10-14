#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
HOST="${DEPLOY_HOST:-bot20@bot20.ftp.tools}"
KEY="${DEPLOY_SSH_KEY:-$HOME/.ssh/cursor_bot20}"
REMOTE="${DEPLOY_REMOTE:-/home/bot20/strim.in.ua}"
PHP="${DEPLOY_PHP:-/usr/local/php84/bin/php}"
SSH=(ssh -i "$KEY" -o IdentitiesOnly=yes -o ConnectTimeout=90 -o ServerAliveInterval=10 "$HOST")
SCP=(scp -i "$KEY" -o IdentitiesOnly=yes -o ConnectTimeout=90)

FILES=(
  engine/engine_helpers.php
  engine/engine_logic.php
  engine/engine_logic_ladder_cascade.php
  engine/signal/eval_strategy.php
  www/api/future_levels.php
  www/api/strategy_pair_levels.php
  run/repair_ladder_stale_anchor.php
)

echo "=== upload ladder anchor fix ==="
for f in "${FILES[@]}"; do
  echo "  $f"
  "${SCP[@]}" "$ROOT/$f" "$HOST:$REMOTE/$f"
done

echo "=== verify closed=0 anchor markers ==="
"${SSH[@]}" "grep -n 'ladder_open_sub_buy_price\\|closed=0 ORDER BY id DESC LIMIT 1' '$REMOTE/engine/engine_logic.php' | head -5"

echo "=== dry-run repair (all) ==="
"${SSH[@]}" "cd '$REMOTE' && $PHP run/repair_ladder_stale_anchor.php"

echo "=== apply repair Type A (sid=13 ADA test2) ==="
"${SSH[@]}" "cd '$REMOTE' && $PHP run/repair_ladder_stale_anchor.php --apply --sid=13"

echo "=== restart signal/exec workers ==="
"${SSH[@]}" "cd '$REMOTE' && bash run/restart_v2_signal_daemons.sh"

echo "=== post-repair sid=13 ==="
"${SSH[@]}" "cd '$REMOTE' && $PHP -r '
require \"www/vendor/autoload.php\";
Dotenv\\Dotenv::createImmutable(\".\")->safeLoad();
\$db=new mysqli(\$_ENV[\"DB_HOST\"],\$_ENV[\"DB_USER\"],\$_ENV[\"DB_PASS\"],\$_ENV[\"DB_NAME\"]);
\$sid=13;
\$r=\$db->query(\"SELECT qty,sub_assigned_qty,next_ptr,base_price FROM positions WHERE strategy_id=\$sid\");
echo \"pos: \".json_encode(\$r->fetch_assoc()).\"\\n\";
\$r=\$db->query(\"SELECT level_idx,qty,closed FROM sub_positions WHERE position_id=32 AND closed=0\");
while(\$row=\$r->fetch_assoc()) echo \"open sub: \".json_encode(\$row).\"\\n\";
'"

echo "done"
