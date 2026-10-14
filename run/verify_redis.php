<?php
declare(strict_types=1);
/**
 * Перевірка Redis (ADM.TOOLS unix socket або REDIS_URL).
 *   php run/verify_redis.php
 */

$root = dirname(__DIR__);
require $root . '/www/vendor/autoload.php';
Dotenv\Dotenv::createImmutable($root)->safeLoad();

echo "REDIS_URL=" . (getenv('REDIS_URL') ?: '(empty)') . PHP_EOL;
echo "REDIS_SOCKET=" . (getenv('REDIS_SOCKET') ?: '(default /home/bot20/.system/redis.sock)') . PHP_EOL;
echo "QUEUE_BACKEND=" . (getenv('QUEUE_BACKEND') ?: 'mysql') . PHP_EOL;
echo "REDIS_PRICE_BUS=" . (getenv('REDIS_PRICE_BUS') ?: '0') . PHP_EOL;

$sock = '/home/bot20/.system/redis.sock';
if (is_readable($sock)) {
    echo "socket file: OK {$sock}\n";
} else {
    echo "socket file: MISSING {$sock}\n";
}

if (class_exists('Redis')) {
    try {
        $r = new Redis();
        $r->connect($sock);
        echo 'php-redis PING=' . $r->ping() . PHP_EOL;
        $r->set('strim:verify', date('c'), 30);
        echo 'php-redis SET/GET=' . $r->get('strim:verify') . PHP_EOL;
    } catch (Throwable $e) {
        echo 'php-redis ERR: ' . $e->getMessage() . PHP_EOL;
    }
} else {
    echo "php-redis extension: not loaded\n";
}

require_once $root . '/engine_v3/redis_client.php';
if (!class_exists(\Predis\Client::class)) {
    echo "predis: NOT INSTALLED — run: cd www && composer require predis/predis:^2.2\n";
    exit(1);
}

if (!redis_available()) {
    echo "predis: FAIL (see error_log)\n";
    exit(1);
}

$r = redis_client();
$r->setex('strim:verify_predis', 30, date('c'));
echo 'predis PING=OK GET=' . $r->get('strim:verify_predis') . PHP_EOL;
echo "OK verify_redis\n";
