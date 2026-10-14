#!/usr/bin/env bash
# ADM.TOOLS: щодня о 08:00 Kyiv
# 0 8 * * * /bin/bash /home/bot20/strim.in.ua/run/cron_trading_analytics_daily.sh
set -euo pipefail
ROOT="/home/bot20/strim.in.ua"
PHP="/usr/local/php84/bin/php"
LOG="${ROOT}/logs/trading_analytics_daily.log"
mkdir -p "${ROOT}/logs"
exec >>"$LOG" 2>&1
exec "$PHP" "${ROOT}/run/cron_trading_analytics_daily.php"
