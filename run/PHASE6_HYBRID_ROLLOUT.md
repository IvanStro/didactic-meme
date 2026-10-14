# Фаза 6 — Hybrid rollout (RoyalQ)

**Передумова:** фази 1–5 на проді, canary **15, 17, 22** (22 = 1pavlo1 SUIUSDT, активні угоди).

Деплой коду (без зміни режиму):

```bash
./run/deploy_v2_phase6_hybrid.sh
```

---

## Що додано в коді

| Env | Значення | Ефект |
|-----|----------|--------|
| `ENGINE_MODE` | `shadow` → `hybrid` | Signal daemon + поступовий signal-exec |
| `V2_HYBRID_EXEC_PCT` | `0`…`100` | % sid (id % 100) на signal-exec, лише в `hybrid`/`v2` |
| `V2_HYBRID_EXTRA_STRATEGY_IDS` | `22,16` | Явний allowlist поверх % |
| `V2_SIGNAL_RECONCILE_SEC` | `60` (default) | Періодичний V1 reconcile для signal-exec sid |

**Без зміни .env** (лише деплой phase 6):
- Canary 15, 17 як раніше (`V2_CANARY_STRATEGY_IDS` + `V2_CANARY_EXEC=1`)
- **Нове:** reconcile-тік кожні ~60 с для sid на signal-exec (sync з Binance між сигналами)
- Shadow eval узгоджено з signal_daemon (свіжа позиція з БД)

---

## Кроки rollout (рекомендовано)

### Крок A — лишаємо shadow (зараз)

```env
ENGINE_MODE=shadow
V2_CANARY_STRATEGY_IDS=15,17,22
V2_CANARY_EXEC=1
V2_HYBRID_EXEC_PCT=0
```

Перевірка:

```bash
php run/shadow_compare_report.php 48
curl -s https://strim.in.ua/api/health.php | jq '.engine_mode,.signal_exec,.v2_canary'
```

### Крок B — hybrid, лише canary (без масового %) — **увімкнено на проді 2026-05-21**

```env
ENGINE_MODE=hybrid
V2_HYBRID_EXEC_PCT=0
V2_HYBRID_V1_TICK_SEC=15
```

(15, 17, 22 на signal-exec через `V2_CANARY_STRATEGY_IDS`; решта running — V1 poll рідше.)

Рестарт:

```bash
ssh bot20 'cd /home/bot20/strim.in.ua && ./run/restart_trading_ops.sh && bash run/start_signal_daemon.sh && bash run/start_signal_exec_worker.sh'
```

### Крок C — розширення signal-exec (без %) — **2026-05-19**

```env
V2_HYBRID_EXTRA_STRATEGY_IDS=16
```

RSR #16 (Vladyslav) — перша non-canary пара на V2 path. Canary 15,17,22 без змін.

Альтернатива (не використовуємо зараз — running id 15–22, `%10` нікого не додає):

```env
V2_HYBRID_EXEC_PCT=10
```

Спостерігати 48 год: `shadow_compare_report.php`, journal, `signal_queue`, watchdog.

### Крок D — фаза 7 (пізніше)

```env
ENGINE_MODE=v2
V2_CANARY_EXEC=1
```

Усі running на signal-exec; V1 poll enqueue вимкнено глобально (окремий PR/деплой).

---

## Rollback

```env
ENGINE_MODE=shadow
V2_HYBRID_EXEC_PCT=0
```

+ `run/V2_ROLLOUT_GATE.md` §5 (restore backup).

---

*Див. `docs/ARCHITECTURE_V2_EVENT_DRIVEN.md` § фаза 6–7.*
