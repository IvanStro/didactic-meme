#!/usr/bin/env bash
# ADM.TOOLS cron: щогодинний аудит сайту → Telegram.
# У панелі cron:
#   5 * * * * /bin/bash /home/bot20/strim.in.ua/run/cron_site_audit.sh
set -euo pipefail
ROOT="/home/bot20/strim.in.ua"
PHP="/usr/local/php84/bin/php"
LOG="${ROOT}/logs/site_audit.log"
mkdir -p "${ROOT}/logs"
exec >>"$LOG" 2>&1
exec "$PHP" "${ROOT}/run/cron_site_audit.php"
