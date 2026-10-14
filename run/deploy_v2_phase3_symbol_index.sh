#!/usr/bin/env bash
# Фаза 3 V2: symbol index (read-only cache, торгівля без змін).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
USER="${DEPLOY_USER:-bot20}"
HOST="${DEPLOY_HOST:-bot20.ftp.tools}"
PORT="${DEPLOY_SSH_PORT:-22}"
ID="${DEPLOY_IDENTITY:-$HOME/.ssh/cursor_bot20}"
REMOTE="${USER}@${HOST}"
SSH=(ssh -p "$PORT" -o IdentitiesOnly=yes -o ConnectTimeout=25 -i "$ID")
[[ -f "$ID" ]] && SSH+=(-i "$ID")

TS=$(date +%Y%m%d_%H%M%S)
LABEL="v2_phase3_symbol_index_${TS}"

echo "==> 1. backup on server"
"${SSH[@]}" "$REMOTE" "set -e
  BK=/home/bot20/backups/${LABEL}
  mkdir -p \"\$BK\"
  cd /home/bot20/strim.in.ua
  for f in www/api/strategy_start.php www/api/strategy_pause.php www/api/strategy_set.php www/api/set_strategy_symbol.php www/api/health.php; do
    [ -f \"\$f\" ] && cp -a \"\$f\" \"\$BK/\"
  done
  echo backup_dir=\$BK
"

echo "==> 2. rsync phase 3 files"
cd "$ROOT"
rsync -avz --relative -e "${SSH[*]}" \
  "./engine/signal/symbol_index.php" \
  "./run/rebuild_symbol_index.php" \
  "./run/cron_rebuild_symbol_index.php" \
  "./www/api/strategy_start.php" \
  "./www/api/strategy_pause.php" \
  "./www/api/strategy_set.php" \
  "./www/api/set_strategy_symbol.php" \
  "./www/api/health.php" \
  "$REMOTE:/home/bot20/strim.in.ua/"

echo "==> 3. rebuild index + health verify"
"${SSH[@]}" "$REMOTE" 'cd /home/bot20/strim.in.ua && /usr/local/php84/bin/php run/rebuild_symbol_index.php && echo "--- health ---" && curl -sS https://strim.in.ua/api/health.php | /usr/local/php84/bin/php -r "echo json_encode(json_decode(stream_get_contents(STDIN),true)[\"symbol_index\"]??\"missing\", JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;"'

echo "==> done (backup: backups/${LABEL})"
