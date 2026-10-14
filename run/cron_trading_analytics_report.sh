#!/usr/bin/env bash
# ADM.TOOLS: щонеділі о 12:00 Kyiv
# 0 12 * * 0 /bin/bash /home/bot20/strim.in.ua/run/cron_trading_analytics_report.sh
set -euo pipefail
ROOT="/home/bot20/strim.in.ua"
PHP="/usr/local/php84/bin/php"
LOG="${ROOT}/logs/trading_analytics_report.log"
mkdir -p "${ROOT}/logs"
exec >>"$LOG" 2>&1
exec "$PHP" "${ROOT}/run/cron_trading_analytics_report.php"
