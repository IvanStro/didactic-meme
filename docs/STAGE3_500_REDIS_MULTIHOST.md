# Етап 3 — 500+ користувачів, ≤1 с, Redis + multi-VPS

**Версія:** 1.0  
**Дата:** 2026-05-25  
**Статус:** впровадження (без урізання max running у тарифах)

Пов’язано: `docs/SCALING_ROADMAP.md` §5 етап 3, `docs/ARCHITECTURE_V2_EVENT_DRIVEN.md` фаза 8+, `engine_v3/`.

---

## 1. Ціль

| Вимога | Значення |
|--------|----------|
| Користувачі | **500+** зареєстрованих, без штучного cap на running |
| Торгова затримка | **price + signal ≤ 1 с** (p95) |
| Exec lag | **≤ 5–10 с** p95 при піковому навантаженні |
| Доступність | 99.9%, один cron-хост, autoscale workers |

**Принцип:** V2 event-driven (signal_daemon) лишається; MySQL `signal_queue` → **Redis Streams**; exec workers на **окремих VPS**.

---

## 2. Топологія (ціль)

```
                    ┌─────────────────────────────────────┐
                    │  VPS #1 — CONTROL (business-117)     │
                    │  www + API + MySQL primary           │
                    │  ws_price_cache, signal_daemon       │
                    │  cron / watchdog (ЄДИНИЙ)            │
                    │  Redis (або managed Redis)           │
                    └──────────────┬──────────────────────┘
                                   │ Redis + MySQL
              ┌────────────────────┼────────────────────┐
              ▼                    ▼                    ▼
     ┌────────────────┐  ┌────────────────┐  ┌────────────────┐
     │ VPS #2 WORKERS │  │ VPS #3 WORKERS │  │ VPS #4 (opt.)  │
     │ exec_worker×N  │  │ exec_worker×N  │  │ exec_worker×N  │
     │ shards 0–7     │  │ shards 8–15    │  │ overflow       │
     └────────────────┘  └────────────────┘  └────────────────┘
```

| Роль | Хост | Процеси |
|------|------|---------|
| Control | business-117 (існуючий) | API, MySQL, Redis, WS, signal_daemon, **не** масовий exec |
| Worker | Новий VPS #2, #3 | `exec_worker.php` × 8–32 на шард |
| Cron | **лише control** | `STRIM_CRON_HOST` = control FQDN |

**Заборонено:** trading cron / exec workers на двох control-хостах одночасно (дубль ордерів).

---

## 3. SLO (production)

| Метрика | Джерело | Alert |
|---------|---------|-------|
| `price_age_sec` p95 | Redis `strim:prices:*` або WS JSON | > 2 с |
| signal eval latency | `signal_daemon.log` | > 500 ms / symbol batch |
| `signal_queue` depth (Redis) | `health.php` | queued > 500 |
| exec picked → done | `signal_exec_worker.log` | p95 > 10 с |
| MySQL lag replica | monitoring | > 5 с |

---

## 4. Redis — ключі та черги

| Ключ / stream | Призначення |
|---------------|-------------|
| `strim:prices` (HASH) | symbol → `{price,ts}` |
| `strim:signal:s{N}` (STREAM) | черга сигналів, N = `strategy_id % SHARDS` |
| `strim:sq:dedupe:{key}` (STRING) | dedupe TTL |
| `strim:sq:busy:{sid}` (STRING) | 1 job processing per strategy |
| Consumer group | `strim-exec` на кожному stream |

Env (див. `run/infra/stage3.env.example`):

```env
QUEUE_BACKEND=redis          # mysql | redis | dual
REDIS_URL=redis://127.0.0.1:6379
REDIS_SIGNAL_SHARDS=16
REDIS_PRICE_BUS=1
WORKER_SHARD_IDS=0,1,2,3,4,5,6,7   # на worker VPS #2
STRIM_NODE_ROLE=worker             # control | worker
```

---

## 5. Етапи впровадження

### Фаза A — Redis на control ✅ (2026-05-26)

- [x] Redis ADM.TOOLS 512MB + unix socket
- [ ] `composer require predis/predis` на проді
- [ ] `QUEUE_BACKEND=mysql` (без змін) + `run/redis_queue_smoke.php` OK
- [ ] `REDIS_PRICE_BUS=1` — signal_daemon читає ціни з Redis
- [ ] Watchdog + `health.php` → `redis.ok`, `queue_backend`

### Фаза B — Dual-write signal queue ✅

- [x] `QUEUE_BACKEND=dual` — пройдено
- [ ] Порівняння глибини черг 48 год
- [ ] `shadow_compare_report` без регресій

### Фаза C — Redis pop на control ✅ (без VPS)

- [x] `QUEUE_BACKEND=redis`, `SIGNAL_EXEC_WORKERS=4` на business-117
- [ ] Load test: 200 synthetic strategies staging
- [ ] Go: p95 signal ≤ 1 с, queued stable

### Фаза D — Worker VPS #2 ⏸ (відкладено)

- [ ] VPS: PHP 8.4 CLI, SSH deploy key, **без cron trading**
- [ ] `.env` → `STRIM_NODE_ROLE=worker`, `WORKER_SHARD_IDS=0..7`
- [ ] `run/start_exec_workers_sharded.sh` systemd/supervisor
- [ ] Control: `STRIM_EXEC_ON_CONTROL=0` — exec лише на workers

### Фаза E — Worker VPS #3 + autoscale (2 тижні)

- [ ] Shards 8–15 на VPS #3
- [ ] Autoscale: якщо `XLEN strim:signal:s*` > threshold → +4 workers (max cap)
- [ ] MySQL read replica для dashboard API

### Фаза F — 500 users go-live

- [ ] Load test 500 running (staging)
- [ ] Runbook + on-call Telegram
- [ ] Маркетинг

---

## 6. Код (репозиторій)

| Шлях | Опис |
|------|------|
| `engine_v3/queue_config.php` | backend, shards, node role |
| `engine_v3/redis_client.php` | Predis singleton |
| `engine_v3/redis_signal_queue.php` | Streams push/pop/done |
| `engine_v3/redis_price_bus.php` | HASH prices |
| `engine_v2/signal_queue.php` | dispatch mysql/redis/dual |
| `run/redis_queue_smoke.php` | smoke test |
| `run/start_exec_workers_sharded.sh` | worker VPS |
| `run/infra/stage3.env.example` | шаблон env |

---

## 7. Орієнтовна потужність

| Running | Control VPS | Worker VPS | Redis |
|---------|-------------|------------|-------|
| 50 | 4 CPU / 8 GB | — | 256 MB |
| 200 | 4 CPU / 8 GB | 1× 4 CPU, 16 exec | 512 MB |
| 500 | 8 CPU / 16 GB | 2× 8 CPU, 24 exec each | 1 GB |
| 1000+ | 8 CPU | 3× 8 CPU + autoscale | 2 GB cluster |

Binance: один IP на control для WS; workers — outbound orders (той самий IP або whitelist IP pool за ToS).

---

## 8. Rollback

1. `QUEUE_BACKEND=mysql`
2. `STRIM_EXEC_ON_CONTROL=1` + restart exec на control
3. Stop worker VPS exec (`pkill exec_worker`)
4. Redis лишається — не шкодить

Backup перед кожною фазою: `run/V2_ROLLOUT_GATE.md` §1.

---

*Оновлювати checklist після кожної фази. Без зменшення `max_running_strategies` у тарифах — масштаб лише інфраструктурою.*
