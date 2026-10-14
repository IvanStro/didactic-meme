#!/usr/bin/env bash
# Ротація логів StrIM (безпечно для активних процесів: copytruncate-подібно через truncate після gzip копії).
# Cron (раз на добу): /bin/bash /home/bot20/strim.in.ua/run/rotate_logs.sh
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
LOG_DIR="${ROOT}/logs"
KEEP_DAYS="${LOG_KEEP_DAYS:-14}"
MAX_GZ_DAYS="${LOG_GZ_KEEP_DAYS:-30}"
mkdir -p "${LOG_DIR}/archive"
stamp=$(date +%Y%m%d)
rotated=0

shopt -s nullglob
for f in "${LOG_DIR}"/*.log; do
  base=$(basename "$f")
  # Thin-exec shadow/canary evidence (B1/C 72h+): не truncate — як у cron_rotate_logs.php
  if [[ "$base" == "thin_exec.log" || "$base" == "rotate_logs.log" ]]; then
    continue
  fi
  # порожні / маленькі — skip
  size=$(stat -c%s "$f" 2>/dev/null || stat -f%z "$f" 2>/dev/null || echo 0)
  if [[ "$size" -lt 1048576 ]]; then
    continue
  fi
  arch="${LOG_DIR}/archive/${base%.log}.${stamp}.log"
  cp "$f" "$arch"
  : > "$f"
  gzip -f "$arch" 2>/dev/null || true
  rotated=$((rotated + 1))
done

# старі gzip
find "${LOG_DIR}/archive" -type f -name '*.gz' -mtime "+${MAX_GZ_DAYS}" -delete 2>/dev/null || true
# дуже старі сирі .log у корені logs (якщо хтось лишив)
find "${LOG_DIR}" -maxdepth 1 -type f \( -name '*.log.[0-9]*' -o -name '*.log.bak' \) -mtime "+${KEEP_DAYS}" -delete 2>/dev/null || true

echo "$(date -Iseconds) rotate_logs rotated=${rotated} keep_gz_days=${MAX_GZ_DAYS}"
