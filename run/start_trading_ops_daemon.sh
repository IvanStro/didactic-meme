#!/usr/bin/env bash
# Запуск єдиного supervisor (enqueue + підтримка воркерів). Синглтон у PHP (flock).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
mkdir -p logs
PHP_BIN="${PHP_BIN:-$(command -v php 2>/dev/null || true)}"
if [[ -z "${PHP_BIN}" ]]; then
  echo "start_trading_ops_daemon: php not in PATH; set PHP_BIN" >&2
  exit 1
fi
export WORKER_PHP_BIN="${WORKER_PHP_BIN:-$PHP_BIN}"
nohup env WORKER_PHP_BIN="$WORKER_PHP_BIN" "$PHP_BIN" "$ROOT/run/trading_ops_daemon.php" \
  >>"$ROOT/logs/trading_ops_daemon.log" 2>&1 &
echo "trading_ops_daemon pid=$! log=$ROOT/logs/trading_ops_daemon.log"
