-- Queue of ladder level buys that could not run (e.g. insufficient free USDT vs min notional).
-- Safe to run once: if column exists, re-run will fail; ignore in that case.

ALTER TABLE `positions`
  ADD COLUMN `ladder_missed_json` TEXT NULL
  COMMENT 'JSON: missed ladder level queue (insufficient funds, catch-up on deposit)'
  AFTER `sub_assigned_qty`;
