#!/usr/bin/env bash
# Один воркер job_queue (legacy). Для кількох: run/start_workers.sh (WORKER_COUNT=8).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
LOGDIR="${ROOT}/logs"
mkdir -p "$LOGDIR"
PIDFILE="${LOGDIR}/worker_pids.txt"
PHP_BIN="${WORKER_PHP_BIN:-/usr/local/php84/bin/php}"
if [[ ! -x "$PHP_BIN" ]]; then
  PHP_BIN="$(command -v php)"
fi

nohup env WORKER_SLOT=1 "$PHP_BIN" "${ROOT}/engine_v2/worker.php" >>"${LOGDIR}/worker.log" 2>&1 &
echo $! >>"$PIDFILE"
# Сумісність зі старим шляхом (не комітити в git)
echo $! >"${ROOT}/run/worker.pid"
echo "started worker pid=$! (tracked in ${PIDFILE})"
