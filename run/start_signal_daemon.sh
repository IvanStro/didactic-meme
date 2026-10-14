#!/usr/bin/env bash
# Запуск V2 signal daemon (eval на зміну ціни, без угод).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SIG_DIR="${ROOT}/services/signal"
PID_FILE="${SIG_DIR}/signal_daemon.pid"
LOG="${ROOT}/logs/signal_daemon.log"
PHP_BIN="${PHP_BIN:-/usr/local/php84/bin/php}"
SCRIPT="${ROOT}/engine_v2/signal_daemon.php"

mkdir -p "${ROOT}/logs" "${ROOT}/logs/cache" "${SIG_DIR}"

if [[ -f "$PID_FILE" ]]; then
  opid=$(tr -d " \n" < "$PID_FILE" || true)
  if [[ -n "$opid" ]] && kill -0 "$opid" 2>/dev/null; then
    echo "signal_daemon already running pid=$opid"
    exit 0
  fi
fi

if [[ ! -x "$PHP_BIN" ]]; then
  PHP_BIN="$(command -v php 2>/dev/null || true)"
fi
if [[ -z "$PHP_BIN" || ! -f "$SCRIPT" ]]; then
  echo "php or script not found" >&2
  exit 1
fi

nohup "$PHP_BIN" "$SCRIPT" >> "$LOG" 2>&1 </dev/null &
echo $! > "$PID_FILE"
echo "started signal_daemon pid=$(cat "$PID_FILE")"
