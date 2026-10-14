<?php
declare(strict_types=1);
/**
 * Повний аудит системи (прод/локально).
 *   php run/full_system_audit.php
 *   php run/full_system_audit.php --json
 */
$root = dirname(__DIR__);
require $root . '/www/vendor/autoload.php';
require_once $root . '/engine_v2/bootstrap.php';
require_once $root . '/engine_v2/engine_mode.php';
require_once $root . '/www/api/_ws_price.php';
require_once $root . '/www/api/_user_keys.php';
require_once $root . '/www/api/_binance_utils.php';
require_once $root . '/www/api/_filters_utils.php';
require_once $root . '/www/api/_billing_access.php';
Dotenv\Dotenv::createImmutable($root)->safeLoad();

$asJson = in_array('--json', $argv, true);
$db = db();
$now = time();
date_default_timezone_set('Europe/Kyiv');

$out = [
    'generated_at' => date('c'),
    'hostname' => gethostname() ?: 'unknown',
    'sections' => [],
];

// --- 1. Health snapshot ---
$health = [
    'engine_mode' => function_exists('engine_mode') ? engine_mode() : '?',
    'signal_daemon' => function_exists('engine_signal_daemon_enabled') ? engine_signal_daemon_enabled() : null,
    'signal_exec' => function_exists('engine_signal_exec_enabled') ? engine_signal_exec_enabled() : null,
];
$health['prices'] = null; // filled after symbols known

$jq = $db->query("SELECT status, COUNT(*) c FROM job_queue GROUP BY status");
$jobQ = [];
while ($jq && ($r = $jq->fetch_assoc())) {
    $jobQ[$r['status']] = (int)$r['c'];
}
$health['job_queue'] = $jobQ;

$sq = $db->query("SELECT status, COUNT(*) c FROM signal_queue GROUP BY status");
$sigQ = [];
while ($sq && ($r = $sq->fetch_assoc())) {
    $sigQ[$r['status']] = (int)$r['c'];
}
$health['signal_queue'] = $sigQ;

$sqFail = $db->query(
    "SELECT id, strategy_id, signal_name, status, updated_at
     FROM signal_queue WHERE status='failed' ORDER BY id DESC LIMIT 15"
);
$health['signal_queue_failed_recent'] = [];
while ($sqFail && ($r = $sqFail->fetch_assoc())) {
    $health['signal_queue_failed_recent'][] = $r;
}

// --- 2. Price fidelity: WS vs Binance REST per running symbol ---
$priceAudit = [];
$running = $db->query(
    "SELECT DISTINCT UPPER(symbol) sym FROM strategies WHERE status='running' AND UPPER(symbol)<>'TEMPLATE'"
);
$symbols = [];
while ($running && ($r = $running->fetch_assoc())) {
    $symbols[] = (string)$r['sym'];
}
$health['prices'] = ws_prices_health_summary($symbols);
$out['sections']['infrastructure'] = $health;

foreach ($symbols as $sym) {
    $wsMid = ws_ticker_price($sym);
    $wsEntry = ws_prices_load_all()[$sym] ?? null;
    $wsAge = null;
    if (is_array($wsEntry) && isset($wsEntry['ts'])) {
        $wsAge = $now - (int)$wsEntry['ts'];
    }
    $restMid = 0.0;
    $restErr = null;
    try {
        $restMid = (float)exi_get_price($sym);
    } catch (Throwable $e) {
        $restErr = $e->getMessage();
    }
    $diffPct = null;
    $diffAbs = null;
    if ($wsMid > 0 && $restMid > 0) {
        $diffAbs = abs($wsMid - $restMid);
        $diffPct = ($diffAbs / $restMid) * 100.0;
    }
    $ok = $restMid > 0 && $diffPct !== null && $diffPct < 0.05 && ($wsAge === null || $wsAge <= 8);
    $priceAudit[] = [
        'symbol' => $sym,
        'ws_mid' => round($wsMid, 8),
        'rest_mid' => round($restMid, 8),
        'diff_abs' => $diffAbs !== null ? round($diffAbs, 8) : null,
        'diff_pct' => $diffPct !== null ? round($diffPct, 4) : null,
        'ws_age_sec' => $wsAge,
        'ok' => $ok,
        'rest_error' => $restErr,
    ];
    if (!$ok) {
        $out['sections']['price_issues'][] = end($priceAudit);
    }
}

$out['sections']['price_fidelity'] = $priceAudit;

