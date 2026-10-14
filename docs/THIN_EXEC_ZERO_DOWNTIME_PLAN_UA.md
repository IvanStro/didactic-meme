# Thin Exec → корпоративний масштаб (zero-downtime)

**Версія:** 1.1  
**Дата:** 2026-09-17  
**Статус:** план впровадження (без змін торгівлі, поки не пройдено gate)  
**Принцип:** діючі користувачі **не повинні помітити** перехід. Будь-який крок або невидимий, або відкочується за хвилини без пояснень у TG/сапорт.

**Жорстке правило процесу:** кожна наступна дія = **Бекап → Дія → Перевірка → Підтвердження → Висновок**. Без заповненого висновку «GO» наступний крок **заборонено**.

Пов’язано:
- `docs/ARCHITECTURE_V2_EVENT_DRIVEN.md`
- `docs/STAGE3_500_REDIS_MULTIHOST.md`
- `docs/SCALING_ROADMAP.md`
- `run/V2_ROLLOUT_GATE.md`
- Журнал кроків: `run/THIN_EXEC_STEP_LOG.md` (шаблон нижче / у файлі)

---

## 0−. Обов’язковий цикл кожної дії (не пропускати)

Будь-який пункт плану (A1, B1, C2, зміна env, деплой, restart daemon, хвиля canary, VPS…) проходить **один і той самий цикл**.

```text
1. БЕКАП        → що саме зберегли, шлях, timestamp
2. ДІЯ          → що змінили (файли / env / процес)
3. ПЕРЕВІРКА    → команди + фактичні результати (вставити output)
4. ПІДТВЕРДЖЕННЯ→ людина: «юзери не постраждали / інваріанти OK»
5. ВИСНОВОК     → GO | HOLD | ROLLBACK (+ чому)
```

### Правила

| # | Правило |
|---|--------|
| 1 | Немає бекапу → немає дії |
| 2 | Немає перевірки з output → немає підтвердження |
| 3 | Немає висновку **GO** → наступний крок не починається |
| 4 | Висновок **ROLLBACK** → спочатку відкат і новий цикл «перевірка після rollback», лише потім знову планувати |
| 5 | Висновок **HOLD** → стоп; фіксуємо blocker; не «підкручуємо на льоту» без нового бекапу |
| 6 | Одна «логічна» зміна = один цикл (не звалювати деплой + canary + reconcile в один крок) |

### Шаблон запису (копіювати в журнал на кожен крок)

```markdown
### Крок: <ID, напр. A1 / B-shadow-on / C-canary-sid2>
Дата/час (Kyiv):
Виконавець:

#### 1. Бекап
- Що: файли / .env snippet / DB snapshot (якщо треба)
- Куди: `/home/bot20/backups/<label>_YYYYMMDD_HHMMSS/`
- Команда:
- Результат: OK / FAIL

#### 2. Дія
- Опис:
- Flag/env до → після:
- Деплой/restart:

#### 3. Перевірка (вставити факти)
- [ ] `php run/v2_status_check.php` → …
- [ ] watchdog / tick_lag → …
- [ ] `signal_exec*.log` без Fatal → …
- [ ] `prescale_audit` / orphan (якщо релевантно) → …
- [ ] UI smoke (1 running пара) → …
- Інше:

#### 4. Підтвердження
- Інваріанти продукту: OK / NOT OK
- Скарги юзерів / аномалії TG: немає / є (опис)
- Підтвердив: <ім’я> о <час>

#### 5. Висновок
- [ ] GO — можна наступний крок `<ID>`
- [ ] HOLD — причина:
- [ ] ROLLBACK — виконано о <час>; перевірка після rollback: OK / NOT OK
```

### Мінімальний набір перевірок (майже після кожного кроку)

```bash
# на проді
/usr/local/php84/bin/php run/v2_status_check.php
# немає Fatal
tail -n 50 logs/signal_exec_slot_1.log logs/signal_daemon.log
# lag / queue
# (див. також health / platform_watchdog.log)
```

Після кроків, що чіпають ордери / canary / thin:

```bash
/usr/local/php84/bin/php run/prescale_audit_all.php
/usr/local/php84/bin/php run/audit_signal_queue_all.php
```

Бекап файлів — за `run/V2_ROLLOUT_GATE.md` §1 (розширити список файлів під конкретний крок).

