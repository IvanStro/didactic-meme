#!/usr/bin/env bash
# Deploy SaaS dashboard + strategies API (Binance spot truth) to production.
#
# Non-interactive SSH (macOS): ./run/ssh_prod.sh або ssh-add --apple-use-keychain ~/.ssh/cursor_bot20
#
# Usage:
#   ./run/deploy_www_dashboard.sh
# Override:
#   DEPLOY_HOST=other.host DEPLOY_IDENTITY=~/.ssh/other_key ./run/deploy_www_dashboard.sh

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck source=run/_ssh_prod_common.sh
source "${ROOT}/run/_ssh_prod_common.sh"

USER="${DEPLOY_USER}"
HOST="${DEPLOY_HOST}"
RDIR="${DEPLOY_PATH:-/home/bot20/strim.in.ua/www}"
PORT="${DEPLOY_SSH_PORT}"
ID="${DEPLOY_IDENTITY}"

REMOTE="${USER}@${HOST}"
ssh_prod_add_key
ssh_prod_ssh_base
SSH=("${SSH_BASE[@]}")

rsync_prod() {
  ssh_prod_retry rsync -avz -e "${SSH[*]}" "$@"
}

echo "==> rsync -> ${REMOTE}:${RDIR}/"
rsync_prod \
  "$ROOT/www/assets/payments/privat24-requisites-qr.jpg" \
  "$REMOTE:$RDIR/assets/payments/"

rsync_prod \
  "$ROOT/www/assets/css/strim-public.css" \
  "$ROOT/www/assets/css/strim-blog.css" \
  "$REMOTE:$RDIR/assets/css/"

rsync_prod \
  "$ROOT/www/blog/" \
  "$REMOTE:$RDIR/blog/"

rsync_prod \
  "$ROOT/www/en/" \
  "$REMOTE:$RDIR/en/"

for _loc in pl de es fr; do
  rsync_prod \
    "$ROOT/www/${_loc}/" \
    "$REMOTE:$RDIR/${_loc}/"
done

rsync_prod \
  "$ROOT/www/assets/i18n/locales.json" \
  "$ROOT/www/assets/js/strim-lang-switch.js" \
  "$ROOT/www/assets/js/strim-lang-footer.js" \
  "$REMOTE:$RDIR/assets/js/"
rsync_prod \
  "$ROOT/www/assets/i18n/locales.json" \
  "$REMOTE:$RDIR/assets/i18n/"

rsync_prod \
  "$ROOT/www/assets/js/strim-theme.js" \
  "$ROOT/www/assets/js/strim-public-nav.js" \
  "$ROOT/www/assets/js/strim-lang-footer.js" \
  "$ROOT/www/assets/js/qrcode.min.js" \
  "$REMOTE:$RDIR/assets/js/"

rsync_prod \
  "$ROOT/www/assets/i18n/ru-refuse.jpg" \
  "$REMOTE:$RDIR/assets/i18n/"

rsync_prod \
  "$ROOT/www/favicon.ico" \
  "$ROOT/www/favicon-32.png" \
  "$ROOT/www/favicon-48.png" \
  "$ROOT/www/apple-touch-icon.png" \
  "$ROOT/www/android-chrome-192.png" \
  "$ROOT/www/site.webmanifest" \
  "$ROOT/www/robots.txt" \
  "$ROOT/www/sitemap.xml" \
  "$REMOTE:$RDIR/"

rsync_prod \
  "$ROOT/www/assets/branding/" \
  "$REMOTE:$RDIR/assets/branding/"

rsync_prod \
  "$ROOT/www/assets/i18n/ru-refuse.jpg" \
  "$ROOT/www/assets/i18n/ru-refuse.webp" \
  "$REMOTE:$RDIR/assets/i18n/"

rsync_prod \
  "$ROOT/www/.htaccess" \
  "$ROOT/www/auth/forgot_password.php" \
  "$ROOT/www/auth/do_forgot_password.php" \
  "$ROOT/www/auth/reset_password.php" \
  "$ROOT/www/auth/do_reset_password.php" \
  "$ROOT/www/auth/login.php" \
  "$ROOT/www/dashboard.php" \
  "$ROOT/www/dashboard_saas.html" \
  "$ROOT/www/pair_details.html" \
  "$ROOT/www/about.html" \
  "$ROOT/www/instruction.html" \
  "$ROOT/www/faq.html" \
  "$ROOT/www/offer.html" \
  "$ROOT/www/returns.html" \
  "$ROOT/www/privacy.html" \
  "$ROOT/www/aml.html" \
  "$ROOT/www/risks.html" \
  "$ROOT/www/security.html" \
  "$ROOT/www/tariffs.html" \
  "$ROOT/www/index.html" \
  "$ROOT/www/strategy.html" \
  "$REMOTE:$RDIR/"

rsync_prod \
  "$ROOT/www/auth/router.php" \
  "$ROOT/www/auth/login.php" \
  "$ROOT/www/auth/register.php" \
  "$ROOT/www/auth/do_register.php" \
  "$ROOT/www/auth/api_gate.php" \
  "$ROOT/www/auth/dashboard_gate.php" \
  "$REMOTE:$RDIR/auth/"

