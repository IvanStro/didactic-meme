<?php
declare(strict_types=1);

/**
 * Smoke: pause/start API + symbol_index + journal.
 * Usage: php run/verify_strategy_pause.php [uid] [sid]
 */

require __DIR__ . '/../www/vendor/autoload.php';
require_once __DIR__ . '/../www/api/_strategy_status.php';

Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

$uid = (int)($argv[1] ?? 0);
$sid = (int)($argv[2] ?? 0);
if ($uid <= 0 || $sid <= 0) {
    fwrite(STDERR, "usage: php run/verify_strategy_pause.php UID SID\n");
    exit(1);
}

$m = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
$m->set_charset('utf8mb4');

$row = strategy_resolve_owned($m, $uid, '', $sid);
if (!$row) {
    echo "FAIL strategy not found uid=$uid sid=$sid\n";
    exit(1);
}

$symbol = (string)$row['symbol'];
$orig = (string)$row['status'];
echo "strategy sid=$sid symbol=$symbol status=$orig\n";

$pause = strategy_apply_status($m, $uid, $sid, 'pause');
echo 'pause: ' . json_encode($pause, JSON_UNESCAPED_UNICODE) . "\n";
if (!$pause['ok'] || ($pause['status'] ?? '') !== 'paused') {
    echo "FAIL pause\n";
    exit(1);
}
$st = $m->query("SELECT status FROM strategies WHERE id=$sid")->fetch_assoc();
if (($st['status'] ?? '') !== 'paused') {
    echo "FAIL status not paused in DB\n";
    exit(1);
}

require_once __DIR__ . '/../engine/signal/symbol_index.php';
if ($symbol !== 'TEMPLATE' && !str_contains($symbol, 'TEMPLATE')) {
    $idx = symbol_index_get_strategy_ids($symbol);
    if (!in_array($sid, $idx, true)) {
        echo "FAIL sid not in symbol_index after pause (exits need index)\n";
        exit(1);
    }
    echo "OK symbol_index contains sid after pause\n";
} else {
    echo "SKIP symbol_index check for TEMPLATE\n";
}

$start = strategy_apply_status($m, $uid, $sid, 'start');
echo 'start: ' . json_encode($start, JSON_UNESCAPED_UNICODE) . "\n";
if (!$start['ok'] || ($start['status'] ?? '') !== 'running') {
    echo "FAIL start\n";
    exit(1);
}

$j = $m->query(
    "SELECT event_type, message FROM engine_journal
     WHERE strategy_id=$sid AND event_type IN ('USER_PAUSE','USER_START')
     ORDER BY id DESC LIMIT 4"
);
echo "recent journal:\n";
while ($j && ($r = $j->fetch_assoc())) {
    echo '  ' . $r['event_type'] . ': ' . $r['message'] . "\n";
}

// restore original if was running before test
if ($orig === 'running') {
    strategy_apply_status($m, $uid, $sid, 'start');
} elseif ($orig === 'paused') {
    strategy_apply_status($m, $uid, $sid, 'pause');
}

echo "OK verify_strategy_pause\n";
