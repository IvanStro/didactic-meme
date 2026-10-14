#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
HOST="${DEPLOY_HOST:-bot20@bot20.ftp.tools}"
KEY="${DEPLOY_SSH_KEY:-$HOME/.ssh/cursor_bot20}"
REMOTE="${DEPLOY_REMOTE:-/home/bot20/strim.in.ua}"
SSH=(ssh -i "$KEY" -o IdentitiesOnly=yes -o ConnectTimeout=30 "$HOST")
SCP=(scp -i "$KEY" -o IdentitiesOnly=yes -o ConnectTimeout=30)

echo "upload engine/engine_logic.php"
"${SCP[@]}" "$ROOT/engine/engine_logic.php" "$HOST:$REMOTE/engine/engine_logic.php"

echo "restart signal/exec workers (pick up new PHP)..."
"${SSH[@]}" "cd '$REMOTE' && bash run/restart_v2_signal_daemons.sh"

echo "verify marker..."
"${SSH[@]}" "grep -n 'DROP missed (spot above trigger)' '$REMOTE/engine/engine_logic.php' | head -2"

echo "done"
