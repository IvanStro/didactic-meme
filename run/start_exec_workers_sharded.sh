#!/usr/bin/env bash
# Запуск N exec_worker (Redis queue). Пропускає вже живі слоти.
# Usage: SIGNAL_EXEC_WORKERS=4 ./run/start_exec_workers_sharded.sh

set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

PHP="${PHP_BIN:-/usr/local/php84/bin/php}"
N="${SIGNAL_EXEC_WORKERS:-4}"
LOG_DIR="${ROOT}/logs"
PID_DIR="${ROOT}/services/signal"
mkdir -p "$LOG_DIR" "$PID_DIR"

if [[ "${STRIM_NODE_ROLE:-control}" == "control" && "${STRIM_EXEC_ON_CONTROL:-1}" != "1" ]]; then
  echo "skip: exec disabled on control"
  exit 0
fi

started=0
for ((i=1; i<=N; i++)); do
  pidf="${PID_DIR}/signal_exec_worker_s${i}.pid"
  if [[ -f "$pidf" ]]; then
    opid=$(tr -d " \n" < "$pidf" || true)
    if [[ -n "$opid" ]] && kill -0 "$opid" 2>/dev/null; then
      continue
    fi
  fi
  export SIGNAL_EXEC_SLOT="$i"
  shard_total="${REDIS_SIGNAL_SHARDS:-16}"
  shards_per=$(( (shard_total + N - 1) / N ))
  start_shard=$(( (i - 1) * shards_per ))
  end_shard=$(( start_shard + shards_per - 1 ))
  if (( end_shard >= shard_total )); then
    end_shard=$(( shard_total - 1 ))
  fi
  shard_list=""
  for ((s = start_shard; s <= end_shard; s++)); do
    shard_list+="${s},"
  done
  export WORKER_SHARD_IDS="${shard_list%,}"
  nohup env WORKER_SHARD_IDS="$WORKER_SHARD_IDS" "$PHP" "$ROOT/engine_v2/exec_worker.php" >> "$LOG_DIR/signal_exec_slot_${i}.log" 2>&1 &
  echo $! > "$pidf"
  echo "  slot $i pid=$(cat "$pidf")"
  started=$((started + 1))
done
echo "exec_workers: target=$N started=$started"
