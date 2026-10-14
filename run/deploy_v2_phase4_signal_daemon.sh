#!/usr/bin/env bash
# V2 фаза 4: signal daemon (eval на зміну ціни, без угод).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
USER="${DEPLOY_USER:-bot20}"
HOST="${DEPLOY_HOST:-bot20.ftp.tools}"
PORT="${DEPLOY_SSH_PORT:-22}"
ID="${DEPLOY_IDENTITY:-$HOME/.ssh/cursor_bot20}"
REMOTE="${USER}@${HOST}"
SSH=(ssh -p "$PORT" -o IdentitiesOnly=yes -o ConnectTimeout=25 -i "$ID")

TS=$(date +%Y%m%d_%H%M%S)
LABEL="v2_phase4_signal_daemon_${TS}"

echo "==> 1. backup"
"${SSH[@]}" "$REMOTE" "set -e
  BK=/home/bot20/backups/${LABEL}
  mkdir -p \"\$BK\"
  cd /home/bot20/strim.in.ua
  for f in engine_v2/engine_mode.php www/api/health.php; do
    [ -f \"\$f\" ] && cp -a \"\$f\" \"\$BK/\"
  done
  echo backup_dir=\$BK
"

echo "==> 2. rsync"
cd "$ROOT"
rsync -avz --relative -e "${SSH[*]}" \
  "./engine_v2/signal_daemon.php" \
  "./engine_v2/engine_mode.php" \
  "./run/start_signal_daemon.sh" \
  "./run/cron_ensure_signal_daemon.php" \
  "./www/api/health.php" \
  "./run/cron_admtools.txt" \
  "$REMOTE:/home/bot20/strim.in.ua/"

echo "==> 3. start daemon + verify"
"${SSH[@]}" "$REMOTE" 'set -e
  cd /home/bot20/strim.in.ua
  chmod +x run/start_signal_daemon.sh
  /usr/local/php84/bin/php run/cron_ensure_signal_daemon.php
  sleep 3
  tail -8 logs/signal_daemon.log
  echo "--- health signal_daemon ---"
  curl -sS https://strim.in.ua/api/health.php | /usr/local/php84/bin/php -r "
    \$j=json_decode(stream_get_contents(STDIN),true);
    echo json_encode(\$j[\"signal_daemon\"]??null, JSON_PRETTY_PRINT).PHP_EOL;
    echo \"engine_mode=\".(\$j[\"engine_mode\"]??\"?\").PHP_EOL;
  "
'

echo "==> done backup=backups/${LABEL}"