---

## 0. Що означає «користувачі навіть не зрозуміли»

### 0.1. Невидимість = інваріанти продукту

Під час і після кожного кроку **має лишатися правда**:

| Інваріант | Як перевіряємо |
|-----------|----------------|
| Ордери ті самі (BUY0 / L1… / SUB / MAIN) | journal + trade_ledger vs Binance myTrades |
| Немає подвійних SELL | `prescale_audit` / orphan sells = 0 нових |
| Немає пропущених SELL на TP | shadow compare + canary journal |
| TG-повідомлення як раніше | той самий `_tg_notify` шлях |
| UI: статус, U/R, «що робить бот» | dashboard без «Engine down» / tick_lag crit |
| Пауза / старт / ліміт пар | billing + CSRF API без регресій |

Якщо хоч один інваріант ламається на canary — **стоп і rollback**, без «пояснити всім».

### 0.2. Правило деплою

1. Код за замовчуванням **вимкнений** feature-flag’ом.  
2. Спочатку **shadow** (лог, без зміни ордерів).  
3. Потім **1–2 canary sid** (краще внутрішні / admin / test).  
4. Потім **1 платячий quiet user** (за згодою або ваш акаунт).  
5. Лише тоді % rollout.  
6. **Кожна** дія — повний цикл §0− (бекап → … → висновок GO).  
7. Деплой у **тихі години** (не під час сильного руху ринку, якщо можна).  
8. Watchdog / TG alerts очікувані лише як *ваші* ops-алерти, не як масовий UX.  
9. Заборонено «заодно» вмикати наступний flag у тому ж деплої без окремого циклу.  

### 0.3. Що користувач ніколи не повинен побачити

- «Бот завис» / tick_lag у кабінеті хвилинами  
- Подвійні продажі / «зникла позиція»  
- Мовчання на явному TP  
- Потребу перепідключити API ключі  
- Зміну логіки сітки (TP%, ladder) без окремого продуктового рішення  

---

## 1. Де ми зараз (факт)

```
WS price (1× на символ)
  → signal_daemon (eval усіх sid на символі)
  → signal_queue
  → exec_worker
  → ПОВНИЙ process_strategy()   ← вузьке місце
```

- `ENGINE_MODE=v2`, `SIGNAL_EXEC_WORKERS=4`, cron на одному хості.  
- Ціна вже «по-дорослому» (спільна).  
- Exec ще «по-старому» (важкий тік на кожен сигнал/reconcile).  
- Stage 3: dual queue є; окремий Worker VPS — ще ні.

**Мета плану:** зробити exec як у великих конкурентів — тонкий ордер + рідкий reconcile — **без зміни торгової логіки правил**.

---

## 2. Цільова модель (після thin exec)

```
WS price (1× BTCUSDT)
  → eval усіх sid на символі (локально, без Binance)
  → якщо дія потрібна → signal_queue {sid, action, level, …}
  → thin_exec: лише place_order + ledger + мінімальне оновлення positions
  → рідкий RECONCILE (після угоди + cadence 2–5 хв), не на кожен сигнал
```

10 юзерів на BTCUSDT:
- ціна: **1** WS  
- eval: 10 локальних  
- REST ордери: **лише** тим, кому реально треба торгувати  

---

## 3. Етапи (огляд)

| Етап | Назва | Видимість для юзерів | Тривалість (орієнтир) |
|------|-------|----------------------|------------------------|
| **A** | Підготовка / вимірювання / freeze логіки | нуль | 1–2 дні |
| **B** | Shadow thin-path (лог «що б зробив») | нуль | 3–7 днів |
| **C** | Canary thin exec (1–3 sid) | майже нуль | 3–7 днів |
| **D** | Розширення canary → cohort → 100% | нуль, якщо gate OK | 1–2 тижні |
| **E** | Reconcile рідше / light-only | нуль | 3–5 днів |
| **F** | Worker VPS (Stage 3D) | нуль | 3–7 днів |
| **G** | Redis pop + shards / autoscale | нуль | за потреби |

**Не робити F/G до стабільного D.** Сервери без thin exec лише дорожче ганяють ту саму важку роботу.

---

## 4. Етап A — Підготовка (без зміни торгівлі)

### A1. Базовий знімок «healthy»

Перед будь-яким кодом:

