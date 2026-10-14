#!/usr/bin/env bash
# Фаза 7 RoyalQ: ENGINE_MODE=v2 — усі running на signal-exec, V1 poll enqueue off.
# Передумова: hybrid 48h OK, canary угоди, dedupe signal_queue, exec worker alive.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
HOST="${DEPLOY_HOST:-bot20@bot20.ftp.tools}"
REMOTE="${DEPLOY_REMOTE:-/home/bot20/strim.in.ua}"
KEY="${DEPLOY_SSH_KEY:-$HOME/.ssh/cursor_bot20}"
PHP="${DEPLOY_PHP:-/usr/local/php84/bin/php}"

echo "== Phase 7: ENGINE_MODE=v2 on $HOST =="
echo "Rollback: ENGINE_MODE=hybrid + ./run/restart_v2_signal_daemons.sh"
if [[ "${FORCE:-}" != "1" ]]; then
  read -r -p "Continue? [y/N] " ans
  [[ "${ans:-}" =~ ^[yY]$ ]] || { echo "aborted"; exit 1; }
fi

ssh -i "$KEY" -o IdentitiesOnly=yes "$HOST" "set -e
  cd '$REMOTE'
  TS=\$(date +%Y%m%d_%H%M%S)
  BK=/home/bot20/backups/phase7_v2_live_\${TS}
  mkdir -p \"\$BK\"
  cp -a .env \"\$BK/.env.bak\"
  echo backup=\$BK

  if grep -q '^ENGINE_MODE=' .env; then
    sed -i.phase7 's/^ENGINE_MODE=.*/ENGINE_MODE=v2/' .env
  else
    echo 'ENGINE_MODE=v2' >> .env
  fi
  grep '^ENGINE_MODE=' .env

  ./run/restart_v2_signal_daemons.sh
  ./run/restart_trading_ops.sh &
  cronpid=\$!
  sleep 10
  kill -0 \"\$cronpid\" 2>/dev/null && kill \"\$cronpid\" 2>/dev/null || true

  $PHP run/v2_status_check.php
  curl -s https://strim.in.ua/api/health.php | $PHP -r '
    \$j=json_decode(stream_get_contents(STDIN),true);
    echo \"engine_mode=\".(\$j[\"engine_mode\"]??\"?\").\" lag=\".(\$j[\"lag_seconds\"]??\"?\").\" exec_alive=\".((\$j[\"signal_exec\"][\"alive\"]??false)?\"1\":\"0\").PHP_EOL;
  '
"

echo "done — спостерігати 24h: php run/v2_status_check.php && php run/shadow_compare_report.php 24"
