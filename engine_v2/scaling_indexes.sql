-- LuckyDrop / engine_v2 — індекси під масштаб (cron enqueue + вибірки).
-- Застосуйте один раз на проді; якщо індекс уже є — MySQL поверне помилку duplicate — ігноруйте або DROP спочатку.

-- enqueue_running_strategies: WHERE status='running' AND id > ? ORDER BY id
CREATE INDEX idx_strategies_status_id ON strategies (status, id);

-- enqueue due: status + next_tick_at (етап 2, run/migrations/20260519_add_strategies_next_tick_at.sql)
-- CREATE INDEX idx_strategies_due ON strategies (status, next_tick_at, id);

-- fair queue: processing count per user
-- CREATE INDEX idx_job_queue_user_status ON job_queue (user_id, status);

-- (опційно) швидкий фільтр стратегій користувача в адмінці / звітах
-- CREATE INDEX idx_strategies_owner_status ON strategies (owner_id, status);
