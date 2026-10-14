#!/usr/bin/env bash
# Деплой unfreeze burst (варіант A) на prod.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
USER="${DEPLOY_USER:-bot20}"
HOST="${DEPLOY_HOST:-bot20.ftp.tools}"
PORT="${DEPLOY_SSH_PORT:-22}"
ID="${DEPLOY_IDENTITY:-$HOME/.ssh/cursor_bot20}"
REMOTE="${USER}@${HOST}"
SSH=(ssh -p "$PORT" -o IdentitiesOnly=yes -o ConnectTimeout=25 -o ServerAliveInterval=10 -o ServerAliveCountMax=3)
[[ -f "$ID" ]] && SSH+=(-i "$ID")
STAMP="$(date +%Y%m%d_%H%M%S)"
REMOTE_ROOT="/home/bot20/strim.in.ua"
BACKUP_DIR="${REMOTE_ROOT}/backups/unfreeze_burst_${STAMP}"

echo "==> remote backup before unfreeze burst deploy"
"${SSH[@]}" "$REMOTE" "mkdir -p '${BACKUP_DIR}' && for f in engine/engine_logic.php engine/ladder_unfreeze_burst.php engine/spot_funds_freeze.php; do if [ -f '${REMOTE_ROOT}/'\$f ]; then cp -a '${REMOTE_ROOT}/'\$f '${BACKUP_DIR}/'; fi; done && ls -la '${BACKUP_DIR}'"

echo "==> rsync burst files"
cd "$ROOT"
rsync -avz --relative -e "${SSH[*]}" \
  "./engine/engine_logic.php" \
  "./engine/ladder_unfreeze_burst.php" \
  "./engine/spot_funds_freeze.php" \
  "./run/verify_unfreeze_burst_plan.php" \
  "$REMOTE:${REMOTE_ROOT}/"

echo "==> remote: syntax check + burst plan dry-run sid=22"
"${SSH[@]}" "$REMOTE" "cd ${REMOTE_ROOT} && /usr/local/php84/bin/php -l engine/ladder_unfreeze_burst.php && /usr/local/php84/bin/php -l engine/engine_logic.php && /usr/local/php84/bin/php run/verify_unfreeze_burst_plan.php 22"

echo "==> done backup=${BACKUP_DIR}"
