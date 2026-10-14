#!/usr/bin/env bash
# B1: backup → set shadow SUB_SELL → single restart → light verify (no SSH-retry loops)
set -euo pipefail
cd /home/bot20/strim.in.ua
PHP=/usr/local/php84/bin/php
TS=$(date +%Y%m%d_%H%M%S)
BK=/home/bot20/backups/thin_exec_B1_${TS}
mkdir -p "$BK"
cp -a engine_v2/signal_exec_handler.php engine_v2/thin_exec_config.php "$BK/" 2>/dev/null || true
cp -a engine_v2/thin_exec_shadow.php "$BK/" 2>/dev/null || true
grep -E '^THIN_EXEC' .env > "$BK/env_before.txt" || true
echo "backup_dir=$BK"

python3 <<'PY'
from pathlib import Path
p = Path(".env")
raw = p.read_text()
want = {
    "THIN_EXEC_MODE": "shadow",
    "THIN_EXEC_CANARY_SIDS": "",
    "THIN_EXEC_ACTIONS": "SUB_SELL",
    "THIN_EXEC_FALLBACK_FULL": "1",
}
found = {k: False for k in want}
out = []
for line in raw.splitlines():
    hit = False
    for k, v in want.items():
        if line.startswith(k + "="):
            out.append(f"{k}={v}")
            found[k] = True
            hit = True
            break
    if not hit:
        out.append(line)
for k, v in want.items():
    if not found[k]:
        out.append(f"{k}={v}")
text = "\n".join(out)
if raw.endswith("\n"):
    text += "\n"
p.write_text(text)
print("env_updated")
PY
grep -E '^THIN_EXEC' .env | tee "$BK/env_after.txt"

$PHP -l engine_v2/thin_exec_config.php
$PHP -l engine_v2/thin_exec_shadow.php
$PHP -l engine_v2/signal_exec_handler.php

./run/restart_v2_signal_daemons.sh
sleep 12

$PHP -r '
require "www/vendor/autoload.php";
Dotenv\Dotenv::createImmutable(getcwd())->safeLoad();
require_once "engine_v2/thin_exec_config.php";
echo "mode=".thin_exec_mode().PHP_EOL;
echo "actions=".implode(",", thin_exec_action_prefixes()).PHP_EOL;
echo "cand_sub=". (thin_exec_is_candidate(2,"SUB_SELL_L5")?"1":"0").PHP_EOL;
echo "cand_recon=". (thin_exec_is_candidate(2,"RECONCILE_TICK")?"1":"0").PHP_EOL;
echo "live=". (thin_exec_live_enabled(2,"SUB_SELL_L5")?"1":"0").PHP_EOL;
$db=new mysqli($_ENV["DB_HOST"],$_ENV["DB_USER"],$_ENV["DB_PASS"],$_ENV["DB_NAME"]);
$x=$db->query("SELECT COUNT(*) n, MAX(TIMESTAMPDIFF(SECOND,last_tick_at,NOW())) max_lag FROM strategies WHERE status=\"running\"")->fetch_assoc();
echo "running={$x["n"]} max_lag_s={$x["max_lag"]}\n";
echo "queued=".$db->query("SELECT COUNT(*) c FROM signal_queue WHERE status=\"queued\"")->fetch_assoc()["c"]."\n";
'
echo "==== thin_exec.log ===="
tail -n 15 logs/thin_exec.log 2>/dev/null || echo "(none yet)"
echo "==== exec done sample ===="
tail -n 8 logs/signal_exec_slot_1.log
echo "B1_APPLY_DONE"
