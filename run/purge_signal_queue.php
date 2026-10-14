<?php
declare(strict_types=1);

/**
 * Purge terminal signal_queue rows (done/failed) older than N days.
 *
 *   php run/purge_signal_queue.php [days=7] [batch=5000]
 *
 * Keeps queued/processing untouched. Batched DELETE to avoid long locks.
 */
$root = dirname(__DIR__);
require $root . '/www/vendor/autoload.php';
Dotenv\Dotenv::createImmutable($root)->safeLoad();

$days = isset($argv[1]) ? max(1, min(90, (int) $argv[1])) : 7;
$batch = isset($argv[2]) ? max(500, min(20000, (int) $argv[2])) : 5000;
$dry = in_array('--dry-run', $argv, true);

$db = new mysqli(
    (string) ($_ENV['DB_HOST'] ?? ''),
    (string) ($_ENV['DB_USER'] ?? ''),
    (string) ($_ENV['DB_PASS'] ?? ''),
    (string) ($_ENV['DB_NAME'] ?? '')
);
if ($db->connect_errno) {
    fwrite(STDERR, 'db: ' . $db->connect_error . PHP_EOL);
    exit(1);
}
$db->set_charset('utf8mb4');

$before = $db->query(
    "SELECT status, COUNT(*) c
     FROM signal_queue
     GROUP BY status"
);
echo '=== purge_signal_queue days=' . $days . ' batch=' . $batch . ($dry ? ' DRY' : '') . ' ===' . PHP_EOL;
echo 'before:' . PHP_EOL;
while ($before && ($r = $before->fetch_assoc())) {
    echo '  ' . $r['status'] . '=' . $r['c'] . PHP_EOL;
}

$eligible = $db->query(
    "SELECT COUNT(*) c FROM signal_queue
     WHERE status IN ('done','failed')
       AND created_at < (NOW() - INTERVAL {$days} DAY)"
)->fetch_assoc();
$elig = (int) ($eligible['c'] ?? 0);
echo "eligible={$elig}" . PHP_EOL;

if ($dry || $elig <= 0) {
    echo 'done (no deletes)' . PHP_EOL;
    exit(0);
}

$deleted = 0;
$rounds = 0;
$maxRounds = (int) ceil($elig / $batch) + 5;
while ($rounds < $maxRounds) {
    $rounds++;
    $db->query(
        "DELETE FROM signal_queue
         WHERE status IN ('done','failed')
           AND created_at < (NOW() - INTERVAL {$days} DAY)
         ORDER BY id ASC
         LIMIT {$batch}"
    );
    $n = (int) $db->affected_rows;
    $deleted += $n;
    echo "round={$rounds} deleted={$n} total={$deleted}" . PHP_EOL;
    if ($n < $batch) {
        break;
    }
    usleep(50_000);
}

$size = $db->query(
    "SELECT ROUND((data_length+index_length)/1024/1024,2) mb
     FROM information_schema.tables
     WHERE table_schema=DATABASE() AND table_name='signal_queue'"
)->fetch_assoc();
echo 'size_mb_after_approx=' . ($size['mb'] ?? '?') . ' (run OPTIMIZE later if needed)' . PHP_EOL;

$after = $db->query('SELECT status, COUNT(*) c FROM signal_queue GROUP BY status');
echo 'after:' . PHP_EOL;
while ($after && ($r = $after->fetch_assoc())) {
    echo '  ' . $r['status'] . '=' . $r['c'] . PHP_EOL;
}
echo "deleted_total={$deleted}" . PHP_EOL;
