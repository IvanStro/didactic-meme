#!/usr/bin/env bash
# М'який перезапуск supervisor: SIGTERM за PID-файлом, потім cron_ensure.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
PHP_BIN="${PHP_BIN:-/usr/local/php84/bin/php}"
if [[ ! -x "$PHP_BIN" ]] && command -v php >/dev/null 2>&1; then
  PHP_BIN="$(command -v php)"
fi
if [[ -f logs/trading_ops_daemon.pid ]]; then
  opid=$(tr -d " \n" < logs/trading_ops_daemon.pid || true)
  if [[ -n "$opid" ]] && kill -0 "$opid" 2>/dev/null; then
    kill -TERM "$opid" 2>/dev/null || true
    sleep 2
    kill -0 "$opid" 2>/dev/null && kill -KILL "$opid" 2>/dev/null || true
  fi
fi
rm -f logs/trading_ops_daemon.pid logs/trading_ops_daemon.lock 2>/dev/null || true
sleep 1
exec "$PHP_BIN" "$ROOT/run/cron_ensure_trading_ops.php"
