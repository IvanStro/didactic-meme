#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PHP="${PHP_BIN:-/usr/local/php84/bin/php}"
mkdir -p "${ROOT}/logs"
# PID до exec: watchdog бачить процес навіть якщо daemon ще не встиг записати.
echo $$ > "${ROOT}/logs/trading_ops_daemon.pid"
exec "$PHP" "$ROOT/run/trading_ops_daemon.php"