```bash
php run/prescale_audit_all.php
php run/audit_signal_queue_all.php
php run/v2_status_check.php
# health + watchdog: tick_lag ok, price ok, workers alive
```

Зафіксувати:
- кількість running / users  
- p95 часу `process_strategy` (з логів)  
- глибину `signal_queue`  
- частоту `RECONCILE_TICK` vs `MAIN_SELL` / `SUB_SELL_*` / `LADDER_*`

### A2. Feature flags (контракт)

Додати в `.env` / `app_settings` (усі default = off):

| Ключ | Default | Значення |
|------|---------|----------|
| `THIN_EXEC_MODE` | `off` | `off` \| `shadow` \| `canary` \| `on` |
| `THIN_EXEC_CANARY_SIDS` | `` | `2,9` (лише ці sid) |
| `THIN_EXEC_ACTIONS` | `` | спочатку `SUB_SELL_*`, потім `MAIN_SELL`, потім `LADDER_*` / `BUY0` |
| `THIN_EXEC_FALLBACK_FULL` | `1` | при будь-якій помилці thin → повний `process_strategy` |
| `MAINTENANCE_WATCHDOG_SOFT` | `0` | опційно приглушити false-crit на 10 хв під час restart |

**Правило:** `off` = поведінка байт-в-байт як зараз.

### A3. Observability до змін

Додати (або розширити) логи/метрики **без зміни угод**:
- `exec_path=full|thin|fallback`  
- latency pick→done  
- `signal_name` + sid  
- лічильник fallback  

Щоб порівнювати shadow vs reality.

### A4. Ритуал деплою (обов’язково)

Кожен PR/деплой thin exec:
1. `run/V2_ROLLOUT_GATE.md` — бекап `engine/`, `engine_v2/`, signal handlers  
2. Deploy коду з `THIN_EXEC_MODE=off`  
3. Restart signal daemons  
4. 15–30 хв спостереження: немає fatal, lag нормальний  
5. Лише потім вмикати shadow/canary **через env**, без нового деплою коду  

---

## 5. Етап B — Shadow thin-path (юзери не помічають взагалі)

### B1. Ідея

Після звичайного (повного) exec **або** паралельно в daemon:

- порахувати: «thin handler для цього signal зробив би ордер X qty/price»  
- **не слати** ордер  
- записати в `logs/thin_exec_shadow.log` або таблицю `thin_exec_shadow`  
- порівняти з тим, що реально зробив повний `process_strategy` (journal / ledger)

### B2. Порядок сигналів у shadow

Іти від найпростішого до складнішого:

1. `SUB_SELL_L*` — найчіткіший контракт (sub_position_id, qty, TP)  
2. `MAIN_SELL_FIRE` / arm-path  
3. `LADDER_L*` / catch-up  
4. `BUY0_ELIGIBLE`  

Не починати з BUY0/ladder cascade.

### B3. Gate виходу з shadow

Мінімум **72 год** (краще 7 днів) і:

| Метрика | Поріг |
|---------|--------|
| Shadow vs actual SELL match rate | ≥ 99.5% на canary-типових подіях |
| False positive (shadow хотів sell, full не продав і правильно) | розібрати вручну кожен |
| False negative (full продав, shadow мовчав) | **0** на критичних TP |
| Нові orphan/dupL | 0 |

Якщо пороги не виконані — **залишаємось у shadow**, правимо eval/thin draft, не йдемо далі.

### B4. Rollback shadow

`THIN_EXEC_MODE=off` — тіки як раніше. Код shadow може лишатись мертвим.

---

## 6. Етап C — Canary thin exec (мікро-ризик)

### C1. Вибір canary

Порядок:

1. Внутрішня / admin стратегія з невеликим FBA  
2. Друга стратегія на тому ж акаунті  
3. Один quiet paying user **лише після** успіху 1–2  

**Не** вмикати одразу vanja / Irka / усі 14 sid.

### C2. Поведінка canary

Для sid ∈ `THIN_EXEC_CANARY_SIDS` і action ∈ allowlist:

```
signal_handle_exec_job:
  if thin_eligible:
    try thin_exec(action)
    on success → done
    on error/uncertainty → THIN_EXEC_FALLBACK_FULL=1 → process_strategy
  else:
    process_strategy  # як зараз
```

