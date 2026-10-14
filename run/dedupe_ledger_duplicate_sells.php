<?php
declare(strict_types=1);
/**
 * Видалити точні дублікати SELL у trade_ledger (залишити MIN(id)).
 *   php run/dedupe_ledger_duplicate_sells.php [--dry-run] [user_id]
 */
require __DIR__ . '/../www/vendor/autoload.php';

Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

$dryRun = in_array('--dry-run', $argv, true);
$uid = 0;
foreach ($argv as $a) {
    if (ctype_digit((string) $a)) {
        $uid = (int) $a;
    }
}

$db = new mysqli(
    (string) ($_ENV['DB_HOST'] ?? ''),
    (string) ($_ENV['DB_USER'] ?? ''),
    (string) ($_ENV['DB_PASS'] ?? ''),
    (string) ($_ENV['DB_NAME'] ?? '')
);
$db->set_charset('utf8mb4');

$uidFilter = $uid > 0 ? "AND t1.user_id = {$uid}" : '';

$q = $db->query("
    SELECT t1.id AS dup_id, t2.id AS keep_id, t1.user_id, t1.strategy_id, t1.event_type,
           t1.order_id, t1.qty, t1.price, t1.pnl_usdt, t1.created_at
    FROM trade_ledger t1
    INNER JOIN trade_ledger t2
      ON t2.strategy_id = t1.strategy_id
     AND t2.event_type = t1.event_type
     AND COALESCE(t2.order_id, 0) = COALESCE(t1.order_id, 0)
     AND t2.created_at = t1.created_at
     AND ROUND(COALESCE(t2.price, 0), 12) = ROUND(COALESCE(t1.price, 0), 12)
     AND ROUND(COALESCE(t2.qty, 0), 12) = ROUND(COALESCE(t1.qty, 0), 12)
     AND t2.id < t1.id
    WHERE t1.event_type IN ('SELL_SUB', 'SELL_MAIN')
      AND t1.side = 'SELL'
      {$uidFilter}
    ORDER BY t1.id
");

$ids = [];
while ($q && ($row = $q->fetch_assoc())) {
    $ids[] = (int) $row['dup_id'];
    echo json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";
}

echo 'duplicates=' . count($ids) . ' dry_run=' . ($dryRun ? '1' : '0') . "\n";

if ($ids === [] || $dryRun) {
    exit(0);
}

$idList = implode(',', $ids);
$db->query("DELETE FROM trade_ledger WHERE id IN ({$idList})");
echo 'deleted=' . $db->affected_rows . "\n";
