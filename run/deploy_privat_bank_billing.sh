#!/usr/bin/env bash
# Privat bank billing poll + auto extend
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
USER="${DEPLOY_USER:-bot20}"
HOST="${DEPLOY_HOST:-bot20.ftp.tools}"
PORT="${DEPLOY_SSH_PORT:-22}"
ID="${DEPLOY_IDENTITY:-$HOME/.ssh/cursor_bot20}"
REMOTE="${USER}@${HOST}"
SSH=(ssh -p "$PORT" -o IdentitiesOnly=yes -o ConnectTimeout=25 -i "$ID")

TS=$(date +%Y%m%d_%H%M%S)
LABEL="privat_bank_billing_${TS}"

echo "==> 1. backup"
"${SSH[@]}" "$REMOTE" "set -e
  BK=/home/bot20/backups/${LABEL}
  mkdir -p \"\$BK\"
  cd /home/bot20/strim.in.ua
  for f in www/api/_whitepay_billing.php .env; do
    [ -f \"\$f\" ] && cp -a \"\$f\" \"\$BK/\"
  done
  echo backup_dir=\$BK
"

echo "==> 2. rsync"
cd "$ROOT"
rsync -avz --relative -e "${SSH[*]}" \
  "./www/api/_billing_extend.php" \
  "./www/api/_privat_acp_client.php" \
  "./www/api/_privat_bank_billing.php" \
  "./www/api/_whitepay_billing.php" \
  "./run/migrations/20260520_create_bank_incoming_payments.sql" \
  "./run/apply_privat_bank_migration.php" \
  "./run/privat_poll_transactions.php" \
  "./run/PRIVAT_BANK_BILLING.md" \
  "./run/cron_admtools.txt" \
  "$REMOTE:/home/bot20/strim.in.ua/"

echo "==> 3. migration + test poll (AUTO_EXTEND should be 0 in .env first)"
"${SSH[@]}" "$REMOTE" 'set -e
  cd /home/bot20/strim.in.ua
  /usr/local/php84/bin/php run/apply_privat_bank_migration.php
  grep -E "^PRIVAT_ACP_" .env 2>/dev/null | sed "s/=.*/=***/" || echo "add PRIVAT_ACP_* to .env (see run/PRIVAT_BANK_BILLING.md)"
  /usr/local/php84/bin/php run/privat_poll_transactions.php 3
'

echo "==> done. Set PRIVAT_ACP_TOKEN, PRIVAT_ACP_CLIENT_ID in .env then re-run poll."
