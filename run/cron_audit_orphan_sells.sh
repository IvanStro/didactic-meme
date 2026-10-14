#!/usr/bin/env bash
# ADM.TOOLS cron: cwd = /home/bot20, тому ../logs/ у PHP-команді ламається (ERROR #1).
# У панелі вставляйте лише цей шлях (абсолютний log усередині):
#   /bin/bash /home/bot20/strim.in.ua/run/cron_audit_orphan_sells.sh
set -euo pipefail
ROOT="/home/bot20/strim.in.ua"
PHP="/usr/local/php84/bin/php"
LOG="${ROOT}/logs/audit_orphan_sells.log"
mkdir -p "${ROOT}/logs"
exec >>"$LOG" 2>&1
exec "$PHP" "${ROOT}/run/cron_audit_orphan_sells.php"
