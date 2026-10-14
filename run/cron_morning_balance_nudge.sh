#!/usr/bin/env bash
# ADM.TOOLS cron (Kyiv / EEST): щодня о 09:00
#   0 9 * * * /bin/bash /home/bot20/strim.in.ua/run/cron_morning_balance_nudge.sh
set -euo pipefail
ROOT="/home/bot20/strim.in.ua"
PHP="/usr/local/php84/bin/php"
LOG="${ROOT}/logs/morning_balance_nudge.log"
mkdir -p "${ROOT}/logs"
exec >>"$LOG" 2>&1
exec "$PHP" "${ROOT}/run/cron_morning_balance_nudge.php"
