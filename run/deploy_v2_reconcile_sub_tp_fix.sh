#!/usr/bin/env bash
set -euo pipefail
# Fix V2 reconcile TZ skew + SUB sell fill-at-TP + watchdog tick lag for signal-exec.
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
HOST="${DEPLOY_HOST:-bot20@bot20.ftp.tools}"
KEY="${DEPLOY_SSH_KEY:-$HOME/.ssh/cursor_bot20}"
REMOTE="${DEPLOY_REMOTE:-/home/bot20/strim.in.ua}"
PHP="${DEPLOY_PHP:-/usr/local/php84/bin/php}"
SSH=(ssh -i "$KEY" -o IdentitiesOnly=yes -o ConnectTimeout=25 "$HOST")
SCP=(scp -i "$KEY" -o IdentitiesOnly=yes -o ConnectTimeout=25)

FILES=(
  engine_v2/bootstrap.php
  engine_v2/scaling_schedule.php
  engine/engine_logic.php
  engine/signal/eval_strategy.php
  run/platform_watchdog.php
)

for f in "${FILES[@]}"; do
  echo "upload $f"
  "${SCP[@]}" "$ROOT/$f" "$HOST:$REMOTE/$f"
done

echo "restart trading ops + signal daemons..."
"${SSH[@]}" "cd '$REMOTE' && bash run/restart_v2_signal_daemons.sh && (bash run/restart_trading_ops.sh >/tmp/restart_trading_ops.out 2>&1 & echo trading_ops_restart_bg=\$!) && sleep 3 && tail -n 5 /tmp/restart_trading_ops.out 2>/dev/null || true"

echo "verify timezone + reconcile due..."
"${SSH[@]}" "cd '$REMOTE' && $PHP -r '
require \"engine_v2/bootstrap.php\";
require_once \"engine_v2/scaling_schedule.php\";
require_once \"engine_v2/signal_queue.php\";
require_once \"engine_v2/engine_mode.php\";
\$db = db();
echo \"php_tz=\" . date_default_timezone_get() . \" now=\" . date(\"c\") . PHP_EOL;
\$r = \$db->query(\"SELECT NOW() n, UTC_TIMESTAMP() u\")->fetch_assoc();
echo \"mysql_now={\$r[\"n\"]} utc={\$r[\"u\"]}\" . PHP_EOL;
\$check = \$db->prepare(\"SELECT id FROM job_queue WHERE user_id=? AND strategy_id=? AND symbol=? AND status IN (\\\"queued\\\",\\\"processing\\\") LIMIT 1\");
\$rs = \$db->query(\"SELECT id,user_id,symbol,last_tick_at,next_tick_at,(next_tick_at IS NULL OR next_tick_at<=NOW()) due FROM strategies WHERE status=\\\"running\\\"\");
while (\$row = \$rs->fetch_assoc()) {
  \$sid = (int)\$row[\"id\"];
  echo \"before sid=\$sid due={\$row[\"due\"]} next={\$row[\"next_tick_at\"]} last={\$row[\"last_tick_at\"]}\" . PHP_EOL;
  if (engine_strategy_skip_v1_poll(\$sid)) {
    scaling_maybe_enqueue_signal_exec_reconcile(\$db, (int)\$row[\"user_id\"], \$sid, (string)\$row[\"symbol\"], \$check);
  }
}
\$rs = \$db->query(\"SELECT id,last_tick_at,next_tick_at FROM strategies WHERE status=\\\"running\\\"\");
while (\$row = \$rs->fetch_assoc()) {
  echo \"after sid={\$row[\"id\"]} next={\$row[\"next_tick_at\"]} last={\$row[\"last_tick_at\"]}\" . PHP_EOL;
}
\$q = \$db->query(\"SELECT id,strategy_id,signal_name,status,created_at FROM signal_queue ORDER BY id DESC LIMIT 8\");
while (\$row = \$q->fetch_assoc()) {
  echo json_encode(\$row, JSON_UNESCAPED_UNICODE) . PHP_EOL;
}
'"

echo "done"
