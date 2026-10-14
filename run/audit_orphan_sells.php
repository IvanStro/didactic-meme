<?php
declare(strict_types=1);
/**
 * Audit: Binance SELL vs trade_ledger per strategy (orphan sells).
 *   php run/audit_orphan_sells.php [user_id] [strategy_id]
 *   php run/audit_orphan_sells.php --all-running
 */
require __DIR__ . '/../www/vendor/autoload.php';
require_once __DIR__ . '/../engine_v2/bootstrap.php';
require_once __DIR__ . '/../www/api/_user_keys.php';
require_once __DIR__ . '/../www/api/_binance_utils.php';

Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

$auditOrphanCli = (PHP_SAPI === 'cli' && realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === realpath(__FILE__));

function fetch_my_trades(string $apiKey, string $secret, string $symbol, int $startMs): array
{
    $all = [];
    $fromId = null;
    for ($page = 0; $page < 15; $page++) {
        $params = [
            'symbol' => $symbol,
            'limit' => 1000,
            'timestamp' => (int) (microtime(true) * 1000),
            'recvWindow' => 10000,
        ];
        if ($fromId !== null) {
            $params['fromId'] = $fromId;
        }
        $q = bn_sign_query($params, $secret);
        [$http, $body, $err] = http_json(
            bn_base() . '/api/v3/myTrades?' . $q,
            ['X-MBX-APIKEY: ' . $apiKey],
            'GET'
        );
        if ($err || $http >= 400) {
            return ['error' => "myTrades http=$http err=$err", 'trades' => []];
        }
        $batch = json_decode((string) $body, true);
        if (!is_array($batch) || $batch === []) {
            break;
        }
        foreach ($batch as $t) {
            if ((int) ($t['time'] ?? 0) >= $startMs) {
                $all[] = $t;
            }
        }
        if (count($batch) < 1000) {
            break;
        }
        $fromId = (int) ($batch[count($batch) - 1]['id'] ?? 0) + 1;
    }
    return ['error' => null, 'trades' => $all];
}

