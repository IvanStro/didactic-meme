#!/usr/bin/env bash
# Enable Privat bank auto-extend on prod + deploy poll hardening.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

./run/rsync_prod.sh -avz www/api/_privat_bank_billing.php \
  bot20@bot20.ftp.tools:/home/bot20/strim.in.ua/www/api/_privat_bank_billing.php
./run/rsync_prod.sh -avz run/privat_poll_transactions.php \
  bot20@bot20.ftp.tools:/home/bot20/strim.in.ua/run/privat_poll_transactions.php
./run/rsync_prod.sh -avz run/PRIVAT_BANK_BILLING.md \
  bot20@bot20.ftp.tools:/home/bot20/strim.in.ua/run/PRIVAT_BANK_BILLING.md
./run/rsync_prod.sh -avz run/cron_admtools.txt \
  bot20@bot20.ftp.tools:/home/bot20/strim.in.ua/run/cron_admtools.txt

./run/ssh_prod.sh 'cd /home/bot20/strim.in.ua && \
  sed -i.bak-autoext "s/^PRIVAT_ACP_AUTO_EXTEND=.*/PRIVAT_ACP_AUTO_EXTEND=1/" .env && \
  (grep -q "^PRIVAT_ACP_DRY_RUN=" .env && sed -i.bak-autodry "s/^PRIVAT_ACP_DRY_RUN=.*/PRIVAT_ACP_DRY_RUN=0/" .env || true) && \
  /usr/local/php84/bin/php -r "
require \"www/vendor/autoload.php\";
Dotenv\\Dotenv::createImmutable(getcwd())->safeLoad();
\$db=new mysqli(\$_ENV[\"DB_HOST\"],\$_ENV[\"DB_USER\"],\$_ENV[\"DB_PASS\"],\$_ENV[\"DB_NAME\"]);
\$db->set_charset(\"utf8mb4\");
\$db->query(\"UPDATE bank_incoming_payments SET status=\"ignored\", match_note=\"legacy_pre_autoextend\", updated_at=NOW() WHERE id=1 AND status=\"matched\" LIMIT 1\");
echo \"legacy_ignore_affected=\".\$db->affected_rows.PHP_EOL;
" && \
  /usr/local/php84/bin/php run/privat_poll_transactions.php 3 && \
  grep -E "^PRIVAT_ACP_(AUTO_EXTEND|DRY_RUN|AMOUNT)" .env'
