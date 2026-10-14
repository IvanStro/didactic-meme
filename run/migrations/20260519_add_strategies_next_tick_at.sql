-- Етап 2: розклад тіків (не сканувати всі running кожні 5 с).
ALTER TABLE strategies
  ADD COLUMN next_tick_at DATETIME NULL DEFAULT NULL AFTER last_tick_at;

CREATE INDEX idx_strategies_due ON strategies (status, next_tick_at, id);
