#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
USER="${DEPLOY_USER:-bot20}"
HOST="${DEPLOY_HOST:-bot20.ftp.tools}"
PORT="${DEPLOY_SSH_PORT:-22}"
ID="${DEPLOY_IDENTITY:-$HOME/.ssh/cursor_bot20}"
REMOTE="${USER}@${HOST}"

SSH=(ssh -p "$PORT" -o IdentitiesOnly=yes)
[[ -f "$ID" ]] && SSH+=(-i "$ID")

echo "==> deploy admin + ops package"
cd "$ROOT"
rsync -avz --relative -e "${SSH[*]}" \
  "./www/admin/users.php" \
  "./www/admin/accounting.php" \
  "./www/admin/admin-panel.css" \
  "./www/api/_accounting.php" \
  "./www/api/_accounting_compliance.php" \
  "./www/api/admin_accounting.php" \
  "./www/api/_billing_extend.php" \
  "./www/api/_csrf.php" \
  "./www/api/_admin_audit.php" \
  "./www/api/admin_billing.php" \
  "./www/api/admin_prelaunch_checks.php" \
  "./www/api/_worker_manager.php" \
  "./www/api/admin_workers.php" \
  "./www/api/admin_withdraw.php" \
  "./www/api/admin_user_details.php" \
  "./www/api/admin_user_topups.php" \
  "./www/api/admin_users_live.php" \
  "./www/api/_admin_realized_pnl.php" \
  "./www/api/admin_user_journal.php" \
  "./www/api/admin_reset_password.php" \
  "./www/api/admin_tg_message.php" \
  "./www/api/_password_reset.php" \
  "./www/api/_mail_send.php" \
  "./www/api/_adm_tools_client.php" \
  "./www/api/admin_hosting_status.php" \
  "./www/api/_api_bootstrap.php" \
  "./www/api/_journal_query.php" \
  "./www/api/journal.php" \
  "./www/api/admin_engine_settings.php" \
  "./run/prelaunch_smoke.sh" \
  "./run/verify_all_public.sh" \
  "./run/verify_main_sell_cb.sh" \
  "./run/reconcile_strategy_readonly.php" \
  "./run/restart_trading_ops.sh" \
  "./run/start_trading_ops_daemon.sh" \
  "./run/sql_referral_audit_readonly.sql" \
  "./run/apply_admin_audit_migration.php" \
  "./run/apply_signup_ref_migration.php" \
  "./run/worker_auto_tick.php" \
  "./run/backfill_referral_rewards.php" \
  "./run/mail_test.php" \
  "./run/adm_dns_audit.php" \
  "./run/adm_tools_ping.php" \
  "./run/admin_binance_reconcile_all.php" \
  "./run/reconcile_binance_strategy.php" \
  "./run/audit_user_strategy.php" \
  "./run/cleanup_template_queue_jobs.php" \
  "./www/api/_tg_notify.php" \
  "./www/api/_journal_query.php" \
  "./www/api/_tg_notify.php" \
  "./run/cron_site_audit.php" \
  "./run/cleanup_journal_site_audit_spam.php" \
  "./run/set_support_tg_webhook.php" \
  "./run/platform_watchdog.php" \
  "./run/cron_ensure_trading_ops.php" \
  "./run/trading_ops_daemon.php" \
  "./www/api/admin_scripts.php" \
  "./www/api/admin_unfreeze_burst_plan.php" \
  "./docs/ACCOUNTING_UA.md" \
  "./run/apply_accounting_compliance_migration.php" \
  "./run/apply_accounting_privat_documents_migration.php" \
  "./run/migrations/20260617_accounting_compliance_settings.sql" \
  "./run/migrations/20260617_accounting_privat_documents.sql" \
  "./www/api/_privat_acp_client.php" \
  "./www/api/_privat_accounting_api.php" \
  "./www/api/_accounting_declaration.php" \
  "./docs/PRIVATBANK_INTEGRATION_UA.md" \
  "./run/dedupe_ledger_duplicate_sells.php" \
  "./engine/engine_logic.php" \
  "./run/verify_user_unfreeze_burst_all.php" \
  "./run/migrations/20260427_create_admin_action_audit.sql" \
  "./run/migrations/20260428_add_users_signup_ref_code_used.sql" \
  "./run/GO_LIVE_48H_CHECKLIST.md" \
  "$REMOTE:/home/bot20/strim.in.ua/"

echo "==> done"
