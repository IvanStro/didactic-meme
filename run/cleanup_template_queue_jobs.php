<?php
declare(strict_types=1);
/**
 * Прибрати з черги службові TEMPLATE-стратегії (queued/processing → done).
 * Dry-run за замовчуванням; --apply для запису.
 */

$root = dirname(__DIR__);
require $root . '/www/vendor/autoload.php';
require_once $root . '/www/api/_worker_manager.php';

Dotenv\Dotenv::createImmutable($root)->safeLoad();

$apply = in_array('--apply', $argv, true);

$m = new mysqli(
    (string)($_ENV['DB_HOST'] ?? ''),
    (string)($_ENV['DB_USER'] ?? ''),
    (string)($_ENV['DB_PASS'] ?? ''),
    (string)($_ENV['DB_NAME'] ?? '')
);
if ($m->connect_errno) {
    fwrite(STDERR, "db: " . $m->connect_error . "\n");
    exit(1);
}
$m->set_charset('utf8mb4');

$result = worker_cleanup_template_queue($m, $apply);

echo 'template_queue_jobs=' . $result['found'] . ($apply ? ' APPLY' : ' dry-run') . "\n";
foreach ($result['jobs'] as $r) {
    echo sprintf(
        "  job=%d sid=%d %s status=%s\n",
        (int)$r['job_id'],
        (int)$r['strategy_id'],
        (string)$r['symbol'],
        (string)$r['status']
    );
}
if ($apply) {
    echo 'updated=' . $result['updated'] . "\n";
}

exit(0);
