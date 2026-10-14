#!/usr/bin/env bash
set -euo pipefail
cd /home/bot20/strim.in.ua
PHP=/usr/local/php84/bin/php
date -u
echo "==== LAG ===="
$PHP <<'PHP'
<?php
require "www/vendor/autoload.php";
Dotenv\Dotenv::createImmutable(getcwd())->safeLoad();
$db = new mysqli($_ENV["DB_HOST"], $_ENV["DB_USER"], $_ENV["DB_PASS"], $_ENV["DB_NAME"]);
$db->set_charset("utf8mb4");
$r = $db->query("SELECT id,symbol,user_id,TIMESTAMPDIFF(SECOND,last_tick_at,NOW()) lag_s,last_tick_at,next_tick_at FROM strategies WHERE status='running' ORDER BY lag_s DESC");
while ($x = $r->fetch_assoc()) {
    echo $x["id"]." ".$x["symbol"]." uid=".$x["user_id"]." lag=".$x["lag_s"]."s tick=".$x["last_tick_at"]." next=".($x["next_tick_at"] ?? "").PHP_EOL;
}
echo "queued=".$db->query("SELECT COUNT(*) c FROM signal_queue WHERE status='queued'")->fetch_assoc()["c"].PHP_EOL;
echo "processing=".$db->query("SELECT COUNT(*) c FROM signal_queue WHERE status='processing'")->fetch_assoc()["c"].PHP_EOL;
$stale = $db->query("SELECT id, strategy_id, signal_name, status, locked_at, worker_id, TIMESTAMPDIFF(SECOND,locked_at,NOW()) age FROM signal_queue WHERE status='processing' ORDER BY locked_at ASC LIMIT 20");
echo "==== PROCESSING JOBS ====\n";
while ($stale && ($j = $stale->fetch_assoc())) {
    echo "#{$j['id']} sid={$j['strategy_id']} {$j['signal_name']} age={$j['age']}s worker={$j['worker_id']} locked={$j['locked_at']}\n";
}
PHP
echo "==== PIDS ===="
for f in services/signal/*.pid; do
  pid=$(cat "$f" 2>/dev/null || true)
  if [[ -n "${pid:-}" ]] && kill -0 "$pid" 2>/dev/null; then
    echo "$f=$pid alive=yes"
  else
    echo "$f=${pid:-?} alive=NO"
  fi
done
echo "==== RECENT EXEC ===="
tail -n 25 logs/signal_exec_slot_1.log || true
echo "==== FATAL/FAIL ===="
grep -E "Fatal|failed #|strategy_lock_busy" logs/signal_exec_slot_1.log logs/signal_exec_slot_2.log 2>/dev/null | tail -n 25 || true
echo "==== WD ===="
tail -n 10 logs/platform_watchdog.log || true
echo "==== THIN ===="
tail -n 5 logs/thin_exec.log 2>/dev/null || true
