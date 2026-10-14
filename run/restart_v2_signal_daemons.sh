#!/usr/bin/env bash
# Перезапуск long-running V2 процесів після змін eval_strategy.php / exec_worker.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

pkill -f "engine_v2/signal_daemon.php" 2>/dev/null || true
pkill -f "engine_v2/exec_worker.php" 2>/dev/null || true
sleep 2
rm -f services/signal/signal_daemon.pid services/signal/signal_exec_worker*.pid

bash run/start_signal_daemon.sh
N="${SIGNAL_EXEC_WORKERS:-4}"
if [[ "$N" -gt 1 ]]; then
  export SIGNAL_EXEC_WORKERS="$N"
  bash run/start_exec_workers_sharded.sh
else
  bash run/start_signal_exec_worker.sh
fi

echo "ok: signal_daemon + exec_workers($N) restarted"
