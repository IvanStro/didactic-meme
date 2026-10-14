# V2 rollout gate — бекап → деплой → перевірка

**Використовувати перед кожним деплоєм**, що торкається `engine/`, `engine_v2/`, `run/trading_ops*`, WS price, signal.

Повний план: [`docs/ARCHITECTURE_V2_EVENT_DRIVEN.md`](../docs/ARCHITECTURE_V2_EVENT_DRIVEN.md)

---

## 0. Передумови

- SSH ключ: `~/.ssh/cursor_bot20`
- Хост: `bot20@bot20.ftp.tools`
- Код на сервері: `/home/bot20/strim.in.ua/`
- PHP CLI: `/usr/local/php84/bin/php`

---

## 1. Бекап (обов’язково)

```bash
TS=$(date +%Y%m%d_%H%M%S)
LABEL="v2_step_${TS}"   # замінити на назву кроку, напр. phase1_ws_format

ssh -i ~/.ssh/cursor_bot20 bot20@bot20.ftp.tools "set -e
  BK=/home/bot20/backups/${LABEL}
  mkdir -p \"\$BK\"
  cd /home/bot20/strim.in.ua
  for f in \
    engine/engine_logic.php \
    engine/engine_helpers.php \
    engine_v2/job_handler.php \
    engine_v2/enqueue_running_strategies.php \
    engine_v2/queue_pop.php \
    engine_v2/scaling_schedule.php \
    engine_v2/scaling_binance_cache.php \
    engine_v2/scaling_tick_mode.php \
    www/api/_ws_price.php \
    www/api/health.php \
    run/trading_ops_daemon.php \
    run/platform_watchdog.php \
    run/restart_trading_ops.sh \
    www/data/ws_prices.json
  ; do
    [ -f \"\$f\" ] && cp -a \"\$f\" \"\$BK/\"
  done
  echo backup_dir=\$BK
  ls -la \"\$BK\"
"
```

Записати `backup_dir=...` у нотатки деплою.

---

## 2. Деплой

### Trading / engine (типовий)

```bash
cd /path/to/bot.luckydrop.uno
./run/deploy_trading_ops.sh
```

Додаткові файли (якщо змінювались) — rsync вручну або розширити `deploy_trading_ops.sh`.

### Dashboard / health only

```bash
./run/deploy_www_dashboard.sh
```

### Міграція БД (лише якщо крок це передбачає)

```bash
ssh -i ~/.ssh/cursor_bot20 bot20@bot20.ftp.tools \
  '/usr/local/php84/bin/php /home/bot20/strim.in.ua/run/apply_scaling_stage2_migration.php'
```

---

## 3. Перезапуск (якщо чіпали engine / daemon)

```bash
ssh -i ~/.ssh/cursor_bot20 bot20@bot20.ftp.tools \
  'cd /home/bot20/strim.in.ua && pkill -f engine_v2/worker.php 2>/dev/null || true; sleep 2; ./run/restart_trading_ops.sh; sleep 5; cat logs/trading_ops_daemon.pid; pgrep -c -f engine_v2/worker.php'
```

Очікування:
- новий PID у `trading_ops_daemon.pid`
- workers ≥ `worker_target_count` (зазвичай 10)
- **можливий 1 Telegram від watchdog** — норма на 1–2 хв; якщо повторюється — див. логи

---

## 4. Перевірка (go / no-go)

### 4.1. Health (обов’язково)

```bash
curl -s https://strim.in.ua/api/health.php | python3 -m json.tool
```

| Поле | Go |
|------|-----|
| `ok` | `true` |
| `engine_alive` | `true` |
| `lag_seconds` | `< 30` (після рестарту < 60) |
| `job_queue.pressure` | `ok` |
| `job_queue.failed_last_hour` | `0` |

### 4.2. Watchdog / daemon (SSH)

```bash
ssh -i ~/.ssh/cursor_bot20 bot20@bot20.ftp.tools \
  'tail -5 /home/bot20/strim.in.ua/logs/platform_watchdog.log'
```

Очікування: `severity=ok`, `daemon=up`.

### 4.3. Торгівля (якщо є running)

```bash
# останні рядки воркера з LIGHT/FULL/SHADOW
ssh -i ~/.ssh/cursor_bot20 bot20@bot20.ftp.tools \
  'grep -h "\[STRAT-\|\[SHADOW\]\|\[FULL\]\|\[LIGHT\]" /home/bot20/strim.in.ua/logs/worker-slot-*.log 2>/dev/null | tail -20'
```

Перевірити 1–2 відомі running sid у адмінці: `last_tick_at` оновлюється.

### 4.4. Після змін `engine/signal/eval_strategy.php` (обовʼязково)

`signal_daemon` — long-running PHP: `require_once` не підхоплює новий код без рестарту.

```bash
./run/restart_v2_signal_daemons.sh
```

Або: daemon сам вийде при зміні mtime файлу (cron `cron_ensure_signal_daemon` підніме знову).

### 4.5. Фаза-специфічні перевірки

Див. таблицю в `docs/ARCHITECTURE_V2_EVENT_DRIVEN.md` для поточної фази.

---

## 5. No-go → rollback

```bash
BK=/home/bot20/backups/BACKUP_DIR_FROM_STEP_1
ssh -i ~/.ssh/cursor_bot20 bot20@bot20.ftp.tools "
  cd /home/bot20/strim.in.ua
  cp -a \$BK/engine_logic.php engine/ 2>/dev/null || true
  cp -a \$BK/engine_helpers.php engine/ 2>/dev/null || true
  cp -a \$BK/job_handler.php engine_v2/ 2>/dev/null || true
  cp -a \$BK/_ws_price.php www/api/ 2>/dev/null || true
  cp -a \$BK/health.php www/api/ 2>/dev/null || true
  # ... інші файли з бекапу
  pkill -f engine_v2/worker.php 2>/dev/null || true
  sleep 2
  ./run/restart_trading_ops.sh
"
curl -s https://strim.in.ua/api/health.php
```

Повідомити в Telegram / нотатках: причина rollback + backup_dir.

---

## 6. Go → наступний крок

- [ ] Health ok ≥ 15 хв
- [ ] Немає crit watchdog (крім одноразового після рестарту)
- [ ] Running стратегії тікають
- [ ] Запис у `NEW_CHAT_HANDOFF.md` / changelog (опційно)

---

## 7. Maintenance mode (опційно, великі зміни)

Перед рестартом daemon (не для shadow-only деплоїв):

```sql
-- app_settings: maintenance_engine=1 + повідомлення
-- або env MAINTENANCE_ENGINE=1 — якщо додано в код
```

Після verify — зняти maintenance.
