<?php
declare(strict_types=1);
/**
 * One-shot: злити пильний open sub у сусідній рівень.
 * Usage: php run/absorb_dust_sub.php <strategy_id> <sub_position_id>
 */
require __DIR__ . '/../www/vendor/autoload.php';
require_once __DIR__ . '/../engine/engine_helpers.php';

Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

$sid = (int)($argv[1] ?? 0);
$spId = (int)($argv[2] ?? 0);
if ($sid <= 0 || $spId <= 0) {
    fwrite(STDERR, "Usage: php run/absorb_dust_sub.php <strategy_id> <sub_position_id>\n");
    exit(1);
}

$db = new mysqli(
    (string)($_ENV['DB_HOST'] ?? ''),
    (string)($_ENV['DB_USER'] ?? ''),
    (string)($_ENV['DB_PASS'] ?? ''),
    (string)($_ENV['DB_NAME'] ?? '')
);
if ($db->connect_errno) {
    fwrite(STDERR, "db connect failed\n");
    exit(1);
}
$db->set_charset('utf8mb4');

$st = $db->query("SELECT id, owner_id, symbol FROM strategies WHERE id={$sid} LIMIT 1")->fetch_assoc();
if (!$st) {
    fwrite(STDERR, "strategy not found\n");
    exit(1);
}
$pos = position_pick_row($db, $sid, (int)$st['owner_id']);
if (!$pos) {
    fwrite(STDERR, "position not found\n");
    exit(1);
}
$pid = (int)$pos['id'];

$mark = 0.0;
$wsFile = dirname(__DIR__) . '/www/data/ws_prices.json';
if (is_readable($wsFile)) {
    $j = json_decode((string)file_get_contents($wsFile), true);
    $sym = strtoupper((string)$st['symbol']);
    $mark = (float)($j[$sym]['mid'] ?? $j[$sym]['price'] ?? 0);
}

$db->begin_transaction();
try {
    $res = ladder_absorb_dust_sub($db, $pid, $spId, $mark, 5.0);
    if (empty($res['ok'])) {
        $db->rollback();
        echo json_encode(['ok' => false, 'result' => $res], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
        exit(2);
    }
    $db->commit();
    echo json_encode([
        'ok' => true,
        'symbol' => $st['symbol'],
        'sid' => $sid,
        'pid' => $pid,
        'result' => $res,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
} catch (Throwable $e) {
    $db->rollback();
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