// --- 3. Running strategies matrix ---
$strategies = [];
$stRes = $db->query(
    "SELECT s.id, s.user_id, u.nickname, s.symbol, s.status, s.last_tick_at, s.tp_whole, s.cb_whole,
            p.qty, p.avg_price, p.base_price, p.last_peak, p.state, p.invested_usdt
     FROM strategies s
     JOIN users u ON u.id = s.user_id
     LEFT JOIN positions p ON p.strategy_id = s.id
     WHERE s.status='running' AND UPPER(s.symbol)<>'TEMPLATE'
     ORDER BY s.user_id, s.id"
);
while ($stRes && ($st = $stRes->fetch_assoc())) {
    $sym = strtoupper((string)$st['symbol']);
    $mid = ws_ticker_price($sym);
    $tickLag = !empty($st['last_tick_at']) ? ($now - strtotime((string)$st['last_tick_at'])) : null;
    $inv = (float)($st['invested_usdt'] ?? 0);
    $qty = (float)($st['qty'] ?? 0);
    $upnl = ($mid > 0 && $qty > 0) ? ($qty * $mid - $inv) : null;
    $basis = (float)($st['base_price'] ?: $st['avg_price'] ?: 0);
    $tp = (float)($st['tp_whole'] ?? 0);
    $tpPrice = $basis > 0 && $tp > 0 ? $basis * (1 + $tp / 100) : null;
    $strategies[] = [
        'sid' => (int)$st['id'],
        'uid' => (int)$st['user_id'],
        'user' => (string)$st['nickname'],
        'symbol' => $sym,
        'qty' => $qty,
        'state' => (string)($st['state'] ?? ''),
        'last_peak' => (float)($st['last_peak'] ?? 0),
        'tick_lag_sec' => $tickLag,
        'last_tick_at' => $st['last_tick_at'],
        'mark' => round($mid, 6),
        'tp_target' => $tpPrice ? round($tpPrice, 6) : null,
        'above_tp' => $tpPrice && $mid > 0 ? ($mid >= $tpPrice) : null,
        'uPnL_est' => $upnl !== null ? round($upnl, 2) : null,
        'v2_exec' => function_exists('engine_strategy_uses_signal_exec')
            ? engine_strategy_uses_signal_exec((int)$st['id']) : null,
    ];
}
$out['sections']['running_strategies'] = $strategies;

// --- 4. DB sanity ---
$dbSanity = [];
$tables = ['users', 'strategies', 'positions', 'sub_positions', 'signal_queue', 'job_queue',
    'trade_ledger', 'engine_journal', 'web_analytics_events', 'referral_rewards'];
foreach ($tables as $t) {
    $chk = $db->query("SHOW TABLES LIKE '{$t}'");
    if (!$chk || $chk->num_rows === 0) {
        $dbSanity[$t] = 'missing';
        continue;
    }
    $c = $db->query("SELECT COUNT(*) c FROM `{$t}`")->fetch_assoc();
    $dbSanity[$t] = (int)($c['c'] ?? 0);
}
$orphanPos = $db->query(
    "SELECT COUNT(*) c FROM positions p LEFT JOIN strategies s ON s.id=p.strategy_id WHERE s.id IS NULL"
)->fetch_assoc();
$dbSanity['orphan_positions'] = (int)($orphanPos['c'] ?? 0);

$dupPos = $db->query(
    "SELECT strategy_id, COUNT(*) c FROM positions GROUP BY strategy_id HAVING c > 1 LIMIT 5"
);
$dbSanity['duplicate_position_rows'] = [];
while ($dupPos && ($r = $dupPos->fetch_assoc())) {
    $dbSanity['duplicate_position_rows'][] = $r;
}

$out['sections']['database'] = $dbSanity;

// --- 5. Ledger dup sells (14d, all running) ---
$dupLedger = $db->query(
    "SELECT strategy_id, level_idx, COUNT(*) c
     FROM trade_ledger
     WHERE event_type='SELL_SUB' AND created_at >= NOW() - INTERVAL 14 DAY
     GROUP BY strategy_id, level_idx
     HAVING c > 1
     LIMIT 20"
);
$out['sections']['ledger_dup_sell_sub'] = [];
while ($dupLedger && ($r = $dupLedger->fetch_assoc())) {
    $out['sections']['ledger_dup_sell_sub'][] = $r;
}

// --- 6. Web analytics (14d) ---
$wa = ['table_exists' => false];
$wt = $db->query("SHOW TABLES LIKE 'web_analytics_events'");
if ($wt && $wt->num_rows > 0) {
    $wa['table_exists'] = true;
    $w = $db->query(
        "SELECT
            COUNT(*) events,
            COUNT(DISTINCT visitor_id) visitors,
            SUM(CASE WHEN event_name='page_view' THEN 1 ELSE 0 END) pv,
            SUM(CASE WHEN gclid<>'' THEN 1 ELSE 0 END) ads,
            SUM(CASE WHEN DATE(event_at)=CURDATE() THEN 1 ELSE 0 END) today
         FROM web_analytics_events
         WHERE event_at >= NOW() - INTERVAL 14 DAY"
    )->fetch_assoc();
    $wa['14d'] = $w;
}
$out['sections']['web_analytics'] = $wa;

