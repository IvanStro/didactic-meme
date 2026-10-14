<?php
declare(strict_types=1);
/**
 * Deep audit user/strategy: timeline, funds depletion, min price, missed ladder levels.
 * Usage: php run/audit_user_strategy.php <user_id> <strategy_id>
 */

require __DIR__ . '/../www/vendor/autoload.php';
require __DIR__ . '/../www/api/_user_keys.php';
require __DIR__ . '/../www/api/_binance_utils.php';
require __DIR__ . '/../www/api/_filters_utils.php';
require __DIR__ . '/../engine/engine_logic_ladder_cascade.php';

Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

$uid = (int) ($argv[1] ?? 15);
$sid = (int) ($argv[2] ?? 16);

$m = new mysqli(
    (string) ($_ENV['DB_HOST'] ?? ''),
    (string) ($_ENV['DB_USER'] ?? ''),
    (string) ($_ENV['DB_PASS'] ?? ''),
    (string) ($_ENV['DB_NAME'] ?? '')
);
$m->set_charset('utf8mb4');

$st = $m->query("SELECT * FROM strategies WHERE id={$sid} AND user_id={$uid} LIMIT 1")->fetch_assoc();
$pos = $m->query("SELECT * FROM positions WHERE strategy_id={$sid} LIMIT 1")->fetch_assoc();
if (!$st || !$pos) {
    fwrite(STDERR, "strategy/position not found\n");
    exit(1);
}

$symbol = (string) $st['symbol'];
$fba = (float) $st['fba'];
$base = (float) ($pos['base_price'] ?? 0);
if ($base <= 0) {
    $base = (float) ($pos['avg_price'] ?? 0);
}
$pid = (int) $pos['id'];
$nextPtr = (int) ($pos['next_ptr'] ?? 1);

$levels = ladder_cascade_load_strategy_levels($m, $sid);
$triggers = ladder_cascade_build_triggers($m, $pid, $base, $levels);
$ladderMode = ladder_cascade_resolve_mode($m);

// Journal
$events = [];
$q = $m->query("SELECT id, created_at, event_type, level_idx, side, qty, price, message FROM engine_journal WHERE strategy_id={$sid} ORDER BY id ASC");
while ($q && ($r = $q->fetch_assoc())) {
    $events[] = $r;
}

// Find funds-depleted anchor: first BUY_L_SKIP or BUY0 SKIP with norm=0 / free USDT
$fundsDepletedAt = null;
$fundsDepletedEvent = null;
foreach ($events as $e) {
    $t = (string) $e['event_type'];
    $msg = (string) ($e['message'] ?? '');
    if ($t === 'BUY_L_SKIP' || ($t === 'BUY0' && (stripos($msg, 'SKIP') !== false || stripos($msg, 'недостатньо') !== false))) {
        $fundsDepletedAt = $e['created_at'];
        $fundsDepletedEvent = ['id' => $e['id'], 'type' => $t, 'lvl' => $e['level_idx'], 'at' => $e['created_at']];
        break;
    }
}

// Last successful bot buy before depletion
$lastSuccessBuy = null;
foreach (array_reverse($events) as $e) {
    if ($fundsDepletedAt && strcmp((string) $e['created_at'], (string) $fundsDepletedAt) >= 0) {
        continue;
    }
    $t = (string) $e['event_type'];
    $msg = (string) ($e['message'] ?? '');
    if (($t === 'BUY0' || $t === 'BUY_L') && stripos($msg, '✅') !== false) {
        $lastSuccessBuy = $e;
        break;
    }
}

// Opened levels from sub_positions + journal BUY_L
$openedLevels = [];
$sq = $m->query("SELECT level_idx, qty, buy_price, closed FROM sub_positions WHERE position_id={$pid} ORDER BY level_idx");
while ($sq && ($r = $sq->fetch_assoc())) {
    $openedLevels[(int) $r['level_idx']] = $r;
}
foreach ($events as $e) {
    if (($e['event_type'] ?? '') === 'BUY_L' && stripos((string) ($e['message'] ?? ''), '✅') !== false) {
        $openedLevels[(int) $e['level_idx']] = ['from' => 'journal', 'at' => $e['created_at']];
    }
}

// Missed queue
$missedQueue = [];
$rawMissed = (string) ($pos['ladder_missed_json'] ?? '');
if ($rawMissed !== '') {
    $missedQueue = json_decode($rawMissed, true) ?: [];
}

// SKIP levels with counts
$skipByLevel = [];
$skipTriggers = [];
foreach ($events as $e) {
    if (($e['event_type'] ?? '') !== 'BUY_L_SKIP') {
        continue;
    }
    $lv = (int) $e['level_idx'];
    $skipByLevel[$lv] = ($skipByLevel[$lv] ?? 0) + 1;
    if (preg_match('/тригер:\s*~([0-9.]+)/u', (string) $e['message'], $mm)) {
        $skipTriggers[$lv] = (float) $mm[1];
    }
}

// SYNC_EXTERNAL count
$syncCount = 0;
foreach ($events as $e) {
    if (($e['event_type'] ?? '') === 'SYNC_EXTERNAL_BUY') {
        $syncCount++;
    }
}

