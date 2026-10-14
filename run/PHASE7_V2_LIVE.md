# Фаза 7 — V2 live (RoyalQ для всіх running)

**Статус:** ✅ **увімкнено на проді 2026-05-23 ~16:33 Kyiv** (`ENGINE_MODE=v2`, backup `phase7_v2_live_20260523_163326`).

Усі 5 running → **V2-signal**. `enqueue_running_strategies` → `queued=0` для poll.

## Go / no-go (2026-05-23)

| Перевірка | Результат |
|-----------|-----------|
| `health.php` ok, `engine_alive` | ✅ lag 4–9 с |
| `signal_daemon` + `exec_worker` | ✅ alive |
| `signal_queue` processing | ✅ 0 завислих |
| Dedupe (після fix 23.05) | ✅ ~15 done/год, ~2/10 хв |
| Canary угоди V2 | ✅ sid 15 BTC L6, sid 22 SUI (50+ BUY) |
| Hybrid на проді | ✅ з 2026-05-21 (~48+ год) |
| Watchdog | ✅ severity=ok, 4 workers |
| `job_queue` pressure | ✅ ok |

**Залишок V1:** лише **sid 18 XRPUSDT** (V1-poll). Фаза 7 переводить і її на signal-exec.

## Команда (після підтвердження)

```bash
chmod +x run/apply_phase7_v2_live.sh
./run/apply_phase7_v2_live.sh
```

Або вручну на сервері:

```env
ENGINE_MODE=v2
V2_CANARY_EXEC=1
# canary + extra ids лишаються (не шкодять у v2 — усі running на signal path)
```

```bash
./run/restart_v2_signal_daemons.sh
./run/restart_trading_ops.sh
php run/v2_status_check.php
```

## Очікування після v2

- У `v2_status_check.php` усі 5 running → **V2-signal**
- `enqueue_running_strategies` → `queued=0` для running (лише reconcile jobs)
- XRP #18: перші сигнали в `signal_queue` при зміні ціни

## Моніторинг 24–48 год

```bash
php run/v2_status_check.php
php run/shadow_compare_report.php 24
curl -s https://strim.in.ua/api/health.php | jq '.engine_mode,.signal_queue,.lag_seconds'
```

## Rollback

```env
ENGINE_MODE=hybrid
```

```bash
./run/restart_v2_signal_daemons.sh && ./run/restart_trading_ops.sh
```

Див. `run/V2_ROLLOUT_GATE.md` §5 (backup restore).

---

*Попередній крок (✅): фаза 6 hybrid + extra sid 16 + CPU tune + signal_queue dedupe.*
