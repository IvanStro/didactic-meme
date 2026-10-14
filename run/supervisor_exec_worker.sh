#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

SLOT="${1:-}"
if [[ -z "$SLOT" || ! "$SLOT" =~ ^[0-9]+$ ]]; then
  echo "usage: $0 <slot>" >&2
  exit 2
fi

PHP="${PHP_BIN:-/usr/local/php84/bin/php}"
N="${SIGNAL_EXEC_WORKERS:-4}"
PID_DIR="${ROOT}/services/signal"
mkdir -p "$PID_DIR" "${ROOT}/logs"

if [[ "${STRIM_NODE_ROLE:-control}" == "control" && "${STRIM_EXEC_ON_CONTROL:-1}" != "1" ]]; then
  echo "supervisor_exec_worker: skip on control" >&2
  exit 0
fi

export SIGNAL_EXEC_SLOT="$SLOT"
shard_total="${REDIS_SIGNAL_SHARDS:-16}"
shards_per=$(( (shard_total + N - 1) / N ))
start_shard=$(( (SLOT - 1) * shards_per ))
end_shard=$(( start_shard + shards_per - 1 ))
if (( end_shard >= shard_total )); then
  end_shard=$(( shard_total - 1 ))
fi
shard_list=""
for ((s = start_shard; s <= end_shard; s++)); do
  shard_list+="${s},"
done
export WORKER_SHARD_IDS="${shard_list%,}"

echo $$ > "${PID_DIR}/signal_exec_worker_s${SLOT}.pid"
exec env SIGNAL_EXEC_SLOT="$SIGNAL_EXEC_SLOT" WORKER_SHARD_IDS="$WORKER_SHARD_IDS" \
  "$PHP" "$ROOT/engine_v2/exec_worker.php"
