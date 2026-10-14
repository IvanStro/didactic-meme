# V2 cleanup & ops (після ENGINE_MODE=v2)

## Зроблено в репо / на проді

| Що | Файл |
|----|------|
| Purge `signal_queue` / `job_queue` (done/failed >14d) | `run/cron_purge_queues.php` |
| Rotate великих логів | `run/cron_rotate_logs.php` |
| Reap stuck `processing` | `run/reap_signal_queue_stale.php` (cron */5) |
| V2 ops tune (2 workers, enqueue 30s) | `run/apply_v2_ops_tune.sh` |
| Watchdog без хибного tick_lag у v2 | `run/platform_watchdog.php` |
| Тихіший `trading_ops_daemon.log` у v2 | `run/trading_ops_daemon.php` |
| UI «V2 signal» | `www/dashboard_saas.html` |
| **SUB sell по TP (V2 сигнали)** | `engine/signal/eval_strategy.php`, `signal_queue.php` |
| **Заморозка без USDT на spot** | `engine/spot_funds_freeze.php` |
| **Розморозка після поповнення** | `run/cron_spot_funds_unfreeze.php` (cron */10) |
| Reconcile через `signal_queue` | `engine_v2/scaling_schedule.php` |

## Заморозка vs пауза

| | **Пауза** (`status=paused`) | **Заморозка** (`spot_funds_frozen`) |
|--|--|--|
| Хто вмикає | Користувач (кабінет) / billing cron | Автоматично при нестачі USDT |
| Статус стратегії | `paused` | **`running`** |
| Докупки | вимкнені (`blocksNewBuys` + скасування `LADDER_L*` у черзі) | вимкнені |
| TP / SUB sell | активні | **активні** |
| V2 symbol_index | **paused лишається в індексі** (signal_daemon — лише виходи) | без змін |
| Журнал | `USER_PAUSE` / `USER_START` / `USER_STOP` | `SPOT_FUNDS_FROZEN` тощо |
| Зняття | «Старт» у кабінеті → `USER_START` | Поповнення Binance spot |

**Код (2026-06-17):** `www/api/_strategy_status.php`, `strategy_set.php`; verify: `run/verify_strategy_pause.php`.

## Що лишається (rollback)

- `job_queue` + `engine_v2/worker.php` — резерв reconcile
- `engine_v2/engine_mode.php` — rollback `ENGINE_MODE=hybrid`
- Legacy `engine/tick_strategy*.php` — не видаляти з git

## Cron ADM.TOOLS

Див. `run/cron_admtools.txt` — purge (daily), rotate (daily), reap (*/5), **spot_funds_unfreeze (*/10)**.

## Phase C (опційно пізніше)

- Видалити shadow-only гілки (зараз shadow лише при `ENGINE_MODE=shadow`)
- Collapse `V2_CANARY_*` env на prod (не шкодять у `v2`)
- Архів `run/deploy_v2_phase*.sh`

## Масштабування (наступні кроки без очікування)

| Крок | Дія |
|------|-----|
| Зараз | `next_tick_at`, reconcile 30s, freeze → менше spam LADDER |
| 50+ running | `worker_target_count=3`, моніторинг `signal_queue` depth |
| 300+ | Redis price bus / окремий signal host — `docs/SCALING_ROADMAP.md` |

Deploy: `./run/deploy_phase_b_scaling.sh`
