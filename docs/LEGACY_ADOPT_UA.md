# Спадок з іншої системи → StrIM (пороги, рекомендації, модель)

Статус: узгоджений наступний крок після концепту (2026-08-29).  
Не обіцяє прибуток; мета — зменшити ризик сліпого старту поверх чужого мішка.

## 1. Пороги класифікації

Усі пороги — у `app_settings` (адмінка), нижче **дефолти**.

| Ключ | Дефолт | Сенс |
|------|--------|------|
| `legacy_dust_usdt` | `7` | Нижче = пил (як мін. FBA тарифу) |
| `legacy_dd_ok_pct` | `15` | Просідання &lt; цього → клас B (здоровий) |
| `legacy_dd_mid_pct` | `40` | 15…40 → клас C (помірне) |
| `legacy_dd_deep_pct` | `40` | ≥ цього → клас D (глибоке) |
| `legacy_stale_days` | `30` | Немає угод N днів → «заморожений мішок» |
| `legacy_rq_step_min` | `5.5` | Мін. USDT кроку для детекту сітки |
| `legacy_rq_step_max` | `8.0` | Макс. для «базового» кроку ≈FBA |

**Просідання (drawdown):**

```
dd_pct = max(0, (avg_cost - spot) / avg_cost * 100)
```

якщо `avg_cost <= 0` або немає історії — `dd_pct = null`, клас **Unknown** (лише Hold + ручний вибір).

**Класи:**

| Клас | Умова | Код |
|------|--------|-----|
| A Пил | `notional < legacy_dust_usdt` | `dust` |
| B Здоровий | `dd_pct < 15` (або spot ≥ avg) | `ok` |
| C Помірне | `15 ≤ dd_pct < 40` | `mid` |
| D Глибоке | `dd_pct ≥ 40` | `deep` |
| E Вже в StrIM | є `strategies` по символу з `qty>0` або running/paused з позицією | `ours` |
| U Невідомо | немає avg / мало трейдів | `unknown` |

---

## 2. Матриця дефолтних рекомендацій

Один **primary** + список **alternatives**. Користувач завжди підтверджує.

| Клас | Primary | Alternatives | Заборонено без double-confirm |
|------|---------|--------------|-------------------------------|
| A `dust` | `ignore` | `sell_dust` | — |
| B `ok` | `adopt_cost` | `adopt_spot`, `hold` | — |
| C `mid` | `adopt_spot` | `hybrid`, `hold`, `exit_plan` | `adopt_cost` |
| D `deep` | `hold` | `exit_plan`, `hybrid`, `adopt_spot` | `adopt_cost` |
| E `ours` | `manage_existing` | — | повторний adopt |
| U `unknown` | `hold` | `adopt_spot` (обережно) | `adopt_cost` |

### Модифікатори (зсув primary)

1. **Free USDT &lt; FBA** (або &lt; min notional) → навіть при B/C primary стає `topup_usdt`, дія сітки — secondary.
2. **Stale ≥ 30 днів** + клас D → primary лишається `hold`; `adopt_spot` лише як alternative з текстом «мішок давно не торгувався».
3. **Сильний RQ-патерн** (частка кроків 5.5–8 USDT ≥ 60% buy-steps) → у тексті: «схоже на сітку іншого бота»; на логіку primary не змінює, але блокує формулювання «ручна позиція».

### Клас D (приклад APE ≈ −72%)

- **Primary: `hold`** — не створювати стратегію, не докуповувати.
- Alternatives:
  - `exit_plan` — план виходу шарами від ринку (без усереднення вниз).
  - `hybrid` — legacy core окремо + нова сітка від spot малим FBA.
  - `adopt_spot` — повний новий цикл від ринку; історичний avg лише в UI «до беззбитку».
- **`adopt_cost` заборонено** в один клік: лише double-confirm + червоний текст, що сітка цілитиме ~avg 0.50 при споті ~0.14.

---

## 3. Сенс дій (коротко для продукту)

| Код дії | Що робить система |
|---------|-------------------|
| `ignore` | Не показувати в активних; пил |
| `sell_dust` | (майбутнє) підказка / ручний sell |
| `hold` | Немає стратегії; пара в списку «спадок / наглядання» |
| `topup_usdt` | Блокер: пояснити поповнення перед сіткою |
| `exit_plan` | Стратегія в режимі `legacy_exit` (або чекліст без авто-buy) |
| `adopt_spot` | Стратегія + позиція: qty з біржі, **grid_anchor = spot**, cost_avg окремо для UI |
| `adopt_cost` | qty+avg з біржі = робочі; next_ptr від 1; лише клас B |
| `hybrid` | `legacy_qty` + `grid` qty=0 спочатку; докупки лише в grid; TP grid як L5+; legacy — окремий exit |
| `manage_existing` | Відкрити існуючу стратегію |

---

## 4. Модель даних (ескіз)

Не ламаємо поточний цикл BTC/ADA. Нові поля — nullable.

### `positions` (додати)

