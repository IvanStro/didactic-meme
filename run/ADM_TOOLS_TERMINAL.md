# ADM.TOOLS — термінал bot20 (копіювати як є)

Проєкт на хості **не** в домашній папці `~`, а тут:

```text
/home/bot20/strim.in.ua/
```

PHP CLI:

```text
/usr/local/php84/bin/php
```

---

## Звіт shadow / canary (24 або 48 год)

```bash
cd /home/bot20/strim.in.ua
/usr/local/php84/bin/php run/shadow_compare_report.php 24
```

Якщо `Could not open input file` — файлу ще немає на сервері (див. «Завантажити файли» нижче).

---

## Health / canary

```bash
cd /home/bot20/strim.in.ua
/usr/local/php84/bin/php -r "
require 'engine_v2/bootstrap.php';
require 'engine_v2/engine_mode.php';
echo 'canary='.implode(',', engine_v2_canary_strategy_ids()).PHP_EOL;
echo 'exec='.(engine_v2_canary_exec_enabled()?'1':'0').PHP_EOL;
"
curl -sS https://strim.in.ua/api/health.php | /usr/local/php84/bin/php -r '
$j=json_decode(stream_get_contents(STDIN),true);
print_r($j["v2_canary"]??null);
print_r($j["signal_exec"]??null);
'
```

---

## Завантажити файли з Mac (у терміналі **Mac**, не на хості)

```bash
cd "/Users/ivanstrokacenko/Documents/бот з курсор/bot.luckydrop.uno"

rsync -avz -e "ssh -i ~/.ssh/cursor_bot20" \
  ./run/shadow_compare_report.php \
  ./run/SHADOW_REPORT_PLAN.md \
  ./run/ADM_TOOLS_TERMINAL.md \
  bot20@bot20.ftp.tools:/home/bot20/strim.in.ua/run/

./run/deploy_www_dashboard.sh
```

`deploy_www_dashboard.sh` теж запускати **з кореня репозиторію** на Mac, не з `/home/bot20`.

---

## Cron (перевірка)

У панелі ADM.TOOLS команди **без** `cd` — повний шлях, див. `run/cron_admtools.txt`.
