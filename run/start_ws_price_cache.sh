#!/usr/bin/env bash
# Запуск Binance WS price writer (services/ws/ws_price_cache.py).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WS_DIR="${ROOT}/services/ws"
PID_FILE="${WS_DIR}/ws_price_cache.pid"
LOG="${ROOT}/logs/ws_price_cache.log"
PY="${WS_DIR}/venv/bin/python"
SCRIPT="${WS_DIR}/ws_price_cache.py"

mkdir -p "${ROOT}/logs" "${ROOT}/www/data"

DATA_FILE="${ROOT}/www/data/ws_prices.json"
MAX_AGE=60

if [[ -f "$PID_FILE" ]]; then
  opid=$(tr -d " \n" < "$PID_FILE" || true)
  if [[ -n "$opid" ]] && kill -0 "$opid" 2>/dev/null; then
    stale=1
    if [[ -f "$DATA_FILE" ]]; then
      now=$(date +%s)
      mtime=$(stat -c %Y "$DATA_FILE" 2>/dev/null || stat -f %m "$DATA_FILE" 2>/dev/null || echo 0)
      age=$((now - mtime))
      if [[ "$age" -le "$MAX_AGE" ]]; then
        stale=0
      fi
    fi
    if [[ "$stale" -eq 0 ]]; then
      echo "ws_price_cache already running pid=$opid"
      exit 0
    fi
    echo "ws_price_cache stale_data age=${age}s — restarting pid=$opid"
    kill -TERM "$opid" 2>/dev/null || true
    sleep 1
    kill -9 "$opid" 2>/dev/null || true
    rm -f "$PID_FILE"
  fi
fi

if [[ ! -x "$PY" ]]; then
  PY="$(command -v python3 2>/dev/null || command -v python 2>/dev/null || true)"
fi
if [[ -z "$PY" || ! -f "$SCRIPT" ]]; then
  echo "python or script not found" >&2
  exit 1
fi

cd "$WS_DIR"
nohup "$PY" "$SCRIPT" >> "$LOG" 2>&1 </dev/null &
echo $! > "$PID_FILE"
echo "started ws_price_cache pid=$(cat "$PID_FILE")"
