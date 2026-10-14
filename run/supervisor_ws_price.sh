#!/usr/bin/env bash
# Foreground for ADM Supervisor. Без shell-flock: інакше при рестарті
# новий bash зависає на lock, а Supervisor думає що процес «живий».
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WS_DIR="${ROOT}/services/ws"
PID_FILE="${WS_DIR}/ws_price_cache.pid"
PY="${WS_DIR}/venv/bin/python"
SCRIPT="${WS_DIR}/ws_price_cache.py"

mkdir -p "${ROOT}/logs" "${WS_DIR}" "${ROOT}/www/data"

if [[ ! -x "$PY" ]]; then
  PY="$(command -v python3 2>/dev/null || command -v python 2>/dev/null || true)"
fi
if [[ -z "${PY}" || ! -f "$SCRIPT" ]]; then
  echo "supervisor_ws_price: python or script not found" >&2
  exit 1
fi

cd "${WS_DIR}"
echo $$ > "${PID_FILE}"
exec "${PY}" "${SCRIPT}"
