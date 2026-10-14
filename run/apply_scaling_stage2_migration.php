<?php
declare(strict_types=1);

/**
 * Застосувати міграцію етапу 2 (next_tick_at). Безпечно повторювати.
 *   php run/apply_scaling_stage2_migration.php
 */

$root = dirname(__DIR__);
require $root . '/www/vendor/autoload.php';

Dotenv\Dotenv::createImmutable($root)->safeLoad();

$m = new mysqli(
    (string)($_ENV['DB_HOST'] ?? ''),
    (string)($_ENV['DB_USER'] ?? ''),
    (string)($_ENV['DB_PASS'] ?? ''),
    (string)($_ENV['DB_NAME'] ?? '')
);
if ($m->connect_errno) {
    fwrite(STDERR, 'db: ' . $m->connect_error . PHP_EOL);
    exit(1);
}
$m->set_charset('utf8mb4');

function column_exists(mysqli $m, string $table, string $col): bool
{
    $t = $m->real_escape_string($table);
    $c = $m->real_escape_string($col);
    $res = $m->query("SHOW COLUMNS FROM `{$t}` LIKE '{$c}'");
    return (bool)($res && $res->num_rows > 0);
}

function index_exists(mysqli $m, string $table, string $idx): bool
{
    $t = $m->real_escape_string($table);
    $i = $m->real_escape_string($idx);
    $res = $m->query("SHOW INDEX FROM `{$t}` WHERE Key_name = '{$i}'");
    return (bool)($res && $res->num_rows > 0);
}

if (!column_exists($m, 'strategies', 'next_tick_at')) {
    if (!$m->query('ALTER TABLE strategies ADD COLUMN next_tick_at DATETIME NULL DEFAULT NULL')) {
        fwrite(STDERR, 'ALTER next_tick_at: ' . $m->error . PHP_EOL);
        exit(1);
    }
    echo "added column strategies.next_tick_at\n";
} else {
    echo "column strategies.next_tick_at already exists\n";
}

if (!index_exists($m, 'strategies', 'idx_strategies_due')) {
    if (!$m->query('CREATE INDEX idx_strategies_due ON strategies (status, next_tick_at, id)')) {
        fwrite(STDERR, 'INDEX idx_strategies_due: ' . $m->error . PHP_EOL);
        exit(1);
    }
    echo "created index idx_strategies_due\n";
} else {
    echo "index idx_strategies_due already exists\n";
}

if (!index_exists($m, 'job_queue', 'idx_job_queue_user_status')) {
    if (!$m->query('CREATE INDEX idx_job_queue_user_status ON job_queue (user_id, status)')) {
        fwrite(STDERR, 'INDEX idx_job_queue_user_status: ' . $m->error . PHP_EOL);
        exit(1);
    }
    echo "created index idx_job_queue_user_status\n";
} else {
    echo "index idx_job_queue_user_status already exists\n";
}

// Running strategies: перший тік негайно.
$m->query("UPDATE strategies SET next_tick_at = NOW() WHERE status = 'running' AND next_tick_at IS NULL");

echo "ok running strategies scheduled for immediate tick\n";