rsync_prod \
  "$ROOT/www/api/_binance_utils.php" \
  "$ROOT/www/api/portfolio.php" \
  "$ROOT/www/api/saas_core.php" \
  "$ROOT/www/api/profit_reinvest_set.php" \
  "$ROOT/www/api/profit_calendar.php" \
  "$ROOT/www/api/strategies_list_v2.php" \
  "$ROOT/www/api/_legacy_portfolio.php" \
  "$ROOT/www/api/legacy_scan.php" \
  "$ROOT/www/api/legacy_resolve.php" \
  "$ROOT/www/api/health.php" \
  "$ROOT/www/api/_strategy_levels_ui.php" \
  "$ROOT/www/api/strategy_pair_levels.php" \
  "$ROOT/www/api/get_strategy_db.php" \
  "$ROOT/www/api/future_levels.php" \
  "$ROOT/www/api/position_state.php" \
  "$ROOT/www/api/_filters_utils.php" \
  "$ROOT/www/api/_free_only_shim.php" \
  "$ROOT/www/api/_api_bootstrap.php" \
  "$ROOT/www/api/_journal_query.php" \
  "$ROOT/www/api/_strategy_status.php" \
  "$ROOT/www/api/strategy_set.php" \
  "$ROOT/www/api/strategy_pause.php" \
  "$ROOT/www/api/strategy_start.php" \
  "$ROOT/www/api/strategy_attach.php" \
  "$ROOT/www/api/journal.php" \
  "$ROOT/www/api/strategy_chart_trades.php" \
  "$ROOT/www/api/tg_link_code.php" \
  "$ROOT/www/api/tg_link_status.php" \
  "$ROOT/www/api/_tg_bot_meta.php" \
  "$ROOT/www/api/tg_webhook.php" \
  "$ROOT/www/api/saas_details.php" \
  "$ROOT/www/api/open_levels.php" \
  "$ROOT/www/api/admin_engine_settings.php" \
  "$ROOT/www/api/pair_news.php" \
  "$ROOT/www/api/support_send.php" \
  "$ROOT/www/api/payment_notify.php" \
  "$ROOT/www/api/tg_support_webhook.php" \
  "$ROOT/www/api/exchange_connect.php" \
  "$ROOT/www/api/strim_nbu_pay.php" \
  "$ROOT/www/api/session_public.php" \
  "$ROOT/www/api/me.php" \
  "$ROOT/www/api/_whitepay_config.php" \
  "$ROOT/www/api/_whitepay_dotenv.php" \
  "$ROOT/www/api/_whitepay_billing.php" \
  "$ROOT/www/api/whitepay_create.php" \
  "$ROOT/www/api/whitepay_webhook.php" \
  "$ROOT/www/api/_google_ads_config.php" \
  "$ROOT/www/api/billing_purchase_confirmed.php" \
  "$ROOT/www/api/payment_page.php" \
  "$ROOT/www/api/payment_page_wait.inc.php" \
  "$ROOT/www/api/payment_page_success.inc.php" \
  "$REMOTE:$RDIR/api/"

rsync_prod \
  "$ROOT/www/payment/wait.php" \
  "$ROOT/www/payment/success.php" \
  "$REMOTE:$RDIR/payment/"

rsync_prod \
  "$ROOT/www/assets/js/strim-cookie-consent.js" \
  "$ROOT/www/assets/js/strim-analytics-boot.js" \
  "$ROOT/www/assets/js/strim-public-nav.js" \
  "$REMOTE:$RDIR/assets/js/"
rsync_prod \
  "$ROOT/www/assets/css/strim-cookie-consent.css" \
  "$REMOTE:$RDIR/assets/css/"

rsync_prod \
  "$ROOT/www/api/_web_analytics.php" \
  "$ROOT/www/api/session_public.php" \
  "$REMOTE:$RDIR/api/"
rsync_prod \
  "$ROOT/www/admin/traffic.php" \
  "$ROOT/www/admin/_traffic_decode.php" \
  "$REMOTE:$RDIR/admin/"
rsync_prod \
  "$ROOT/www/auth/do_register.php" \
  "$REMOTE:$RDIR/auth/"

ENGINE_DIR="${DEPLOY_ENGINE_PATH:-/home/bot20/strim.in.ua/engine}"
echo "==> rsync engine (logic + helpers) -> ${REMOTE}:${ENGINE_DIR}/"
rsync_prod \
  "$ROOT/engine/engine_logic.php" \
  "$ROOT/engine/engine_logic_ladder_cascade.php" \
  "$ROOT/engine/engine_helpers.php" \
  "$ROOT/engine/profit_reinvest.php" \
  "$ROOT/engine/ladder_unfreeze_burst.php" \
  "$ROOT/engine/tick_strategy.php" \
  "$REMOTE:$ENGINE_DIR/"

echo "==> done. Verify: curl -sI https://strim.in.ua/dashboard.php | grep -i location"
