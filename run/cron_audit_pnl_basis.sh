#!/usr/bin/env bash
# ADM.TOOLS cron: аудит фейкового MAIN basis/PnL → Telegram адміну.
#   */20 * * * * /bin/bash /home/bot20/strim.in.ua/run/cron_audit_pnl_basis.sh
set -euo pipefail
ROOT="/home/bot20/strim.in.ua"
PHP="/usr/local/php84/bin/php"
LOG="${ROOT}/logs/audit_pnl_basis.log"
mkdir -p "${ROOT}/logs"
exec >>"$LOG" 2>&1
exec "$PHP" "${ROOT}/run/cron_audit_pnl_basis.php"