// --- 7. Users summary ---
$users = [];
$uRes = $db->query(
    "SELECT u.id, u.nickname, u.billing_status, u.is_admin,
            (SELECT COUNT(*) FROM strategies WHERE user_id=u.id AND status='running') run_cnt
     FROM users u ORDER BY run_cnt DESC, u.id"
);
while ($uRes && ($u = $uRes->fetch_assoc())) {
    $uid = (int)$u['id'];
    $users[] = [
        'id' => $uid,
        'nickname' => (string)$u['nickname'],
        'billing' => (string)$u['billing_status'],
        'running' => (int)$u['run_cnt'],
        'keys' => (bool)user_keys($db, $uid)[0],
        'billing_ok' => billing_user_has_access($db, $uid),
    ];
}
$out['sections']['users'] = $users;

// --- 8. Signal queue stuck ---
$stuck = $db->query(
    "SELECT id, strategy_id, signal_name, status, TIMESTAMPDIFF(SECOND, updated_at, NOW()) age_sec
     FROM signal_queue
     WHERE status IN ('queued','processing') AND updated_at < NOW() - INTERVAL 5 MINUTE
     ORDER BY id LIMIT 20"
);
$out['sections']['signal_queue_stuck'] = [];
while ($stuck && ($r = $stuck->fetch_assoc())) {
    $out['sections']['signal_queue_stuck'][] = $r;
}

// --- Summary scores ---
$issues = [];
if (($health['prices']['all_fresh'] ?? false) !== true) {
    $issues[] = 'ws_prices_not_all_fresh';
}
foreach ($priceAudit as $p) {
    if (!$p['ok']) {
        $issues[] = 'price_mismatch_' . $p['symbol'];
    }
}
if ((int)($sigQ['failed'] ?? 0) > 0) {
    $issues[] = 'signal_queue_has_failed';
}
if (count($out['sections']['signal_queue_stuck']) > 0) {
    $issues[] = 'signal_queue_stuck_jobs';
}
if (count($out['sections']['ledger_dup_sell_sub']) > 0) {
    $issues[] = 'ledger_dup_sell_sub';
}
if ((int)($dbSanity['orphan_positions'] ?? 0) > 0) {
    $issues[] = 'orphan_position_rows';
}

$out['summary'] = [
    'status' => count($issues) === 0 ? 'ok' : (count($issues) <= 2 ? 'warn' : 'critical'),
    'issues' => $issues,
    'running_strategies' => count($strategies),
    'users_with_running' => count(array_filter($users, static fn($u) => $u['running'] > 0)),
];

if ($asJson) {
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(count($issues) > 0 ? 1 : 0);
}

// Human report
echo "=== STRIM FULL SYSTEM AUDIT ===\n";
echo 'Generated: ' . $out['generated_at'] . ' (' . date('T') . ")\n";
echo 'Host: ' . $out['hostname'] . "\n";
echo 'Overall: ' . strtoupper($out['summary']['status']) . "\n";
if ($issues) {
    echo "Issues: " . implode(', ', $issues) . "\n";
}
echo "\n--- Price fidelity (WS vs Binance REST) ---\n";
printf("%-10s %12s %12s %8s %6s %6s %s\n", 'Symbol', 'WS', 'REST', 'diff%', 'wsAge', 'OK', '');
foreach ($priceAudit as $p) {
    printf(
        "%-10s %12.6f %12.6f %8.4f %6s %6s %s\n",
        $p['symbol'],
        $p['ws_mid'],
        $p['rest_mid'],
        $p['diff_pct'] ?? -1,
        $p['ws_age_sec'] ?? '-',
        $p['ok'] ? 'YES' : 'NO',
        $p['rest_error'] ?? ''
    );
}
echo "\n--- Running strategies (" . count($strategies) . ") ---\n";
foreach ($strategies as $s) {
    echo sprintf(
        "sid=%d %-10s %-8s qty=%.6f tick_lag=%ss peak=%.4f mark=%.4f TP=%.4f above_tp=%s uPnL~%s v2=%s\n",
        $s['sid'],
        $s['symbol'],
        $s['user'],
        $s['qty'],
        $s['tick_lag_sec'] ?? '?',
        $s['last_peak'],
        $s['mark'],
        $s['tp_target'] ?? 0,
        $s['above_tp'] ? 'Y' : 'N',
        $s['uPnL_est'] ?? '-',
        $s['v2_exec'] ? 'Y' : 'N'
    );
}
echo "\n--- Signal queue ---\n";
print_r($sigQ);
if ($health['signal_queue_failed_recent']) {
    echo "Recent failed:\n";
    foreach ($health['signal_queue_failed_recent'] as $f) {
        echo "  #{$f['id']} sid={$f['strategy_id']} {$f['signal_name']} {$f['updated_at']}\n";
    }
}
echo "\n--- Ledger dup SELL_SUB (14d): " . count($out['sections']['ledger_dup_sell_sub']) . "\n";
if ($out['sections']['ledger_dup_sell_sub']) {
    print_r($out['sections']['ledger_dup_sell_sub']);
}
echo "\n--- Web analytics (14d) ---\n";
print_r($wa);
echo "\n--- DB row counts ---\n";
print_r($dbSanity);
exit(count($issues) > 0 ? 1 : 0);
