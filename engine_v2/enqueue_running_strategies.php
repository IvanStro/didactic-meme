<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/queue_push.php';
require_once __DIR__ . '/scaling_schedule.php';
require_once __DIR__ . '/engine_mode.php';
require_once dirname(__DIR__) . '/www/api/_billing_access.php';

/**
 * Enqueue running strategies + paused with open position (exit wind-down).
 * Paused without qty stay out of the queue (no empty ticks).
 */
const ENQUEUE_BATCH_SIZE = 500;

$batch = ENQUEUE_BATCH_SIZE;

$db = db();
$useDue = scaling_has_next_tick_column($db);

$check = $db->prepare("
    SELECT id FROM job_queue
    WHERE user_id = ?
      AND strategy_id = ?
      AND symbol = ?
      AND status IN ('queued','processing')
    LIMIT 1
");

$dueSql = $useDue ? ' AND (s.next_tick_at IS NULL OR s.next_tick_at <= NOW())' : '';

// Access gate: percent wallet (balance/trial) OR legacy paid_until/trial_until.
// Paused wind-down always enqueues if not blocked (exhausted users must still sell).
if (billing_percent_mode_active($db)) {
    $accessSql = '
      AND (u.billing_status IS NULL OR u.billing_status <> \'blocked\')
      AND (
        s.status = \'paused\'
        OR COALESCE(u.balance_usdt, 0) > 1e-8
        OR u.wallet_activated_at IS NULL
        OR (u.paid_until IS NOT NULL AND u.paid_until > NOW())
        OR (u.trial_until IS NOT NULL AND u.trial_until > NOW())
      )';
} else {
    $accessSql = '
      AND (u.billing_status IS NULL OR u.billing_status <> \'blocked\')
      AND (
        s.status = \'paused\'
        OR (u.paid_until IS NOT NULL AND u.paid_until > NOW())
        OR (u.trial_until IS NOT NULL AND u.trial_until > NOW())
      )';
}

// PAUSE_EXIT_TICKS: paused + open qty keep RECONCILE for normal StrIM cycles only.
// Never wake LEGACY adopt modes (exit_plan/hybrid/adopt_*) — grid basis ≠ cost_avg and can dump bags.
$sel = $db->prepare('
    SELECT s.id, s.user_id, s.symbol, s.status
    FROM strategies s
    INNER JOIN users u ON u.id = s.owner_id
    WHERE (
        s.status = \'running\'
        OR (
          s.status = \'paused\'
          AND EXISTS (
            SELECT 1 FROM positions p
            WHERE p.strategy_id = s.id
              AND p.qty > 1e-12
              AND (p.adopt_mode IS NULL OR p.adopt_mode = \'\')
              AND (p.origin IS NULL OR p.origin = \'\' OR p.origin = \'strim\')
            LIMIT 1
          )
        )
      )
      AND s.id > ?
      ' . $dueSql . '
      ' . $accessSql . '
    ORDER BY s.id ASC
    LIMIT ?
');

$count = 0;
$lastId = 0;
while (true) {
    $sel->bind_param('ii', $lastId, $batch);
    $sel->execute();
    $res = $sel->get_result();
    if (!$res) {
        break;
    }
    $n = 0;
    $maxSeen = $lastId;
    while ($row = $res->fetch_assoc()) {
        $n++;
        $strategyId = (int)$row['id'];
        $userId = (int)$row['user_id'];
        $symbol = (string)$row['symbol'];
        $maxSeen = max($maxSeen, $strategyId);

        if ($strategyId <= 0 || $userId <= 0 || $symbol === '') {
            continue;
        }

        if ($symbol === 'TEMPLATE' || str_contains($symbol, 'TEMPLATE')) {
            continue;
        }

        if (engine_strategy_skip_v1_poll($strategyId)) {
            scaling_maybe_enqueue_signal_exec_reconcile($db, $userId, $strategyId, $symbol, $check);
            continue;
        }

        $check->bind_param('iis', $userId, $strategyId, $symbol);
        $check->execute();
        $res2 = $check->get_result();
        $exists = $res2 ? $res2->fetch_assoc() : null;

        if (!$exists) {
            queue_push('PROCESS_STRATEGY', $userId, $strategyId, $symbol);
            $count++;
        }
    }
    if ($n < $batch) {
        break;
    }
    $lastId = $maxSeen;
}

echo "queued={$count}\n";
