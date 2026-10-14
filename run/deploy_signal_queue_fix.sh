#!/usr/bin/env bash
# Signal queue fix: dual + mysql pop, Redis consumer fixes, orphan dedupe reap.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ID="${DEPLOY_IDENTITY:-$HOME/.ssh/cursor_bot20}"
REMOTE="${DEPLOY_USER:-bot20}@${DEPLOY_HOST:-bot20.ftp.tools}"
RR="/home/bot20/strim.in.ua"
TS="$(date +%Y%m%d_%H%M%S)"
BK="backup_signal_queue_fix_${TS}"
SSH=(ssh -o IdentitiesOnly=yes -o ConnectTimeout=25)
if [[ -f "$ID" ]]; then
  SSH+=(-i "$ID")
fi
RSYNC_SSH="ssh -o IdentitiesOnly=yes"
if [[ -f "$ID" ]]; then
  RSYNC_SSH="$RSYNC_SSH -i $ID"
fi

echo "[deploy] backup ${BK}"
"${SSH[@]}" "$REMOTE" "mkdir -p /home/bot20/backups/${BK}; cd ${RR}; cp -a .env engine_v2/signal_queue.php engine_v2/exec_worker.php engine_v3/ /home/bot20/backups/${BK}/; echo OK"

cd "$ROOT"
rsync -avz -e "$RSYNC_SSH" \
  engine_v2/signal_queue.php engine_v2/exec_worker.php \
  "${REMOTE}:${RR}/engine_v2/"
rsync -avz -e "$RSYNC_SSH" engine_v3/ "${REMOTE}:${RR}/engine_v3/"
rsync -avz -e "$RSYNC_SSH" \
  run/start_exec_workers_sharded.sh \
  run/restart_v2_signal_daemons.sh \
  run/deploy_signal_queue_fix.sh \
  run/reap_signal_dedupe.php \
  "${REMOTE}:${RR}/run/"
rsync -avz -e "$RSYNC_SSH" www/api/health.php "${REMOTE}:${RR}/www/api/"

echo "[deploy] .env dual + mysql pop"
"${SSH[@]}" "$REMOTE" "cd ${RR}; grep -q '^QUEUE_BACKEND=' .env && sed -i 's/^QUEUE_BACKEND=.*/QUEUE_BACKEND=dual/' .env || echo 'QUEUE_BACKEND=dual' >> .env; grep -q '^QUEUE_POP=' .env && sed -i 's/^QUEUE_POP=.*/QUEUE_POP=mysql/' .env || echo 'QUEUE_POP=mysql' >> .env; grep -q '^QUEUE_MYSQL_FALLBACK=' .env || echo 'QUEUE_MYSQL_FALLBACK=1' >> .env; grep -q '^QUEUE_MYSQL_PUSH_SAFETY=' .env || echo 'QUEUE_MYSQL_PUSH_SAFETY=1' >> .env; grep -E 'QUEUE_BACKEND|QUEUE_POP' .env"

echo "[deploy] reap orphan dedupe sid=15 LADDER_L7"
"${SSH[@]}" "$REMOTE" "cd ${RR}; /usr/local/php84/bin/php run/reap_signal_dedupe.php 15 LADDER_L7"

echo "[deploy] restart V2 daemons"
"${SSH[@]}" "$REMOTE" "cd ${RR}; export SIGNAL_EXEC_WORKERS=4; bash run/restart_v2_signal_daemons.sh"

echo "[deploy] verify"
"${SSH[@]}" "$REMOTE" "cd ${RR}; /usr/local/php84/bin/php run/v2_status_check.php | head -20"
curl -sS "https://strim.in.ua/api/health.php" | grep -oE '"queue_backend":"[^"]+"|"queue_pop":"[^"]+"|"workers_alive":[0-9]+' | head -5
echo ""
echo "DONE backup=${BK}"
