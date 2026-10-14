#!/usr/bin/env bash
# ADM.TOOLS cron: щогодинна перевірка Thin Exec C0/C4 → Telegram.
# У панелі:
#   12 * * * * /bin/bash /home/bot20/strim.in.ua/run/cron_thin_exec_c4_check.sh
set -euo pipefail
ROOT="/home/bot20/strim.in.ua"
PHP="/usr/local/php84/bin/php"
LOG="${ROOT}/logs/thin_exec_c4_check.log"
mkdir -p "${ROOT}/logs"
exec >>"$LOG" 2>&1
exec "$PHP" "${ROOT}/run/cron_thin_exec_c4_check.php"
