#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PHP="${PHP_BIN:-/usr/local/php84/bin/php}"
PID_DIR="${ROOT}/services/signal"
mkdir -p "$PID_DIR" "${ROOT}/logs"
echo $$ > "${PID_DIR}/signal_daemon.pid"
exec "$PHP" "$ROOT/engine_v2/signal_daemon.php"
