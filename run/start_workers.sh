#!/usr/bin/env bash
# Запуск кількох незалежних PHP-воркерів (кожен — свій PID, спільна job_queue + FOR UPDATE).
# Використання:
#   chmod +x run/start_workers.sh
#   WORKER_COUNT=8 ./run/start_workers.sh
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
N="${WORKER_COUNT:-4}"
LOGDIR="${ROOT}/logs"
mkdir -p "$LOGDIR"
PIDFILE="${LOGDIR}/worker_pids.txt"
: >"$PIDFILE"
for i in $(seq 1 "$N"); do
  nohup env WORKER_SLOT="$i" php "${ROOT}/engine_v2/worker.php" >>"${LOGDIR}/worker-slot-${i}.log" 2>&1 &
  echo $! >>"$PIDFILE"
  echo "worker slot=$i pid=$!"
done
echo "Started ${N} workers, PIDs in ${PIDFILE}"