**Критично:** thin exec **перевіряє live spot / TP ще раз** перед MARKET (як зараз SUB sell не продає «по high», лише по live). Жодних «довірився сигналу сліпо».

### C3. Що thin exec робить (мінімальний контракт)

Для `SUB_SELL_L{n}` (приклад першого live):

1. Lock `sub_sell_{sid}_{spId}` + `strat_proc_{sid}`  
2. Перечитати sub з БД; якщо closed → no-op done  
3. Live price ≥ TP  
4. Place SELL (існуючі helpers з `engine_logic`, не новий брокер)  
5. ledger_write idempotent  
6. closed=1, оновити positions qty  
7. TG notify той самий шлях  

**Не** робити в thin: повний `sync_from_trades`, ladder catch-up, BUY0, shadow eval заново.

### C4. Gate canary (48–72 год)

- 0 unexpected double sells  
- 0 missed TP на canary (ручний/audit)  
- fallback rate < 5% і всі fallback зрозумілі  
- UI canary-юзера без скарг  
- `prescale_audit` OK  

### C5. Rollback canary (≤ 2 хв)

```bash
# на проді
THIN_EXEC_MODE=off   # або прибрати sid з CANARY_SIDS
# restart exec workers (підхоплять env) 
./run/restart_v2_signal_daemons.sh
```

Юзеру нічого не писати: повний тік знову на місці.

---

## 7. Етап D — Розширення без «великого вибуху»

### D1. Хвилі

| Хвиля | Кого | Режим |
|-------|------|--------|
| D0 | 1–3 внутрішні sid | canary SUB_SELL only |
| D1 | + MAIN_SELL на тих самих sid | canary |
| D2 | + 10% running sid (random sticky) | canary list / hash |
| D3 | + 50% | canary |
| D4 | `THIN_EXEC_MODE=on` усі | on + fallback full ще ввімкнений |
| D5 | Вимкнути fallback лише коли fallback < 0.5% тиждень | обережно |

Між хвилями: **мінімум 48 год зелених метрик**.

### D2. Kill-switch «червона кнопка»

Один env / app_settings:

`THIN_EXEC_MODE=off` — миттєво всі назад на full tick.

Документ для себе (не для юзерів): «якщо TG watchdog crit tick_lag + thin errors — спочатку kill-switch, потім розбирати».

### D3. Комунікація

**За замовчуванням — нуль повідомлень юзерам.**  
Внутрішній ops-лог / admin note достатньо.  
Публічний пост лише якщо був реальний інцидент із втратою грошей (тоді вже інший playbook).

---

## 8. Етап E — Reconcile «рідше, але чесно»

Лише після стабільного thin на основних sells.

### E1. Розділити типи jobs у черзі

| Job | Частота | Вага |
|-----|---------|------|
| Action signals (SELL/BUY) | event-driven | thin |
| `RECONCILE_TICK` | 120–300 с (замість ~60) для «тихих» | light sync / full рідше |
| Post-trade reconcile | одразу після успішного thin order | full для цього sid |

### E2. Flag

`V2_SIGNAL_RECONCILE_SEC=120` спочатку на canary sid, потім глобально.

### E3. Gate

- Немає розсинхрону qty vs Binance (audit)  
- UI не показує «фантомні» позиції  
- Lag watchdog пороги підлаштувати під новий cadence (як уже зроблено для V2), щоб не було false alarm  

---

## 9. Етап F — Worker VPS (Stage 3D) — інфра без зміни логіки

Коли thin exec уже `on` і стабільний:

### F1. Підготовка VPS #2

- PHP 8.4 CLI, той самий код (rsync), `.env` з `STRIM_NODE_ROLE=worker`  
- **Без** trading cron на worker  
- SSH + deploy key  
- Outbound до Binance  

### F2. Dual-run (невидимий)

1. Workers крутяться на control **і** на VPS #2, але шарди розділені (`WORKER_SHARD_IDS`) так, щоб **не було double-consume** одного job.  
2. Або спочатку VPS #2 лише shadow latency (не pop).  
3. Перевести 25% шардів → 50% → 100% на VPS #2.  
4. `STRIM_EXEC_ON_CONTROL=0` лише коли VPS #2 стабільно 48 год.

### F3. Rollback F

`STRIM_EXEC_ON_CONTROL=1` + stop worker processes на VPS #2. Control знову тягне все.

