#!/usr/bin/env bash
# V2 фаза 5: exec worker (signal_queue → process_strategy для canary).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SIG_DIR="${ROOT}/services/signal"
PID_FILE="${SIG_DIR}/signal_exec_worker.pid"
LOG="${ROOT}/logs/signal_exec_worker.log"
PHP_BIN="${PHP_BIN:-/usr/local/php84/bin/php}"
SCRIPT="${ROOT}/engine_v2/exec_worker.php"

mkdir -p "${ROOT}/logs" "${SIG_DIR}"

if [[ -f "$PID_FILE" ]]; then
  opid=$(tr -d " \n" < "$PID_FILE" || true)
  if [[ -n "$opid" ]] && kill -0 "$opid" 2>/dev/null; then
    echo "signal_exec_worker already running pid=$opid"
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
echo "started signal_exec_worker pid=$(cat "$PID_FILE")"
