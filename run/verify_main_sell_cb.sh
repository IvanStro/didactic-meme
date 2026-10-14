#!/usr/bin/env bash
set -euo pipefail

# Usage:
#   bash run/verify_main_sell_cb.sh ADAUSDT
# Optional env:
#   DB_HOST DB_USER DB_PASS DB_NAME
#   LOG_FILE (default: logs/worker.log)

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
if [[ -f "$ROOT/.env" ]]; then
  while IFS= read -r line || [[ -n "$line" ]]; do
    line="${line%$'\r'}"
    [[ -z "$line" || "$line" =~ ^[[:space:]]*# ]] && continue
    if [[ "$line" =~ ^(DB_HOST|DB_USER|DB_PASS|DB_NAME)=(.*)$ ]]; then
      export "${BASH_REMATCH[1]}=${BASH_REMATCH[2]}"
    fi
  done < "$ROOT/.env"
fi

SYMBOL="${1:-ADAUSDT}"
LOG_FILE="${LOG_FILE:-$ROOT/logs/worker.log}"

echo "== Verify MAIN SELL/CB for ${SYMBOL} =="
echo "Time: $(date '+%Y-%m-%d %H:%M:%S %Z')"
echo

if [[ ! -f "$LOG_FILE" ]]; then
  echo "[WARN] Log file not found: $LOG_FILE"
  for alt in "$ROOT"/logs/worker-slot-*.log "$ROOT"/logs/worker.log; do
    [[ -f "$alt" ]] || continue
    LOG_FILE="$alt"
    echo "[INFO] Using log: $LOG_FILE"
    break
  done
fi

if [[ ! -f "$LOG_FILE" ]]; then
  echo "[WARN] No worker logs found under logs/"
else
  echo "-- Recent MAIN SELL log lines (${SYMBOL}) --"
  grep -E "${SYMBOL}.*MAIN SELL|MAIN SELL.*${SYMBOL}" "$LOG_FILE" 2>/dev/null | tail -n 80 || true
  echo
fi

if ! command -v mysql >/dev/null 2>&1; then
  echo "[WARN] mysql client not found; skipping SQL checks."
  exit 0
fi

if [[ -z "${DB_HOST:-}" || -z "${DB_USER:-}" || -z "${DB_NAME:-}" ]]; then
  echo "[WARN] DB env is not set (DB_HOST/DB_USER/DB_NAME)."
  echo "       Export DB_* and rerun for SQL checks."
  exit 0
fi

MYSQL_CMD=(mysql -h "${DB_HOST}" -u "${DB_USER}")
if [[ -n "${DB_PASS:-}" ]]; then
  MYSQL_CMD+=(-p"${DB_PASS}")
fi
MYSQL_CMD+=("${DB_NAME}" -N -e)

echo "-- Strategy + position snapshot --"
"${MYSQL_CMD[@]}" "
SELECT
  s.id,
  s.owner_id,
  s.status,
  s.symbol,
  s.tp_whole,
  s.cb_whole,
  p.id AS position_id,
  p.state,
  p.qty,
  p.avg_price,
  p.last_buy,
  p.last_peak,
  p.next_ptr,
  p.updated_at
FROM strategies s
LEFT JOIN positions p ON p.strategy_id=s.id AND p.user_id=s.owner_id
WHERE s.symbol='${SYMBOL}'
ORDER BY s.id DESC
LIMIT 5;
"
echo

echo "-- Open sub-levels summary (should not be blocked by dust) --"
"${MYSQL_CMD[@]}" "
SELECT
  s.id AS strategy_id,
  sp.level_idx,
  COUNT(*) AS cnt,
  ROUND(SUM(sp.qty), 10) AS sum_qty
FROM strategies s
JOIN positions p ON p.strategy_id=s.id AND p.user_id=s.owner_id
JOIN sub_positions sp ON sp.position_id=p.id
WHERE s.symbol='${SYMBOL}' AND sp.closed=0
GROUP BY s.id, sp.level_idx
ORDER BY s.id DESC, sp.level_idx ASC;
"
echo

echo "-- Recent SELL orders --"
"${MYSQL_CMD[@]}" "
SELECT
  o.id,
  o.user_id,
  o.strategy_id,
  o.symbol,
  o.side,
  o.status,
  o.executed_qty,
  o.price,
  FROM_UNIXTIME(o.ts/1000) AS ts_utc
FROM orders o
WHERE o.symbol='${SYMBOL}' AND o.side='SELL'
ORDER BY o.id DESC
LIMIT 20;
"
echo

echo "Done."
