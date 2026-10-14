#!/usr/bin/env bash
# C0: deploy thin SUB_SELL live + canary 1 sid (default 9 if env not set).
# Usage on prod: CANARY_SID=9 bash run/apply_thin_exec_C0.sh
set -euo pipefail
cd /home/bot20/strim.in.ua
PHP=/usr/local/php84/bin/php
TS=$(date +%Y%m%d_%H%M%S)
BK=/home/bot20/backups/thin_exec_C0_${TS}
CANARY_SID="${CANARY_SID:-9}"
mkdir -p "$BK"
cp -a engine_v2/signal_exec_handler.php engine_v2/thin_exec_config.php "$BK/" 2>/dev/null || true
cp -a engine_v2/thin_exec_shadow.php "$BK/" 2>/dev/null || true
cp -a engine_v2/thin_exec_sub_sell.php "$BK/" 2>/dev/null || true
grep -E '^THIN_EXEC' .env > "$BK/env_before.txt" || true
echo "backup_dir=$BK canary_sid=$CANARY_SID"

$PHP -l engine_v2/thin_exec_config.php
$PHP -l engine_v2/thin_exec_shadow.php
$PHP -l engine_v2/thin_exec_sub_sell.php
$PHP -l engine_v2/signal_exec_handler.php

python3 - "$CANARY_SID" <<'PY'
import sys
from pathlib import Path
sid = sys.argv[1].strip()
if not sid.isdigit() or int(sid) <= 0:
    raise SystemExit(f"bad CANARY_SID={sid!r}")
p = Path(".env")
raw = p.read_text()
want = {
    "THIN_EXEC_MODE": "canary",
    "THIN_EXEC_CANARY_SIDS": sid,
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

# Hosting supervisor keeps workers alive — pkill only; do not double-start.
pkill -f 'engine_v2/signal_daemon.php' 2>/dev/null || true
pkill -f 'engine_v2/exec_worker.php' 2>/dev/null || true
sleep 6
n=$(pgrep -fc 'engine_v2/exec_worker.php' || true)
if [[ "${n:-0}" -lt 1 ]]; then
  echo "supervisor quiet → restart_v2_signal_daemons"
  ./run/restart_v2_signal_daemons.sh
  sleep 8
fi
echo "workers: daemon=$(pgrep -fc 'engine_v2/signal_daemon.php' || true) exec=$(pgrep -fc 'engine_v2/exec_worker.php' || true)"

$PHP <<'PHP'
require 'www/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(getcwd())->safeLoad();
require_once 'engine_v2/thin_exec_config.php';
echo 'mode=' . thin_exec_mode() . PHP_EOL;
$ids = thin_exec_canary_sids();
echo 'canary=' . implode(',', $ids) . PHP_EOL;
$sid = (int)($ids[0] ?? 0);
echo 'actions=' . implode(',', thin_exec_action_prefixes()) . PHP_EOL;
echo 'fallback=' . (thin_exec_fallback_full() ? '1' : '0') . PHP_EOL;
echo 'cand_canary_sub=' . (thin_exec_is_candidate($sid, 'SUB_SELL_L5') ? '1' : '0') . PHP_EOL;
echo 'live_canary_sub=' . (thin_exec_live_enabled($sid, 'SUB_SELL_L5') ? '1' : '0') . PHP_EOL;
echo 'cand_other_sub=' . (thin_exec_is_candidate(999999, 'SUB_SELL_L5') ? '1' : '0') . PHP_EOL;
echo 'live_other_sub=' . (thin_exec_live_enabled(999999, 'SUB_SELL_L5') ? '1' : '0') . PHP_EOL;
echo 'cand_recon=' . (thin_exec_is_candidate($sid, 'RECONCILE_TICK') ? '1' : '0') . PHP_EOL;
$db = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
$x = $db->query("SELECT COUNT(*) n, MAX(TIMESTAMPDIFF(SECOND,last_tick_at,NOW())) max_lag FROM strategies WHERE status='running'")->fetch_assoc();
echo "running={$x['n']} max_lag_s={$x['max_lag']}\n";
echo 'queued=' . $db->query("SELECT COUNT(*) c FROM signal_queue WHERE status='queued'")->fetch_assoc()['c'] . "\n";
$r = $db->query('SELECT id,user_id,symbol,fba,status FROM strategies WHERE id=' . $sid . ' LIMIT 1');
$row = $r ? $r->fetch_assoc() : null;
echo 'canary_row=' . json_encode($row, JSON_UNESCAPED_UNICODE) . PHP_EOL;
PHP
echo "==== thin_exec.log ===="
tail -n 20 logs/thin_exec.log 2>/dev/null || echo "(none yet)"
echo "==== exec done sample ===="
tail -n 8 logs/signal_exec_slot_1.log 2>/dev/null || true
echo "C0_APPLY_DONE"