function audit_strategy(mysqli $db, int $uid, int $sid, string $symbol): array
{
    [$apiKey, $secret] = user_keys($db, $uid);
    if (!$apiKey || !$secret) {
        return ['sid' => $sid, 'error' => 'no_keys'];
    }

    $startMs = (int) (strtotime('-14 days') * 1000);
    $fetch = fetch_my_trades($apiKey, $secret, $symbol, $startMs);
    if ($fetch['error']) {
        return ['sid' => $sid, 'symbol' => $symbol, 'error' => $fetch['error']];
    }

    $known = [];
    $lr = $db->query("SELECT order_id FROM trade_ledger WHERE strategy_id={$sid} AND order_id IS NOT NULL AND side='SELL'");
    while ($lr && ($row = $lr->fetch_assoc())) {
        $known[(int) $row['order_id']] = true;
    }

  // Group sells by orderId
    $sells = [];
    foreach ($fetch['trades'] as $t) {
        if (($t['isBuyer'] ?? true) === true || ($t['isBuyer'] ?? 1) == 1) {
            continue;
        }
        $oid = (int) ($t['orderId'] ?? 0);
        if ($oid <= 0) {
            continue;
        }
        if (!isset($sells[$oid])) {
            $sells[$oid] = [
                'orderId' => $oid,
                'time_utc' => gmdate('Y-m-d H:i:s', (int) (($t['time'] ?? 0) / 1000)),
                'qty' => 0.0,
                'quote' => 0.0,
                'prices' => [],
            ];
        }
        $q = (float) ($t['qty'] ?? 0);
        $p = (float) ($t['price'] ?? 0);
        $sells[$oid]['qty'] += $q;
        $sells[$oid]['quote'] += $q * $p;
        $sells[$oid]['prices'][] = $p;
    }

    $orphans = [];
    $ledgered = [];
    foreach ($sells as $oid => $s) {
        $avg = $s['qty'] > 0 ? $s['quote'] / $s['qty'] : 0;
        $row = [
            'orderId' => $oid,
            'time_utc' => $s['time_utc'],
            'qty' => round($s['qty'], 8),
            'price' => round($avg, 4),
            'in_ledger' => isset($known[$oid]),
        ];
        if ($row['in_ledger']) {
            $ledgered[] = $row;
        } else {
            $orphans[] = $row;
        }
    }

    // Подвійний SELL_SUB на рівні без BUY_L між продажами (реальний dup, не цикл buy→sell→buy)
    $dupLevels = [];
    $dr = $db->query("
        SELECT t1.level_idx AS level_idx,
               COUNT(DISTINCT t1.id) AS c,
               GROUP_CONCAT(DISTINCT t1.order_id ORDER BY t1.id) AS oids,
               GROUP_CONCAT(t1.created_at ORDER BY t1.id) AS times
        FROM trade_ledger t1
        INNER JOIN trade_ledger t2
          ON t2.strategy_id = t1.strategy_id
         AND t2.level_idx = t1.level_idx
         AND t2.event_type = 'SELL_SUB'
         AND t2.id <> t1.id
         AND t2.created_at > t1.created_at
         AND t2.created_at >= (NOW() - INTERVAL 14 DAY)
        WHERE t1.strategy_id = {$sid}
          AND t1.event_type = 'SELL_SUB'
          AND t1.created_at >= (NOW() - INTERVAL 14 DAY)
          AND NOT EXISTS (
            SELECT 1 FROM trade_ledger tb
            WHERE tb.strategy_id = t1.strategy_id
              AND tb.level_idx = t1.level_idx
              AND tb.event_type = 'BUY_L'
              AND tb.created_at > t1.created_at
              AND tb.created_at < t2.created_at
          )
        GROUP BY t1.level_idx
    ");
    while ($dr && ($x = $dr->fetch_assoc())) {
        $dupLevels[] = $x;
    }

    return [
        'sid' => $sid,
        'uid' => $uid,
        'symbol' => $symbol,
        'binance_sells' => count($sells),
        'ledger_sells' => count($ledgered),
        'orphan_sells' => $orphans,
        'dup_levels_ledger' => $dupLevels,
    ];
}

if (!$auditOrphanCli) {
    return;
}

$allRunning = in_array('--all-running', $argv, true);
$uid = (int) ($argv[1] ?? 0);
$sid = (int) ($argv[2] ?? 0);

$db = db();
$strategies = [];
if ($allRunning) {
    $r = $db->query("SELECT s.id, s.user_id, s.symbol, u.nickname, u.email FROM strategies s JOIN users u ON u.id=s.user_id WHERE s.status='running' ORDER BY s.id");
    while ($r && ($row = $r->fetch_assoc())) {
        $strategies[] = $row;
    }
} elseif ($sid > 0) {
    $r = $db->query("SELECT s.id, s.user_id, s.symbol, u.nickname, u.email FROM strategies s JOIN users u ON u.id=s.user_id WHERE s.id={$sid} LIMIT 1");
    if ($r && ($row = $r->fetch_assoc())) {
        $strategies[] = $row;
    }
} elseif ($uid > 0) {
    $r = $db->query("SELECT s.id, s.user_id, s.symbol, u.nickname, u.email FROM strategies s JOIN users u ON u.id=s.user_id WHERE s.user_id={$uid} ORDER BY s.id");
    while ($r && ($row = $r->fetch_assoc())) {
        $strategies[] = $row;
    }
} else {
    // find Irka
    $r = $db->query("SELECT s.id, s.user_id, s.symbol, u.nickname, u.email FROM strategies s JOIN users u ON u.id=s.user_id WHERE u.nickname LIKE '%Irka%' OR u.email LIKE '%irka%' OR u.email LIKE '%Irka%'");
    while ($r && ($row = $r->fetch_assoc())) {
        $strategies[] = $row;
    }
}

foreach ($strategies as $st) {
    $sid = (int) $st['id'];
    $uid = (int) $st['user_id'];
    $sym = (string) $st['symbol'];
    echo "\n=== {$st['nickname']} ({$st['email']}) sid={$sid} {$sym} ===\n";
    $rep = audit_strategy($db, $uid, $sid, $sym);
    echo json_encode($rep, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
}
