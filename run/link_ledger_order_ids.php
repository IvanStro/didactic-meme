<?php
declare(strict_types=1);
/**
 * Прив'язати Binance order_id до існуючих trade_ledger без order_id (qty/time match).
 *   php run/link_ledger_order_ids.php [--all-running] [strategy_id] [--dry-run]
 */
require __DIR__ . '/../www/vendor/autoload.php';
require_once __DIR__ . '/../engine_v2/bootstrap.php';
require_once __DIR__ . '/../www/api/_user_keys.php';
require_once __DIR__ . '/../www/api/_binance_utils.php';
require_once __DIR__ . '/audit_orphan_sells.php';

Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

$dryRun = in_array('--dry-run', $argv, true);
$allRunning = in_array('--all-running', $argv, true);
$sidArg = 0;
foreach ($argv as $a) {
    if (is_numeric($a)) {
        $sidArg = (int) $a;
    }
}

$db = db();

$strategies = [];
if ($allRunning) {
    $r = $db->query("SELECT id, user_id, symbol FROM strategies WHERE status='running' ORDER BY id");
    while ($r && ($row = $r->fetch_assoc())) {
        $strategies[] = $row;
    }
} elseif ($sidArg > 0) {
    $r = $db->query("SELECT id, user_id, symbol FROM strategies WHERE id={$sidArg} LIMIT 1");
    if ($r && ($row = $r->fetch_assoc())) {
        $strategies[] = $row;
    }
} else {
    fwrite(STDERR, "Usage: php run/link_ledger_order_ids.php [--all-running] [strategy_id] [--dry-run]\n");
    exit(1);
}

$linked = 0;
$skipped = 0;

foreach ($strategies as $st) {
    $sid = (int) $st['id'];
    $uid = (int) $st['user_id'];
    $sym = (string) $st['symbol'];
    $rep = audit_strategy($db, $uid, $sid, $sym);
    if (!empty($rep['error'])) {
        echo "sid={$sid}: error {$rep['error']}\n";
        continue;
    }
    foreach ($rep['orphan_sells'] as $orph) {
        $oid = (int) ($orph['orderId'] ?? 0);
        $qty = (float) ($orph['qty'] ?? 0);
        $price = (float) ($orph['price'] ?? 0);
        $tUtc = (string) ($orph['time_utc'] ?? '');
        if ($oid <= 0 || $qty <= 0 || $tUtc === '') {
            $skipped++;
            continue;
        }

        $tEsc = $db->real_escape_string($tUtc);
        // Binance time — UTC; created_at у БД часто EEST (+3). Вікно ±6 год.
        $lr = $db->query("
            SELECT id, qty, price, created_at, event_type, level_idx
            FROM trade_ledger
            WHERE strategy_id={$sid}
              AND side='SELL'
              AND (order_id IS NULL OR order_id=0)
              AND ABS(qty - {$qty}) < 0.000001
              AND created_at BETWEEN ('{$tEsc}' - INTERVAL 6 HOUR) AND ('{$tEsc}' + INTERVAL 6 HOUR)
            ORDER BY ABS(TIMESTAMPDIFF(SECOND, created_at, DATE_ADD('{$tEsc}', INTERVAL 3 HOUR))) ASC
            LIMIT 1
        ");
        $row = $lr ? $lr->fetch_assoc() : null;
        if (!$row) {
            echo "sid={$sid} orphan oid={$oid} qty={$qty} — no ledger match\n";
            $skipped++;
            continue;
        }
        $lid = (int) $row['id'];
        echo "sid={$sid} link ledger={$lid} {$row['event_type']} → order_id={$oid} (qty={$qty})\n";
        if (!$dryRun) {
            $db->query("UPDATE trade_ledger SET order_id={$oid} WHERE id={$lid} AND (order_id IS NULL OR order_id=0) LIMIT 1");
            if ($db->affected_rows > 0) {
                $linked++;
            }
        } else {
            $linked++;
        }
    }
}

echo json_encode(['linked' => $linked, 'skipped' => $skipped, 'dry_run' => $dryRun], JSON_PRETTY_PRINT) . "\n";
