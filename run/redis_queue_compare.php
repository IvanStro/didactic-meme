<?php
declare(strict_types=1);
/**
 * Порівняння глибини черг MySQL vs Redis (фаза B dual).
 *   php run/redis_queue_compare.php
 */

$root = dirname(__DIR__);
require $root . '/www/vendor/autoload.php';
require_once $root . '/engine_v2/bootstrap.php';
require_once $root . '/engine_v2/signal_queue.php';
require_once $root . '/engine_v3/queue_config.php';
require_once $root . '/engine_v3/redis_signal_queue.php';

Dotenv\Dotenv::createImmutable($root)->safeLoad();

echo date('c') . ' backend=' . queue_backend() . PHP_EOL;

$mysql = signal_queue_counts();
unset($mysql['done'], $mysql['failed']);
$redis = redis_signal_queue_counts();

echo 'mysql_signal_queue ' . json_encode($mysql, JSON_UNESCAPED_UNICODE) . PHP_EOL;
echo 'redis_streams      ' . json_encode($redis, JSON_UNESCAPED_UNICODE) . PHP_EOL;

$mq = (int)($mysql['queued'] ?? 0) + (int)($mysql['processing'] ?? 0);
$rq = (int)($redis['queued'] ?? 0) + (int)($redis['processing'] ?? 0);
$diff = abs($mq - $rq);
echo "active_mysql={$mq} active_redis={$rq} diff={$diff}\n";

if (queue_backend() === 'dual' && $diff > 50) {
    fwrite(STDERR, "WARN: queue depth diff > 50\n");
    exit(2);
}

echo "OK\n";
