#!/usr/bin/env bash
# Deploy R1 (tip-only ladder) + R2 (MAIN=core UI) + orphan buy guard.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck source=run/_ssh_prod_common.sh
source "${ROOT}/run/_ssh_prod_common.sh"

ssh_prod_add_key
ssh_prod_ssh_base
SSH=("${SSH_BASE[@]}")
REMOTE="${DEPLOY_USER}@${DEPLOY_HOST}"
RR="${DEPLOY_PATH_ROOT:-/home/bot20/strim.in.ua}"
BK="${RR}/backups/r1_r2_orphan_$(date +%Y%m%d_%H%M%S)"

echo "==> backup -> ${BK}"
ssh_prod_retry "${SSH[@]}" "$REMOTE" "mkdir -p '${BK}' && for f in \
  engine/engine_helpers.php \
  engine/engine_logic.php \
  engine/signal/eval_strategy.php \
  www/api/strategies_list_v2.php \
  www/dashboard_saas.html; do \
  if [ -f '${RR}/'\$f ]; then mkdir -p '${BK}/'\$(dirname \$f) && cp -a '${RR}/'\$f '${BK}/'\$f; fi; \
done && ls -la '${BK}' '${BK}/engine' '${BK}/www/api' 2>/dev/null | head -40"

echo "==> rsync"
ssh_prod_retry rsync -avz -e "${SSH[*]}" \
  "$ROOT/engine/engine_helpers.php" \
  "$REMOTE:$RR/engine/"
ssh_prod_retry rsync -avz -e "${SSH[*]}" \
  "$ROOT/engine/engine_logic.php" \
  "$REMOTE:$RR/engine/"
ssh_prod_retry rsync -avz -e "${SSH[*]}" \
  "$ROOT/engine/signal/eval_strategy.php" \
  "$REMOTE:$RR/engine/signal/"
ssh_prod_retry rsync -avz -e "${SSH[*]}" \
  "$ROOT/www/api/strategies_list_v2.php" \
  "$REMOTE:$RR/www/api/"
ssh_prod_retry rsync -avz -e "${SSH[*]}" \
  "$ROOT/www/dashboard_saas.html" \
  "$REMOTE:$RR/www/"

echo "==> php -l"
ssh_prod_retry "${SSH[@]}" "$REMOTE" "cd '$RR' && /usr/local/php84/bin/php -l engine/engine_helpers.php && /usr/local/php84/bin/php -l engine/engine_logic.php && /usr/local/php84/bin/php -l engine/signal/eval_strategy.php && /usr/local/php84/bin/php -l www/api/strategies_list_v2.php"

echo "==> grep markers"
ssh_prod_retry "${SSH[@]}" "$REMOTE" "grep -n 'ladder_next_tip_level\\|ORPHAN_GUARD\\|main_sell_source\\|ladder_tip' \
  $RR/engine/engine_helpers.php $RR/engine/engine_logic.php $RR/engine/signal/eval_strategy.php $RR/www/api/strategies_list_v2.php | head -30"

echo "==> restart V2 daemons"
ssh_prod_retry "${SSH[@]}" "$REMOTE" "cd '$RR' && bash run/restart_v2_signal_daemons.sh"

echo "==> smoke DOGE/BTC helpers"
ssh_prod_retry "${SSH[@]}" "$REMOTE" 'cd /home/bot20/strim.in.ua && /usr/local/php84/bin/php -r "
require \"www/vendor/autoload.php\";
require \"engine/engine_helpers.php\";
Dotenv\Dotenv::createImmutable(getcwd())->safeLoad();
\$db=new mysqli(\$_ENV[\"DB_HOST\"],\$_ENV[\"DB_USER\"],\$_ENV[\"DB_PASS\"],\$_ENV[\"DB_NAME\"]);
\$db->set_charset(\"utf8mb4\");
\$pr=\$db->query(\"SELECT * FROM positions WHERE strategy_id=9 ORDER BY id DESC LIMIT 1\")->fetch_assoc();
\$core=position_core_basis(\$db, \$pr);
echo \"DOGE core=\".json_encode(\$core).PHP_EOL;
echo \"DOGE tip=\".ladder_next_tip_level(\$db,(int)\$pr[\"id\"]).PHP_EOL;
\$btc=\$db->query(\"SELECT * FROM positions WHERE strategy_id=2 ORDER BY id DESC LIMIT 1\")->fetch_assoc();
echo \"BTC tip=\".ladder_next_tip_level(\$db,(int)\$btc[\"id\"]).\" max=\".position_max_open_level_idx(\$db,(int)\$btc[\"id\"]).PHP_EOL;
\$ob=ladder_orphan_should_block_buys(\$db,(int)\$pr[\"id\"],(float)\$pr[\"qty\"],0.0815,50.0);
echo \"DOGE orphan_block=\".json_encode(\$ob).PHP_EOL;
"'

echo "==> done R1+R2+orphan"
