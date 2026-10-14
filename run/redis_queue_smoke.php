<?php
declare(strict_types=1);
/**
 * Smoke: Redis signal queue (локально або на проді з REDIS_URL).
 *   QUEUE_BACKEND=redis php run/redis_queue_smoke.php
 */

$root = dirname(__DIR__);
require $root . '/www/vendor/autoload.php';
require_once $root . '/engine_v2/bootstrap.php';
require_once $root . '/engine_v3/queue_config.php';
require_once $root . '/engine_v3/redis_client.php';
require_once $root . '/engine_v2/signal_queue.php';

Dotenv\Dotenv::createImmutable($root)->safeLoad();

echo 'queue_backend=' . queue_backend() . PHP_EOL;
echo 'redis_available=' . (redis_available() ? 'yes' : 'no') . PHP_EOL;

if (!redis_available()) {
    fwrite(STDERR, "FAIL: Redis not reachable. Set REDIS_URL and run: composer install -d www\n");
    exit(1);
}

$testSid = 999999;
$testUid = 1;
$sym = 'BTCUSDT';
$sig = 'RECONCILE_TICK';

$id = signal_queue_push($testUid, $testSid, $sym, $sig, 50000.0, ['smoke' => true]);
echo "push result={$id}\n";

$workerId = 'smoke-' . getmypid();
$row = signal_queue_pop($workerId);
if (!$row) {
    fwrite(STDERR, "FAIL: pop returned null\n");
    exit(1);
}

echo 'popped id=' . ($row['id'] ?? '') . ' sid=' . ($row['strategy_id'] ?? '') . ' signal=' . ($row['signal_name'] ?? '') . PHP_EOL;
signal_queue_mark_done($row['id'], (int)($row['strategy_id'] ?? 0));

$counts = signal_queue_counts();
echo 'counts=' . json_encode($counts) . PHP_EOL;
echo "OK redis_queue_smoke\n";
