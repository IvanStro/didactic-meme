-- Унікальність order_id на стратегію (NULL дозволено кілька разів).
-- Перед застосуванням: php run/link_ledger_order_ids.php --all-running
--                       php run/backfill_binance_sell.php для справжніх orphan SUB.

ALTER TABLE trade_ledger
  ADD UNIQUE INDEX uq_trade_ledger_strategy_order (strategy_id, order_id);
