# V2 Canary — GO (2026-05-22)

## Критерії

| # | Перевірка | Статус |
|---|-----------|--------|
| 1 | signal_daemon + exec alive | OK |
| 2 | Повний цикл signal → queue → exec → BUY | **2 угоди** |
| 3 | `signal_queue.failed` = 0 | OK |
| 4 | Немає дубль-SELL на один сигнал | OK (dedupe 90s) |

## Успішні угоди (V2 path)

| sid | User | Пара | Рівень | qid | Час (лог) |
|-----|------|------|--------|-----|-----------|
| 15 | test2 | BTCUSDT | **L6** | 1 | 2026-05-22 ~07:26 |
| 22 | 1pavlo1 | SUIUSDT | **L5** | 2 | 2026-05-22 ~08:06 |

Ланцюжок: `[SIGNAL_DAEMON] signals=LADDER_*` → `[SIGNAL_ENQUEUE]` → `picked` → journal `BUY_L*`.

## Виправлення під час canary

1. **eval_strategy.php** — ladder не обрізався при `last_peak=0` (early return).
2. **signal_daemon** — рестарт після змін eval (`require_once` у long-running процесі).
3. **eval** — `min(ws_mid, ws_recent_low_1m)` для ladder (як V1 для CB).

## Наступний крок (фаза 6C)

```env
V2_HYBRID_EXTRA_STRATEGY_IDS=16
```

RSR #16 (Vladyslav) — активніша V1-пара, тест signal-exec без зміни canary list.

Після 48h без інцидентів → **фаза 7** `ENGINE_MODE=v2` (усі running на signal-exec).

## Rollback

```env
V2_CANARY_STRATEGY_IDS=15,17,22
V2_HYBRID_EXTRA_STRATEGY_IDS=
./run/restart_v2_signal_daemons.sh
```
