#!/usr/bin/env bash
# Dedupe signal_queue (sid+signal, not price per tick) + restart V2 daemons.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
HOST="${DEPLOY_HOST:-bot20@bot20.ftp.tools}"
REMOTE="${DEPLOY_REMOTE:-/home/bot20/strim.in.ua}"
KEY="${DEPLOY_SSH_KEY:-$HOME/.ssh/cursor_bot20}"
PHP="${DEPLOY_PHP:-/usr/local/php84/bin/php}"

FILES=(
  "./engine_v2/signal_queue.php"
  "./engine_v2/signal_daemon.php"
)

echo "== deploy signal_queue dedupe =="
for f in "${FILES[@]}"; do
  [[ -f "$ROOT/$f" ]] || { echo "missing: $f" >&2; exit 1; }
done

for f in "${FILES[@]}"; do
  dest="$REMOTE/${f#./}"
  ssh -i "$KEY" -o IdentitiesOnly=yes "$HOST" "mkdir -p \"\$(dirname '$dest')\""
  scp -i "$KEY" -o IdentitiesOnly=yes "$ROOT/$f" "$HOST:$dest"
  echo "ok $f"
done

ssh -i "$KEY" -o IdentitiesOnly=yes "$HOST" "set -e
  cd '$REMOTE'
  chmod +x run/restart_v2_signal_daemons.sh 2>/dev/null || true
  ./run/restart_v2_signal_daemons.sh
  $PHP -r \"
    require 'engine_v2/bootstrap.php';
    require 'engine_v2/signal_queue.php';
    echo 'dedupe_key_l10='.signal_queue_dedupe_key(17,'LADDER_L10',0.25).PHP_EOL;
  \"
  curl -s https://strim.in.ua/api/health.php | $PHP -r '
    \$j=json_decode(stream_get_contents(STDIN),true);
    echo \"health ok=\".(\$j[\"ok\"]??\"?\").\" signal_exec=\".json_encode(\$j[\"signal_exec\"]??[]).PHP_EOL;
  '
"

echo "done"
