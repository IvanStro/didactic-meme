#!/usr/bin/env bash
# Деплой підтвердження email при реєстрації.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
USER="${DEPLOY_USER:-bot20}"
HOST="${DEPLOY_HOST:-bot20.ftp.tools}"
PORT="${DEPLOY_SSH_PORT:-22}"
ID="${DEPLOY_IDENTITY:-$HOME/.ssh/cursor_bot20}"
REMOTE="${USER}@${HOST}"
SSH=(ssh -p "$PORT" -o IdentitiesOnly=yes -o ConnectTimeout=25)
[[ -f "$ID" ]] && SSH+=(-i "$ID")
REMOTE_ROOT="/home/bot20/strim.in.ua"
STAMP="$(date +%Y%m%d_%H%M%S)"
BACKUP="${REMOTE_ROOT}/backups/register_email_verify_${STAMP}"

echo "==> backup"
"${SSH[@]}" "$REMOTE" "mkdir -p '${BACKUP}' && for f in www/auth/register.php www/auth/do_register.php; do cp -a '${REMOTE_ROOT}/'\$f '${BACKUP}/' 2>/dev/null || true; done && ls -la '${BACKUP}'"

echo "==> rsync"
cd "$ROOT"
rsync -avz --relative -e "${SSH[*]}" \
  "./www/api/_email_verify.php" \
  "./www/auth/send_register_code.php" \
  "./www/auth/register.php" \
  "./www/auth/do_register.php" \
  "./run/apply_signup_email_verify_migration.php" \
  "./run/migrations/20260613_signup_email_verification.sql" \
  "$REMOTE:${REMOTE_ROOT}/"

echo "==> migration + syntax"
"${SSH[@]}" "$REMOTE" "cd ${REMOTE_ROOT} && /usr/local/php84/bin/php run/apply_signup_email_verify_migration.php && /usr/local/php84/bin/php -l www/api/_email_verify.php && /usr/local/php84/bin/php -l www/auth/send_register_code.php && /usr/local/php84/bin/php -l www/auth/do_register.php"

echo "==> done backup=${BACKUP}"
