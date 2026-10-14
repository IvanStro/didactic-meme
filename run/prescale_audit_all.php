<?php
declare(strict_types=1);
/**
 * Повний аудит перед масштабуванням (усі користувачі / стратегії).
 *   php run/prescale_audit_all.php
 *   php run/prescale_audit_all.php --json > /tmp/prescale.json
 */
$root = dirname(__DIR__);
require $root . '/www/vendor/autoload.php';
require_once $root . '/engine_v2/bootstrap.php';
require_once __DIR__ . '/audit_orphan_sells.php';

Dotenv\Dotenv::createImmutable($root)->safeLoad();

$asJson = in_array('--json', $argv, true);
$db = db();

$report = [
    'generated_at' => gmdate('c'),
    'users' => [],
    'strategies' => [],
    'issues' => [],
    'summary' => [],
];

$usersRes = $db->query(
    "SELECT u.id, u.nickname, u.email, u.is_admin,
            (SELECT COUNT(*) FROM strategies s WHERE s.user_id=u.id) AS strategies_total,
            (SELECT COUNT(*) FROM strategies s WHERE s.user_id=u.id AND s.status='running') AS strategies_running
     FROM users u
     ORDER BY u.id"
);
while ($usersRes && ($u = $usersRes->fetch_assoc())) {
    $uid = (int)$u['id'];
    $hasKeys = false;
    $keysQ = $db->query(
        "SELECT api_key_enc, api_secret_enc FROM users WHERE id={$uid} LIMIT 1"
    );
    if ($keysQ && ($kr = $keysQ->fetch_assoc())) {
        $hasKeys = trim((string)($kr['api_key_enc'] ?? '')) !== ''
            && trim((string)($kr['api_secret_enc'] ?? '')) !== '';
    }
    $billingOk = true;
    if (function_exists('billing_user_has_access')) {
        require_once $root . '/www/api/_billing_access.php';
        $billingOk = billing_user_has_access($db, $uid);
    }
    $report['users'][] = [
        'user_id' => $uid,
        'nickname' => (string)$u['nickname'],
        'email' => (string)$u['email'],
        'is_admin' => (int)($u['is_admin'] ?? 0),
        'strategies_total' => (int)$u['strategies_total'],
        'strategies_running' => (int)$u['strategies_running'],
        'has_binance_keys' => $hasKeys,
        'billing_active' => $billingOk,
    ];
    if ((int)$u['strategies_running'] > 0 && !$hasKeys) {
        $report['issues'][] = ['type' => 'running_no_keys', 'user_id' => $uid, 'nickname' => $u['nickname']];
    }
    if ((int)$u['strategies_running'] > 0 && !$billingOk) {
        $report['issues'][] = ['type' => 'running_no_billing', 'user_id' => $uid, 'nickname' => $u['nickname']];
    }
}

$strRes = $db->query(
    "SELECT s.id sid, s.user_id uid, s.symbol, s.status, u.nickname
     FROM strategies s JOIN users u ON u.id=s.user_id
     ORDER BY s.status DESC, s.id"
);
while ($strRes && ($st = $strRes->fetch_assoc())) {
    $sid = (int)$st['sid'];
    $uid = (int)$st['uid'];
    $sym = (string)$st['symbol'];
    $row = [
        'strategy_id' => $sid,
        'user_id' => $uid,
        'nickname' => (string)$st['nickname'],
        'symbol' => $sym,
        'status' => (string)$st['status'],
        'orphan_sells' => 0,
        'dup_sells' => 0,
        'queue_pending' => 0,
        'audit_error' => null,
    ];
    if ($st['status'] === 'running') {
        $rep = audit_strategy($db, $uid, $sid, $sym);
        if (!empty($rep['error'])) {
            $row['audit_error'] = (string)$rep['error'];
            $report['issues'][] = ['type' => 'audit_error', 'strategy_id' => $sid, 'error' => $row['audit_error']];
        } else {
            $row['orphan_sells'] = count($rep['orphan_sells'] ?? []);
            $row['dup_sells'] = count($rep['dup_levels_ledger'] ?? []);
            if ($row['orphan_sells'] > 0) {
                $report['issues'][] = ['type' => 'orphan_sell', 'strategy_id' => $sid, 'count' => $row['orphan_sells']];
            }
            if ($row['dup_sells'] > 0) {
                $report['issues'][] = ['type' => 'dup_sell', 'strategy_id' => $sid, 'count' => $row['dup_sells'], 'levels' => $rep['dup_levels_ledger']];
            }
        }
        $q = $db->query("SELECT COUNT(*) c FROM signal_queue WHERE strategy_id={$sid} AND status IN ('queued','processing')");
        $row['queue_pending'] = (int)(($q ? $q->fetch_assoc() : [])['c'] ?? 0);
        if ($row['queue_pending'] > 50) {
            $report['issues'][] = ['type' => 'queue_backlog', 'strategy_id' => $sid, 'count' => $row['queue_pending']];
        }
    }
    $report['strategies'][] = $row;
}

$report['summary'] = [
    'users_total' => count($report['users']),
    'strategies_total' => count($report['strategies']),
    'strategies_running' => count(array_filter($report['strategies'], static fn($s) => $s['status'] === 'running')),
    'issues_total' => count($report['issues']),
    'orphan_issues' => count(array_filter($report['issues'], static fn($i) => $i['type'] === 'orphan_sell')),
    'dup_issues' => count(array_filter($report['issues'], static fn($i) => $i['type'] === 'dup_sell')),
];

if ($asJson) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    exit(count($report['issues']) > 0 ? 1 : 0);
}

echo "=== StrIM pre-scale audit " . $report['generated_at'] . " ===\n\n";
echo "Users: {$report['summary']['users_total']}, strategies: {$report['summary']['strategies_total']}, running: {$report['summary']['strategies_running']}\n";
echo "Issues: {$report['summary']['issues_total']} (orphan={$report['summary']['orphan_issues']} dup={$report['summary']['dup_issues']})\n\n";

foreach ($report['strategies'] as $s) {
    if ($s['status'] !== 'running') {
        continue;
    }
    $flags = [];
    if ($s['orphan_sells'] > 0) {
        $flags[] = 'orphan=' . $s['orphan_sells'];
    }
    if ($s['dup_sells'] > 0) {
        $flags[] = 'dupL=' . $s['dup_sells'];
    }
    if ($s['queue_pending'] > 0) {
        $flags[] = 'queue=' . $s['queue_pending'];
    }
    if ($s['audit_error']) {
        $flags[] = 'err=' . $s['audit_error'];
    }
    $line = sprintf(
        'sid=%d %-12s %-10s %s',
        $s['strategy_id'],
        $s['nickname'],
        $s['symbol'],
        $flags === [] ? 'OK' : implode(' ', $flags)
    );
    echo $line . "\n";
}

if ($report['issues'] !== []) {
    echo "\n--- Issues detail ---\n";
    foreach ($report['issues'] as $i) {
        echo json_encode($i, JSON_UNESCAPED_UNICODE) . "\n";
    }
    exit(1);
}

echo "\nAll checks passed.\n";
exit(0);
