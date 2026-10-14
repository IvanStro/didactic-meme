<?php
declare(strict_types=1);
/**
 * Admin overview: Binance vs DB for all running/paused strategies.
 * Read-only. Outputs compact JSON table for admin panel.
 */

require __DIR__ . '/../www/vendor/autoload.php';

Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

$m = new mysqli(
    (string) ($_ENV['DB_HOST'] ?? ''),
    (string) ($_ENV['DB_USER'] ?? ''),
    (string) ($_ENV['DB_PASS'] ?? ''),
    (string) ($_ENV['DB_NAME'] ?? '')
);
$m->set_charset('utf8mb4');

$phpBin = '/usr/local/php84/bin/php';
if (!is_executable($phpBin)) {
    $phpBin = 'php';
}
$script = __DIR__ . '/reconcile_binance_strategy.php';

$res = $m->query("
    SELECT s.id AS strategy_id, s.user_id, s.symbol, s.status
    FROM strategies s
    WHERE s.status IN ('running', 'paused')
    ORDER BY s.id ASC
");

$rows = [];
$issues = 0;

while ($res && ($st = $res->fetch_assoc())) {
    $sid = (int) ($st['strategy_id'] ?? 0);
    $uid = (int) ($st['user_id'] ?? 0);
    if ($sid <= 0 || $uid <= 0) {
        continue;
    }

    $cmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($script) . ' ' . $uid . ' ' . $sid;
    $raw = shell_exec($cmd . ' 2>/dev/null');
    $data = is_string($raw) ? json_decode($raw, true) : null;

    if (!is_array($data)) {
        $rows[] = [
            'strategy_id' => $sid,
            'user_id' => $uid,
            'symbol' => (string) ($st['symbol'] ?? ''),
            'status' => (string) ($st['status'] ?? ''),
            'ok' => false,
            'error' => 'reconcile_failed',
        ];
        $issues++;
        continue;
    }

    $rec = $data['reconciliation'] ?? [];
    $untracked = $data['untracked_buys_since_start_ts'] ?? [];
    $syncN = (int) ($data['sync_external_buy_count'] ?? 0);
    $myVsDb = (float) ($rec['myTrades_since_vs_db'] ?? 0);
    $dbVsEx = (float) ($rec['db_qty_vs_exchange'] ?? 0);
    $hasIssue = (abs($myVsDb) > 0.01)
        || (abs($dbVsEx) > 0.01)
        || (count($untracked) > 0)
        || ($syncN > 0);

    if ($hasIssue) {
        $issues++;
    }

    $rows[] = [
        'strategy_id' => $sid,
        'user_id' => $uid,
        'symbol' => (string) ($data['symbol'] ?? $st['symbol'] ?? ''),
        'status' => (string) ($st['status'] ?? ''),
        'ok' => !$hasIssue,
        'db_qty' => (float) (($data['db_position']['qty'] ?? 0)),
        'exchange_base' => (float) (($data['exchange']['total_base'] ?? 0)),
        'myTrades_since_vs_db' => $myVsDb,
        'db_qty_vs_exchange' => $dbVsEx,
        'untracked_buys' => count($untracked),
        'sync_external_buy_count' => $syncN,
        'free_usdt' => (float) (($data['exchange']['free_usdt'] ?? 0)),
    ];
}

echo json_encode([
    'checked_at_utc' => gmdate('Y-m-d H:i:s') . ' UTC',
    'strategies' => count($rows),
    'with_issues' => $issues,
    'hint' => 'Для деталей по одній парі: «Звірка Binance — одна стратегія» з параметрами user_id strategy_id.',
    'rows' => $rows,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
