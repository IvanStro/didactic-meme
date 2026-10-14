<?php
declare(strict_types=1);
/**
 * One-shot: MAIN/CORE sells + LEGACY activity in pause-exit window.
 * Usage: php run/audit_pause_exit_sells.php
 */
require __DIR__ . '/../www/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

$db = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
$db->set_charset('utf8mb4');
if ($db->connect_error) {
    fwrite(STDERR, "db: {$db->connect_error}\n");
    exit(1);
}

$since = '2026-09-17 12:00:00';

echo "==== MAIN/CORE SELL msgs since {$since} ====\n";
$sql = "
SELECT j.id, j.created_at, j.strategy_id, j.symbol, j.qty, j.price, j.pnl_usdt, j.pnl_pct,
       j.message, j.event_type, j.side, j.level_idx,
       s.status, s.user_id, u.nickname
FROM engine_journal j
LEFT JOIN strategies s ON s.id = j.strategy_id
LEFT JOIN users u ON u.id = s.user_id
WHERE j.created_at >= ?
  AND (
    j.message LIKE '%MAIN SELL%'
    OR j.message LIKE '%SELL CORE%'
    OR j.message LIKE '%MAIN SELL CORE%'
  )
ORDER BY j.id ASC
";
$st = $db->prepare($sql);
$st->bind_param('s', $since);
$st->execute();
$res = $st->get_result();
$n = 0;
while ($r = $res->fetch_assoc()) {
    $n++;
    $msg = preg_replace('/\s+/', ' ', (string)$r['message']);
    echo json_encode([
        'id' => $r['id'],
        'at' => $r['created_at'],
        'sid' => $r['strategy_id'],
        'sym' => $r['symbol'],
        'nick' => $r['nickname'],
        'uid' => $r['user_id'],
        'qty' => $r['qty'],
        'px' => $r['price'],
        'pnl' => $r['pnl_usdt'],
        'pnl_pct' => $r['pnl_pct'],
        'status_now' => $r['status'],
        'msg' => mb_substr($msg, 0, 200),
    ], JSON_UNESCAPED_UNICODE) . "\n";
}
echo "main_sell_count={$n}\n\n";

echo "==== ALL TG-style SELL msgs today (MAIN/SUB/CORE) ====\n";
$sql2 = "
SELECT j.id, j.created_at, j.strategy_id, j.symbol, j.message, u.nickname, s.user_id
FROM engine_journal j
LEFT JOIN strategies s ON s.id = j.strategy_id
LEFT JOIN users u ON u.id = s.user_id
WHERE j.created_at >= '2026-09-18 00:00:00'
  AND j.message LIKE '%SELL%'
  AND (j.message LIKE '%MAIN%' OR j.message LIKE '%CORE%' OR j.message LIKE '%SUB SELL%' OR j.message LIKE '%SUBPOS%')
ORDER BY j.id ASC
";
$r2 = $db->query($sql2);
$n2 = 0;
while ($r2 && ($r = $r2->fetch_assoc())) {
    $n2++;
    $msg = preg_replace('/\s+/', ' ', (string)$r['message']);
    echo $r['created_at'] . " sid={$r['strategy_id']} {$r['symbol']} @{$r['nickname']} | " . mb_substr($msg, 0, 220) . "\n";
}
echo "sell_msgs_today={$n2}\n\n";

echo "==== LEGACY / exit_plan journal since 2026-09-01 ====\n";
$sql3 = "
SELECT j.id, j.created_at, j.strategy_id, j.symbol, j.message, u.nickname
FROM engine_journal j
LEFT JOIN strategies s ON s.id = j.strategy_id
LEFT JOIN users u ON u.id = s.user_id
WHERE j.created_at >= '2026-09-01'
  AND (
    j.message LIKE '%LEGACY%'
    OR j.message LIKE '%exit_plan%'
    OR j.message LIKE '%історичний avg%'
    OR j.message LIKE '%adopt%'
  )
ORDER BY j.id ASC
";
$r3 = $db->query($sql3);
while ($r3 && ($r = $r3->fetch_assoc())) {
    $msg = preg_replace('/\s+/', ' ', (string)$r['message']);
    echo $r['created_at'] . " sid={$r['strategy_id']} {$r['symbol']} @{$r['nickname']} | " . mb_substr($msg, 0, 240) . "\n";
}

echo "\n==== Positions still with adopt_mode / legacy origin ====\n";
$r4 = $db->query("
SELECT s.id, s.symbol, s.status, u.nickname, p.qty, p.avg_price, p.cost_avg, p.adopt_mode, p.origin, p.legacy_qty
FROM positions p
JOIN strategies s ON s.id = p.strategy_id
JOIN users u ON u.id = s.user_id
WHERE (p.adopt_mode IS NOT NULL AND p.adopt_mode <> '')
   OR (p.origin IS NOT NULL AND p.origin <> '' AND p.origin <> 'strim')
   OR (p.legacy_qty IS NOT NULL AND p.legacy_qty > 0)
ORDER BY s.id
");
$n4 = 0;
while ($r4 && ($r = $r4->fetch_assoc())) {
    $n4++;
    echo json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
}
echo "legacy_pos_count={$n4}\n\n";

echo "==== Paused + open qty now (pause-exit candidates) ====\n";
$r5 = $db->query("
SELECT s.id, s.symbol, s.status, u.nickname, p.qty, p.avg_price, p.adopt_mode, p.origin, p.cost_avg
FROM strategies s
JOIN users u ON u.id = s.user_id
JOIN positions p ON p.strategy_id = s.id
WHERE s.status = 'paused' AND p.qty > 1e-12
ORDER BY s.id
");
while ($r5 && ($r = $r5->fetch_assoc())) {
    echo json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
}

echo "\n==== USER_PAUSE / status changes near sells (2026-09-17+) ====\n";
$r6 = $db->query("
SELECT j.id, j.created_at, j.strategy_id, j.symbol, j.message, u.nickname
FROM engine_journal j
LEFT JOIN strategies s ON s.id = j.strategy_id
LEFT JOIN users u ON u.id = s.user_id
WHERE j.created_at >= '{$since}'
  AND (
    j.message LIKE '%USER_PAUSE%'
    OR j.message LIKE '%пауза%'
    OR j.message LIKE '%PAUSE%'
    OR j.event_type LIKE '%PAUSE%'
    OR j.event_type LIKE '%STATUS%'
  )
ORDER BY j.id ASC
LIMIT 80
");
while ($r6 && ($r = $r6->fetch_assoc())) {
    $msg = preg_replace('/\s+/', ' ', (string)$r['message']);
    echo $r['created_at'] . " sid={$r['strategy_id']} {$r['symbol']} @{$r['nickname']} | " . mb_substr($msg, 0, 180) . "\n";
}

echo "DONE\n";
