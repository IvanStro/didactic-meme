# Правила доступу за підпискою (StrIM)

**Версія:** 2026-05-25  
**Код:** `www/api/_billing_access.php`, cron `run/cron_billing_pause_expired.php`

## Статуси (effective)

| Статус | Умова | Торгівля |
|--------|--------|----------|
| **trial** | `trial_until > now` | ✅ |
| **active** | `paid_until > now` | ✅ |
| **expired** | терміни минули | ❌ |
| **blocked** | адмін-блок | ❌ |

Cron синхронізує `users.billing_status = expired` для прострочених.

## EXPIRED — правила

1. Start / Pause / Stop / ручні ордери / зміна параметрів — **заборонено** (API `403 billing_expired`).
2. `running` → **`paused`** автоматично (`BILLING_PAUSE` у журналі).
3. **Wind-down:** продажі за TP можливі; BUY0 і докупки — **ні**.
4. Кабінет (перегляд) і оплата тарифу — доступні.
5. **Після оплати / продовження:** стратегії з паузою через EXPIRED (`BILLING_PAUSE` або legacy без ручного `USER_PAUSE`) → знову **`running`** (`BILLING_RESUME`). Ручна пауза користувача (`USER_PAUSE` / `USER_STOP`) **не** знімається автоматично.

**Пауза користувачем** (кнопка в кабінеті) — окремо від billing: `www/api/_strategy_status.php`, журнал `USER_PAUSE` / `USER_START`. Див. `NEW_CHAT_HANDOFF.md` §2.6.

Див. також: `/faq.html#billing-expired`, `/instruction.html`.
