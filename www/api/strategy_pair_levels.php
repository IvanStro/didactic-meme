<?php
declare(strict_types=1);

/**
 * Trigger prices for Pair Details chart — same geometry as engine handle_buy_ladder + saas_trade_targets.
 */

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../auth/_session_user.php';
require_once __DIR__ . '/_strategy_levels_ui.php';

use Dotenv\Dotenv;

header('Content-Type: application/json; charset=utf-8');

$dotenv = Dotenv::createImmutable(dirname(__DIR__, 2));
$dotenv->safeLoad();

$mysqli = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
if ($mysqli->connect_errno) {
    echo json_encode(['ok' => false, 'error' => 'db'], JSON_UNESCAPED_UNICODE);
    exit;
}
$mysqli->set_charset('utf8mb4');

$uid = current_user_id($mysqli, 86400);
if ($uid <= 0) {
    echo json_encode(['ok' => false, 'error' => 'unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

$sid = isset($_GET['strategy_id']) ? (int)$_GET['strategy_id'] : 0;
if ($sid <= 0) {
    echo json_encode(['ok' => false, 'error' => 'strategy_id required'], JSON_UNESCAPED_UNICODE);
    exit;
}

$master_id = 0;
$rm = $mysqli->query("SELECT v FROM app_settings WHERE k='master_strategy_id' LIMIT 1");
if ($rm && ($m = $rm->fetch_assoc())) {
    $master_id = (int)$m['v'];
}

$chk = $mysqli->query("SELECT id FROM strategies WHERE id=$sid AND owner_id=$uid LIMIT 1");
if (!$chk || !$chk->fetch_assoc()) {
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pr = null;
$pq = $mysqli->query(
    "SELECT id, qty, avg_price, base_price, last_buy, COALESCE(next_ptr,1) AS next_ptr
     FROM positions WHERE strategy_id=$sid ORDER BY id DESC LIMIT 1"
);
if ($pq && ($pr = $pq->fetch_assoc())) {
    // ok
}

$pid = $pr ? (int)$pr['id'] : 0;
$qty = $pr ? (float)$pr['qty'] : 0.0;
$avg = $pr ? (float)$pr['avg_price'] : 0.0;
$base = $pr ? (float)($pr['base_price'] ?? 0) : 0.0;
$lastBuy = $pr ? (float)($pr['last_buy'] ?? 0) : 0.0;

// BUY0 anchor for L1–L4 (engine parity).
$mainCore = $base > 0.0 ? $base : $avg;
if ($mainCore <= 0.0 && $lastBuy > 0.0) {
    $mainCore = $lastBuy;
}

// Ті самі %, що в рушії (тільки strategy_levels / глобальні 0). Martingale — лише fallback.
$levelDefs = strategy_levels_rows_like_engine($mysqli, $sid);
if (!count($levelDefs)) {
    $levelDefs = strategy_levels_ui_rows($mysqli, $sid, $master_id);
}
if (!count($levelDefs)) {
    echo json_encode([
        'ok' => true,
        'strategy_id' => $sid,
        'items' => [],
        'hint' => 'no_levels',
        'main_core_anchor' => null,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$byIdx = [];
foreach ($levelDefs as $row) {
    $byIdx[(int)$row['idx']] = $row;
}
ksort($byIdx);

$computedBuy = [];
$items = [];
$prevSubAnchor = $avg > 0.0 ? $avg : $mainCore;

foreach ($byIdx as $idx => $lv) {
    if ($idx < 1) {
        continue;
    }
    $drop = (float)$lv['drop'];
    $anchor = 0.0;

    if ($idx <= 4) {
        $anchor = $mainCore > 0.0 ? $mainCore : 0.0;
    } elseif ($idx === 5) {
        $anchor = $avg > 0.0 ? $avg : 0.0;
    } else {
        $prev = $idx - 1;
        $dbPx = 0.0;
        if ($pid > 0) {
            $rr = $mysqli->query(
                "SELECT buy_price FROM sub_positions WHERE position_id=$pid AND level_idx=$prev AND closed=0 ORDER BY id DESC LIMIT 1"
            );
            if ($rr && ($r2 = $rr->fetch_assoc())) {
                $dbPx = (float)$r2['buy_price'];
            }
        }
        if ($dbPx > 0.0) {
            $anchor = $dbPx;
        } elseif (isset($computedBuy[$prev]) && $computedBuy[$prev] > 0.0) {
            $anchor = $computedBuy[$prev];
        } else {
            $anchor = $prevSubAnchor > 0.0 ? $prevSubAnchor : 0.0;
        }
    }

    if ($anchor <= 0.0) {
        continue;
    }

    $buy = round($anchor * (1.0 - $drop / 100.0), 8);
    $computedBuy[$idx] = $buy;
    if ($idx >= 5) {
        $prevSubAnchor = $buy;
    }

    $items[] = [
        'level_idx' => $idx,
        'buy_price' => $buy,
        'drop_pct' => $drop,
    ];
}

echo json_encode([
    'ok' => true,
    'strategy_id' => $sid,
    'position_id' => $pid > 0 ? $pid : null,
    'qty' => $qty,
    'base_price' => $base > 0 ? round($base, 8) : null,
    'avg_price' => $avg > 0 ? round($avg, 8) : null,
    'main_core_anchor' => $mainCore > 0 ? round($mainCore, 8) : null,
    'items' => $items,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
