<?php
declare(strict_types=1);

/**
 * Repair orphan L6+ sub_positions created by stale closed-sub anchor (parallel mode bug).
 *
 * Type A (safe auto-repair): open L>=6 without open L(N-1) AND no open subs at levels 1..(N-2).
 *   Typical after CORE_SELL + new BUY0 cycle when L6 fired from old closed L5 anchor.
 *
 * Type B (report only): open L>=6 without open L(N-1) but lower ladder subs still open
 *   (e.g. L5 sold, L6 kept) — may be valid; never auto-closed.
 *
 * Usage:
 *   php run/repair_ladder_stale_anchor.php              # dry-run all
 *   php run/repair_ladder_stale_anchor.php --apply      # fix Type A only
 *   php run/repair_ladder_stale_anchor.php --apply --sid=13
 */

$root = dirname(__DIR__);
require $root . '/www/vendor/autoload.php';
Dotenv\Dotenv::createImmutable($root)->safeLoad();

$db = new mysqli(
    $_ENV['DB_HOST'],
    $_ENV['DB_USER'],
    $_ENV['DB_PASS'],
    $_ENV['DB_NAME']
);
if ($db->connect_error) {
    fwrite(STDERR, "DB connect failed\n");
    exit(1);
}
$db->set_charset('utf8mb4');

require_once $root . '/engine/engine_helpers.php';

$apply = in_array('--apply', $argv, true);
$onlySid = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--sid=')) {
        $onlySid = (int)substr($arg, 6);
    }
}

$sidFilter = $onlySid !== null && $onlySid > 0 ? ' AND p.strategy_id=' . (int)$onlySid : '';

$sql = "
    SELECT sp.id AS sub_id, sp.position_id, sp.level_idx, sp.qty, sp.buy_price,
           p.strategy_id, p.qty AS pos_qty, p.sub_assigned_qty, p.next_ptr, p.base_price,
           s.symbol, s.status, u.email
    FROM sub_positions sp
    INNER JOIN positions p ON p.id = sp.position_id
    INNER JOIN strategies s ON s.id = p.strategy_id
    LEFT JOIN users u ON u.id = s.owner_id
    WHERE sp.closed = 0 AND sp.level_idx >= 6
      AND NOT EXISTS (
          SELECT 1 FROM sub_positions prev
          WHERE prev.position_id = sp.position_id
            AND prev.level_idx = sp.level_idx - 1
            AND prev.closed = 0
      )
    $sidFilter
    ORDER BY sp.id ASC
";

$res = $db->query($sql);
if (!$res) {
    fwrite(STDERR, "Query failed: {$db->error}\n");
    exit(1);
}

$typeA = [];
$typeB = [];

while ($row = $res->fetch_assoc()) {
    $pid = (int)$row['position_id'];
    $lvl = (int)$row['level_idx'];
    $lowerOpen = 0;
    if ($lvl > 6) {
        $qLo = $db->query(
            "SELECT COUNT(*) AS c FROM sub_positions " .
            "WHERE position_id=$pid AND closed=0 AND level_idx >= 1 AND level_idx <= " . ($lvl - 2)
        );
        $lowerOpen = ($qLo && ($rLo = $qLo->fetch_assoc())) ? (int)($rLo['c'] ?? 0) : 0;
    } elseif ($lvl === 6) {
        $qLo = $db->query(
            "SELECT COUNT(*) AS c FROM sub_positions " .
            "WHERE position_id=$pid AND closed=0 AND level_idx BETWEEN 1 AND 4"
        );
        $lowerOpen = ($qLo && ($rLo = $qLo->fetch_assoc())) ? (int)($rLo['c'] ?? 0) : 0;
    }

    if ($lowerOpen === 0) {
        $typeA[] = $row;
    } else {
        $typeB[] = $row;
    }
}

echo ($apply ? 'APPLY' : 'DRY-RUN') . " ladder stale-anchor repair\n";
echo 'Type A (auto-repair candidates): ' . count($typeA) . "\n";
echo 'Type B (report only): ' . count($typeB) . "\n\n";

foreach ($typeB as $row) {
    printf(
        "  [B] sid=%s %s sub_id=%s L%d qty=%s email=%s — lower open subs exist, skip\n",
        (string)$row['strategy_id'],
        (string)$row['symbol'],
        (string)$row['sub_id'],
        (int)$row['level_idx'],
        (string)$row['qty'],
        (string)($row['email'] ?? '')
    );
}

$fixed = 0;
foreach ($typeA as $row) {
    $subId = (int)$row['sub_id'];
    $pid = (int)$row['position_id'];
    $sid = (int)$row['strategy_id'];
    $symbol = (string)$row['symbol'];
    $lvl = (int)$row['level_idx'];
    $subQty = (float)$row['qty'];

    printf(
        "  [A] sid=%s %s sub_id=%s L%d qty=%s email=%s\n",
        (string)$sid,
        $symbol,
        (string)$subId,
        $lvl,
        (string)$row['qty'],
        (string)($row['email'] ?? '')
    );

    if (!$apply) {
        continue;
    }

    $db->begin_transaction();
    try {
        $db->query("UPDATE sub_positions SET closed=1, updated_at=NOW() WHERE id=$subId AND closed=0");
        if ($db->affected_rows < 1) {
            throw new RuntimeException("sub $subId already closed");
        }

        $subQtySql = number_format($subQty, 18, '.', '');
        $db->query(
            "UPDATE positions SET " .
            "sub_assigned_qty = GREATEST(0, sub_assigned_qty - $subQtySql), " .
            "updated_at = NOW() " .
            "WHERE id=$pid"
        );

        $mx = $db->query("SELECT MAX(level_idx) AS mx FROM sub_positions WHERE position_id=$pid AND closed=0");
        $mxv = ($mx && ($r = $mx->fetch_assoc())) ? (int)($r['mx'] ?? 0) : 0;
        if ($mxv > 0) {
            $lb = ladder_open_sub_buy_price($db, $pid, $mxv);
            $np = $mxv + 1;
            if ($lb > 0) {
                $lbSql = number_format($lb, 18, '.', '');
                $db->query("UPDATE positions SET next_ptr=$np, last_buy=$lbSql WHERE id=$pid");
            } else {
                $db->query("UPDATE positions SET next_ptr=$np WHERE id=$pid");
            }
        } else {
            $posQty = (float)($row['pos_qty'] ?? 0);
            $np = $posQty > 0 ? 1 : 1;
            $db->query("UPDATE positions SET next_ptr=$np, sub_assigned_qty=0 WHERE id=$pid");
        }

        $symEsc = $db->real_escape_string($symbol);
        $msg = sprintf(
            'ℹ️ %s repair stale L%d anchor: sub #%d closed, qty %.8f merged to core (parallel anchor bug fix).',
            $symbol,
            $lvl,
            $subId,
            $subQty
        );
        $msgEsc = $db->real_escape_string($msg);
        $db->query(
            "INSERT INTO engine_journal (created_at, strategy_id, symbol, event_type, level_idx, message) " .
            "VALUES (NOW(), $sid, '$symEsc', 'INFO', $lvl, '$msgEsc')"
        );

        $db->commit();
        $fixed++;
        echo "    -> fixed\n";
    } catch (Throwable $e) {
        $db->rollback();
        echo '    -> ERROR: ' . $e->getMessage() . "\n";
    }
}

echo "\nDone. fixed=$fixed apply=" . ($apply ? 'yes' : 'no') . "\n";
