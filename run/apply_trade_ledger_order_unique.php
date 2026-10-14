<?php
declare(strict_types=1);
/**
 * Унікальний індекс trade_ledger(strategy_id, order_id). Безпечно повторювати.
 *   php run/apply_trade_ledger_order_unique.php [--dry-run]
 */
$root = dirname(__DIR__);
require $root . '/www/vendor/autoload.php';
Dotenv\Dotenv::createImmutable($root)->safeLoad();

$dryRun = in_array('--dry-run', $argv, true);

$m = new mysqli(
    (string)($_ENV['DB_HOST'] ?? ''),
    (string)($_ENV['DB_USER'] ?? ''),
    (string)($_ENV['DB_PASS'] ?? ''),
    (string)($_ENV['DB_NAME'] ?? '')
);
if ($m->connect_errno) {
    fwrite(STDERR, 'db: ' . $m->connect_error . PHP_EOL);
    exit(1);
}
$m->set_charset('utf8mb4');

function idx_exists(mysqli $m, string $table, string $idx): bool
{
    $t = $m->real_escape_string($table);
    $i = $m->real_escape_string($idx);
    $res = $m->query("SHOW INDEX FROM `{$t}` WHERE Key_name = '{$i}'");
    return (bool)($res && $res->num_rows > 0);
}

if (idx_exists($m, 'trade_ledger', 'uq_trade_ledger_strategy_order')) {
    echo "index uq_trade_ledger_strategy_order already exists\n";
    exit(0);
}

$dup = $m->query("
    SELECT strategy_id, order_id, COUNT(*) c
    FROM trade_ledger
    WHERE order_id IS NOT NULL AND order_id > 0
    GROUP BY strategy_id, order_id
    HAVING c > 1
    LIMIT 20
");
if ($dup && $dup->num_rows > 0) {
    fwrite(STDERR, "Cannot add unique index: duplicate (strategy_id, order_id) rows:\n");
    while ($row = $dup->fetch_assoc()) {
        fwrite(STDERR, json_encode($row) . "\n");
    }
    exit(1);
}

$sql = file_get_contents(__DIR__ . '/migrations/20260519_trade_ledger_order_id_unique.sql');
if ($dryRun) {
    echo "dry-run: would run:\n{$sql}\n";
    exit(0);
}

if (!$m->query($sql)) {
    fwrite(STDERR, 'migration failed: ' . $m->error . PHP_EOL);
    exit(1);
}
echo "ok: uq_trade_ledger_strategy_order created\n";
