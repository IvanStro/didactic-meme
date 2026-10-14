# План звіту SHADOW vs SIGNAL_DAEMON vs угоди

**Старт shadow на проді:** 2026-05-19  
**Старт canary (15, 17):** 2026-05-20  
**Мінімальний період:** 24–48 год  

**Команда звіту:**

```bash
php run/shadow_compare_report.php 24
php run/shadow_compare_report.php 48
```

Скрипт читає:
- `logs/worker-slot-*.log` — `[SHADOW]`
- `logs/signal_daemon.log` — `[SIGNAL_DAEMON]`, `[CANARY_ENQUEUE]`
- `logs/signal_exec_worker.log` — `picked` / `done`
- `trade_ledger`, `signal_queue` (для canary)

## Що порівнюємо

| Джерело | Що означає |
|---------|------------|
| SHADOW (worker) | eval у V1 poll tick, без угод |
| SIGNAL_DAEMON | eval на зміну ціни WS |
| CANARY_ENQUEUE | actionable сигнал → `signal_queue` |
| exec worker `picked` | `process_strategy` для canary |
| trade_ledger | фактичні угоди |

| Сигнал | Очікувана угода в ledger |
|--------|--------------------------|
| `MAIN_SELL_FIRE` | SELL / CORE_SELL протягом 1–2 exec |
| `BUY0_ELIGIBLE` | BUY0 коли flat |
| `LADDER_L{n}` | BUY L{n} (може відставати через cascade) |
| `MAIN_ARM` / `MAIN_WAIT_CB` | без угоди (exec може оновити peak) |

## Критерії OK

- SHADOW і SIGNAL_DAEMON: однаковий порядок величини `fire` / `ladder` по sid (допуск lag eval)
- Canary sid: при `daemon fire > 0` інколи є `CANARY_ENQUEUE` або `signal_queue` (якщо ціна біля TP — частіше)
- Немає подвійних SELL у ledger для одного сигналу (dedupe 90 с у queue)
- Ladder: допускаємо lag через `ws_recent_low_1m` у V1

## Після звіту

- OK → фаза 6 hybrid або +sid у `V2_CANARY_STRATEGY_IDS`
- Розбіжності → `engine/signal/eval_strategy.php`, не V1 mass logic
