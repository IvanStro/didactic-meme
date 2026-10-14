#!/usr/bin/env bash
# A2 apply on prod: backup → ensure THIN_EXEC_MODE=off → restart → smoke
set -euo pipefail
cd /home/bot20/strim.in.ua
PHP=/usr/local/php84/bin/php
TS=$(date +%Y%m%d_%H%M%S)
BK=/home/bot20/backups/thin_exec_A2_${TS}
mkdir -p "$BK"
cp -a engine_v2/signal_exec_handler.php "$BK/" 2>/dev/null || true
cp -a engine_v2/thin_exec_config.php "$BK/" 2>/dev/null || true
grep -E '^(ENGINE_MODE|QUEUE_|SIGNAL_EXEC|THIN_EXEC)' .env > "$BK/env_before.txt" || true
echo "backup_dir=$BK"

# Ensure THIN_EXEC flags in .env (off)
python3 <<'PY'
from pathlib import Path
p = Path(".env")
raw = p.read_text()
lines = raw.splitlines()
keys = {
    "THIN_EXEC_MODE": "off",
    "THIN_EXEC_CANARY_SIDS": "",
    "THIN_EXEC_ACTIONS": "",
    "THIN_EXEC_FALLBACK_FULL": "1",
}
found = {k: False for k in keys}
out = []
for line in lines:
    hit = False
    for k, v in keys.items():
        if line.startswith(k + "="):
            found[k] = True
            out.append(f"{k}={v}")
            hit = True
            break
    if not hit:
        out.append(line)
for k, v in keys.items():
    if not found[k]:
        out.append(f"{k}={v}")
text = "\n".join(out)
if raw.endswith("\n"):
    text += "\n"
p.write_text(text)
print("env_thin_flags_written")
PY

grep -E '^THIN_EXEC' .env | tee "$BK/env_after_thin.txt"

$PHP -l engine_v2/thin_exec_config.php
$PHP -l engine_v2/signal_exec_handler.php

./run/restart_v2_signal_daemons.sh
sleep 10

$PHP run/v2_status_check.php | head -25
echo "===="
$PHP -r '
require "www/vendor/autoload.php";
Dotenv\Dotenv::createImmutable(getcwd())->safeLoad();
require_once "engine_v2/thin_exec_config.php";
echo "mode=".thin_exec_mode().PHP_EOL;
echo "live_enabled_dummy=". (thin_exec_live_enabled(2,"SUB_SELL_L5")?"1":"0").PHP_EOL;
echo "candidate_dummy=". (thin_exec_is_candidate(2,"SUB_SELL_L5")?"1":"0").PHP_EOL;
'
echo "===="
# wait for at least one thin_exec log line or show empty
sleep 5
if [[ -f logs/thin_exec.log ]]; then
  echo "thin_exec.log tail:"
  tail -n 8 logs/thin_exec.log
else
  echo "thin_exec.log: not yet (ok if no job in 5s)"
fi
echo "===="
tail -n 5 logs/signal_exec_slot_1.log
$PHP -r '
require "www/vendor/autoload.php";
Dotenv\Dotenv::createImmutable(getcwd())->safeLoad();
$db=new mysqli($_ENV["DB_HOST"],$_ENV["DB_USER"],$_ENV["DB_PASS"],$_ENV["DB_NAME"]);
$r=$db->query("SELECT COUNT(*) n, MAX(TIMESTAMPDIFF(SECOND,last_tick_at,NOW())) max_lag FROM strategies WHERE status=\"running\"");
$x=$r->fetch_assoc();
echo "running={$x["n"]} max_lag_s={$x["max_lag"]}\n";
'
echo "A2_APPLY_DONE"
