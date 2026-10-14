<?php
declare(strict_types=1);
/**
 *   php run/reap_signal_dedupe.php [strategy_id] [signal_name]
 * Clear orphan Redis dedupe / busy for a strategy+signal.
 */

$root = dirname(__DIR__);
require $root . '/www/vendor/autoload.php';
require_once $root . '/engine_v2/bootstrap.php';
require_once $root . '/engine_v3/redis_signal_queue.php';

Dotenv\Dotenv::createImmutable($root)->safeLoad();

$sid = isset($argv[1]) ? (int)$argv[1] : 0;
$signal = isset($argv[2]) ? trim((string)$argv[2]) : '';

if ($sid <= 0 || $signal === '') {
    fwrite(STDERR, "Usage: php run/reap_signal_dedupe.php <strategy_id> <signal_name>\n");
    exit(1);
}

redis_signal_reap_orphan_dedupe($sid, $signal);

$r = redis_client();
if (!$r) {
    echo "redis unavailable\n";
    exit(1);
}

$dedupe = signal_queue_dedupe_key($sid, $signal, 0.0);
$key = 'strim:sq:dedupe:' . $dedupe;
if ($r->exists($key)) {
    $r->del($key);
    echo "cleared dedupe {$key}\n";
} else {
    echo "dedupe absent\n";
}
$r->del('strim:sq:busy:' . $sid);
echo "ok sid={$sid} signal={$signal}\n";
