#!/usr/bin/env bash
# Застосувати правильний ADM Supervisor + прибрати дублікати процесів.
# Usage: bash run/fix_adm_supervisor_prod.sh [--dry-run]
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
PHP="${PHP_BIN:-/usr/local/php84/bin/php}"
DRY=0
[[ "${1:-}" == "--dry-run" ]] && DRY=1

chmod +x \
  run/supervisor_ws_price.sh \
  run/supervisor_exec_worker.sh \
  run/supervisor_signal_daemon.sh \
  run/supervisor_trading_ops.sh \
  run/fix_adm_supervisor_prod.sh \
  run/_pkill_safe.sh \
  2>/dev/null || true

if [[ "$DRY" -eq 1 ]]; then
  "$PHP" run/apply_adm_supervisor.php --dry-run
  exit 0
fi

echo "==> 1) update Supervisor task definitions"
"$PHP" run/apply_adm_supervisor.php --no-restart

echo "==> 2) stop dual-managed / orphan processes (safe pkill)"
bash run/_pkill_safe.sh '/home/bot20/strim\.in\.ua/services/ws/ws_price_cache\.py'
bash run/_pkill_safe.sh '/home/bot20/strim\.in\.ua/engine_v2/exec_worker\.php'
bash run/_pkill_safe.sh '/home/bot20/strim\.in\.ua/engine_v2/signal_daemon\.php'
bash run/_pkill_safe.sh '/home/bot20/strim\.in\.ua/run/trading_ops_daemon\.php'
sleep 2
rm -f \
  services/ws/ws_price_cache.pid \
  services/signal/signal_daemon.pid \
  services/signal/signal_exec_worker.pid \
  services/signal/signal_exec_worker_s1.pid \
  services/signal/signal_exec_worker_s2.pid \
  services/signal/signal_exec_worker_s3.pid \
  services/signal/signal_exec_worker_s4.pid \
  logs/trading_ops_daemon.pid \
  2>/dev/null || true

echo "==> 3) restart all StrIM Supervisor tasks"
"$PHP" run/apply_adm_supervisor.php --restart-only

sleep 8
echo "==> 4) verify counts (expect ws=1 signal=1 exec=4 trading=1)"
# підрахунок через ps, без pgrep у pipe що ловить себе
count_pat() {
  local pat="$1"
  ps -eo args= 2>/dev/null | grep -E "$pat" | grep -v grep | grep -v '_pkill_safe' | grep -v 'fix_adm_supervisor' | wc -l | tr -d ' '
}
echo "ws_price=$(count_pat 'ws_price_cache\.py')"
echo "signal=$(count_pat 'engine_v2/signal_daemon\.php')"
echo "exec=$(count_pat 'engine_v2/exec_worker\.php')"
echo "trading_ops=$(count_pat 'run/trading_ops_daemon\.php')"
echo "--- ps ---"
ps -eo pid=,args= 2>/dev/null | grep -E 'ws_price_cache\.py|signal_daemon\.php|exec_worker\.php|trading_ops_daemon\.php' | grep -v grep | head -25 || true
echo "fix_adm_supervisor_prod done"