| Поле | Тип | Сенс |
|------|-----|------|
| `origin` | `ENUM('strim','legacy','hybrid')` DEFAULT `'strim'` | Звідки позиція |
| `cost_avg` | `DECIMAL(24,8) NULL` | Історична собівартість (UI / exit), може ≠ `avg_price` |
| `grid_anchor` | `DECIMAL(24,8) NULL` | Якір сітки; якщо NULL — як зараз `base_price` |
| `legacy_qty` | `DECIMAL(24,8) NULL` | У hybrid: частина мішка «поза сіткою» |
| `adopt_mode` | `VARCHAR(32) NULL` | `adopt_spot` / `adopt_cost` / `hybrid` / `exit_plan` |
| `adopted_at` | `DATETIME NULL` | Коли користувач підтвердив |

Правила движка (P2):

- Тригери L1+ рахувати від `COALESCE(grid_anchor, base_price)`.
- MAIN / відображення «до беззбитку» — від `COALESCE(cost_avg, avg_price)`.
- При `origin=legacy|hybrid` **не** брати buy_price з `sub_positions` де `closed=1` як якір (вже зроблено для parallel; cascade теж).
- `start_ts` стратегії при adopt = час підтвердження (історія RQ не змішується в sync як «наш» цикл без потреби).

### `strategies` (опційно)

| Поле | Сенс |
|------|------|
| `legacy_review_status` | `pending` / `resolved` / `skipped` |
| `legacy_review_json` | Останній знімок аналізу (клас, dd, рекомендація) |

### Нова таблиця (P1, зручніше за JSON у strategies)

`legacy_asset_reviews`:

- `id`, `user_id`, `symbol`, `notional`, `qty`, `spot`, `avg_cost`, `dd_pct`, `klass`, `primary_action`, `payload_json`, `user_action`, `resolved_at`, `created_at`

Ідемпотентність: один «відкритий» review на (user, symbol).

---

## 5. API / екрани (ескіз)

### `GET /api/legacy_scan.php` (P1)

Відповідь:

```json
{
  "ok": true,
  "free_usdt": 0.01,
  "items": [
    {
      "symbol": "APEUSDT",
      "qty": 1039.9,
      "notional": 145.0,
      "spot": 0.139,
      "avg_cost": 0.502,
      "dd_pct": 72.2,
      "klass": "deep",
      "stale_days": 110,
      "rq_like": true,
      "primary": "hold",
      "alternatives": ["exit_plan", "hybrid", "adopt_spot"],
      "blocked": ["adopt_cost"],
      "reasons": ["deep_drawdown", "stale", "low_usdt"]
    }
  ]
}
```

### `POST /api/legacy_resolve.php`

`{ symbol, action }` → створює/оновлює стратегію згідно матриці або лише маркує hold.

### UI

1. Після connect API / кнопка «Спадок портфеля».
2. Таблиця пар з класом і primary.
3. Деталь пари: dd, recovery_needed_%, кнопки дій.
4. Double-confirm для заборонених / небезпечних.

Адмінка (P0): той самий звіт read-only по `user_id` (для test2 зараз).

---

## 6. Тексти для класу D (шаблон UA)

> У вас на біржі залишок **APE** ≈ {notional} USDT. Середня собівартість ≈ {avg}, ринок ≈ {spot} (**просідання {dd}%**). Щоб лише вийти в нуль по собівартості, ціна має зрости ще ≈ {recovery}%.  
> **Рекомендуємо:** не запускати сітку від старої собівартості й не докуповувати автоматично. Залиште під наглядом або оберіть план виходу / нову сітку **від поточної ціни** (окремо від історичного avg).

---

## 7. Порядок імплементації

1. **P0** — CLI/admin read-only scan (`run/legacy_portfolio_scan.php`) — **зроблено**.
2. **P1** — `legacy_scan` + UI рекомендацій + `legacy_resolve` для `hold` / `adopt_spot` / `ignore` — **зроблено** (`www/api/legacy_*.php`, банер у `dashboard_saas.html`, таблиця `legacy_asset_reviews`).
3. **P2** — поля `positions` (`origin`, `cost_avg`, `grid_anchor`, `legacy_qty`, `adopt_mode`, `adopted_at`) + режими `hybrid` / `exit_plan` у resolve і блокування докупок у движку — **зроблено**.
4. **P3** — пороги в адмінці (`admin_engine_settings` + блок у `admin/users.php`), кнопка **«Спадок»** завжди в швидкому старті дашборду — **зроблено**.

---

## 8. Фіксація для test2 (очікуваний primary зараз)

| Пара | Очікуваний клас | Primary |
|------|-----------------|---------|
| APE | D deep | `hold` |
| ATOM | D (перевірити dd) | `hold` або `exit_plan` |
| RSR/GRT/DOT/DOGE/SUSHI | C або D | за dd |
| BTC/ADA | E ours | `manage_existing` |
| SXP/ETC/GRAM | A dust | `ignore` |