// Klines since funds depleted
[$apiKey, $secret] = user_keys($m, $uid);
$priceStats = null;
$sinceTs = $fundsDepletedAt ? strtotime((string) $fundsDepletedAt) : null;
if ($sinceTs && $apiKey) {
    $sinceMs = $sinceTs * 1000;
    $endMs = (int) (microtime(true) * 1000);
    $low = PHP_FLOAT_MAX;
    $high = 0.0;
    $lowAt = 0;
    $cursor = $sinceMs;
    while ($cursor < $endMs) {
        $url = 'https://api.binance.com/api/v3/klines?' . http_build_query([
            'symbol' => $symbol,
            'interval' => '5m',
            'startTime' => $cursor,
            'endTime' => $endMs,
            'limit' => 1000,
        ]);
        $raw = @file_get_contents($url);
        $kl = $raw ? json_decode($raw, true) : null;
        if (!is_array($kl) || $kl === []) {
            break;
        }
        foreach ($kl as $c) {
            $l = (float) ($c[3] ?? 0);
            $h = (float) ($c[2] ?? 0);
            $t = (int) ($c[0] ?? 0);
            if ($l < $low) {
                $low = $l;
                $lowAt = $t;
            }
            if ($h > $high) {
                $high = $h;
            }
        }
        $lastOpen = (int) ($kl[count($kl) - 1][0] ?? $cursor);
        if ($lastOpen <= $cursor) {
            break;
        }
        $cursor = $lastOpen + 1;
    }
    $curPx = exi_get_price($symbol, $apiKey, $secret);
    $priceStats = [
        'since' => $fundsDepletedAt,
        'low' => $low === PHP_FLOAT_MAX ? null : $low,
        'low_at' => $lowAt ? gmdate('Y-m-d H:i:s', (int) floor($lowAt / 1000)) . ' UTC' : null,
        'high' => $high > 0 ? $high : null,
        'current' => $curPx,
    ];
}

// Map: at lowest price which levels should fire (price <= trigger) and status
$low = $priceStats['low'] ?? null;
$levelAnalysis = [];
if ($low !== null) {
    foreach ($triggers as $lv => $trPx) {
        if ($lv < 1) {
            continue;
        }
        $wouldTrigger = $low <= (float) $trPx;
        $status = 'not_reached';
        if ($wouldTrigger) {
            if (isset($openedLevels[$lv]) && (empty($openedLevels[$lv]['closed']) || (int) ($openedLevels[$lv]['closed'] ?? 0) === 0)) {
                $status = 'opened';
            } elseif ($lv >= $nextPtr) {
                $status = 'missed_no_usdt';
            } else {
                $status = 'passed_ptr_or_closed';
            }
        }
        $mult = (float) ($levels[$lv]['mult'] ?? 1);
        $levelAnalysis[] = [
            'level' => $lv,
            'trigger' => round((float) $trPx, 8),
            'trigger_skip_msg' => $skipTriggers[$lv] ?? null,
            'drop_pct' => $levels[$lv]['drop'] ?? null,
            'quote_usdt' => round($fba * $mult, 2),
            'would_at_low' => $wouldTrigger,
            'status' => $status,
            'skip_count' => $skipByLevel[$lv] ?? 0,
        ];
    }
}

// Compact timeline (all significant)
$timeline = [];
foreach ($events as $e) {
    $t = (string) $e['event_type'];
    if (!preg_match('/^(BUY0|BUY_L|BUY_L_SKIP|SYNC_EXTERNAL|CORE_SELL|SUB_SELL|SELL_SUB|MAIN_SELL)/', $t)) {
        continue;
    }
    $timeline[] = [
        'at' => $e['created_at'],
        'type' => $t,
        'lvl' => $e['level_idx'],
        'head' => mb_substr(preg_replace('/\s+/', ' ', strip_tags((string) $e['message'])), 0, 100),
    ];
}

$out = [
    'user_id' => $uid,
    'strategy_id' => $sid,
    'symbol' => $symbol,
    'ladder_mode' => $ladderMode,
    'fba' => $fba,
    'base_price' => $base,
    'avg_price' => (float) ($pos['avg_price'] ?? 0),
    'position_qty' => (float) ($pos['qty'] ?? 0),
    'next_ptr' => $nextPtr,
    'journal_total' => count($events),
    'funds_depleted' => $fundsDepletedEvent,
    'last_success_buy_before' => $lastSuccessBuy ? [
        'at' => $lastSuccessBuy['created_at'],
        'type' => $lastSuccessBuy['event_type'],
        'lvl' => $lastSuccessBuy['level_idx'],
        'head' => mb_substr((string) $lastSuccessBuy['message'], 0, 120),
    ] : null,
    'opened_levels' => array_keys($openedLevels),
    'missed_queue' => $missedQueue,
    'sync_external_buy_count' => $syncCount,
    'skip_by_level' => $skipByLevel,
    'price_since_depletion' => $priceStats,
    'level_analysis' => $levelAnalysis,
    'timeline' => $timeline,
];

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