---

## 10. Етап G — Redis pop + autoscale (за потреби)

Див. `docs/STAGE3_500_REDIS_MULTIHOST.md` фази C→E.

Умова старту G: thin+E стабільні, або control CPU/черга впираються навіть після thin.

Порядок: dual → порівняння глибин → redis pop на control → потім multihost.

---

## 11. Матриця ризиків і відповіді

| Ризик | Як не дати юзеру «зрозуміти» |
|-------|------------------------------|
| Баг thin → подвійний SELL | Існуючі locks + ledger UNIQUE + shadow gate + canary |
| Баг thin → пропуск SELL | `THIN_EXEC_FALLBACK_FULL` + reconcile cadence + orphan audit |
| Fatal після деплою (як return bool) | PHP `-l` у gate; canary 15 хв; smoke `process_strategy` на dry sid |
| Watchdog spam під restart | короткий maintenance soft; restart script чекає PID |
| Роз’їзд shadow і full | не йти в canary |
| Людська помилка env | checklist двох осіб / записаний rollback |

---

## 12. Чеклист одного кроку (копіпаста = цикл §0−)

```text
Крок ID: _______________
[ ] 1. БЕКАП зроблено, шлях записано
[ ] 2. ДІЯ виконана (один змістовний change)
[ ] 3. ПЕРЕВІРКА: v2_status / логи / (audit якщо треба) — output збережено
[ ] 4. ПІДТВЕРДЖЕННЯ: інваріанти OK, юзери OK
[ ] 5. ВИСНОВОК: GO / HOLD / ROLLBACK
[ ] Запис додано в run/THIN_EXEC_STEP_LOG.md
[ ] Якщо не GO — наступний крок НЕ починати
```

Додатково для деплою коду:

```text
[ ] php -l усі змінені PHP
[ ] THIN_EXEC_MODE на проді перевірено очима (не «думаємо що off»)
[ ] restart_v2_signal_daemons.sh після коду engine/signal
[ ] 15 хв спостереження перед будь-яким увімкненням flag
```

---

## 13. Порядок робіт у репо (технічний backlog)

1. `engine/execution/thin_sub_sell.php` (або аналог) — витягти з `handle_sub_sells` мінімальний шлях  
2. `engine_v2/signal_exec_handler.php` — гілка thin vs full за flags  
3. `engine_v2/thin_exec_config.php` — читання env/canary  
4. Shadow logger + `run/thin_exec_shadow_report.php`  
5. Метрики в health / admin ops (опційно)  
6. Документація rollback у `run/THIN_EXEC_RUNBOOK.md`  
7. Лише потім — ladder/BUY0 thin  
8. Лише потім — Stage 3D VPS  

**Не** переписувати `engine_logic.php` «з нуля». Виносити існуючі перевірені шматки.

---

## 14. Критерій «корпоративно готово» (для вашої цілі)

Можна казати «готово до великої кількості пар», коли:

1. Thin exec на **усіх** action-сигналах, fallback рідкісний  
2. Reconcile не є головним навантаженням  
3. Exec на окремому worker-вузлі (або кількох)  
4. Load-test: сотні synthetic running без tick_lag crit  
5. 14+ днів без thin-related інцидентів на проді  

«Необмежено» у сенсі продукту = **немає штучного product-cap**, масштаб = інфра + thin path. Фізичні ліміти Binance IP/key лишаються — їх обходять пулом IP / окремими worker egress за ToS, не магією.

---

## 15. Наступна конкретна дія (старт)

Кожен рядок нижче = **окремий** цикл §0− (окремий бекап і окремий висновок GO).

1. **Затвердити** цей документ (висновок GO на «план прийнято»).  
2. **A0** — створити `run/THIN_EXEC_STEP_LOG.md` з першим записом baseline.  
3. **A1** — знімок healthy (аудит/status), без зміни коду.  
4. **A2** — деплой лише flags/метрик з `THIN_EXEC_MODE=off` → перевірка → GO.  
5. **B1** — увімкнути shadow `SUB_SELL_*` (окремий цикл) → 72 год спостереження з періодичними перевірками → висновок GO/HOLD на canary.

Поки останній висновок не **GO** — наступний ID не відкривати.  
Поки shadow не зелений — **жодного** thin live і **жодного** Worker VPS «для швидкості».
