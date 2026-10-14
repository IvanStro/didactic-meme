<?php
declare(strict_types=1);

/**
 * Resolve strategy id for authenticated trading endpoints.
 * Prefer explicit strategy_id/sid in body; else newest running strategy for user+symbol.
 *
 * @param array<string,mixed> $body
 */
function resolve_trading_strategy_id(mysqli $m, int $uid, string $symbol, array $body): int
{
  $symEsc = $m->real_escape_string($symbol);
  $sid = (int)($body['strategy_id'] ?? $body['sid'] ?? 0);
  if ($sid > 0) {
    $st = $m->query("SELECT id FROM strategies WHERE id=$sid AND owner_id=$uid LIMIT 1");
    if ($st && ($row = $st->fetch_assoc())) {
      return (int)$row['id'];
    }
    throw new RuntimeException('strategy not found or not owned');
  }
  $st = $m->query(
    "SELECT id FROM strategies WHERE owner_id=$uid AND symbol='$symEsc' AND status='running' ORDER BY id DESC LIMIT 1"
  );
  if ($st && ($row = $st->fetch_assoc())) {
    return (int)$row['id'];
  }
  throw new RuntimeException('no active strategy for this user/symbol');
}
