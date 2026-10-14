<?php
declare(strict_types=1);

/**
 * Архів/expire старих WhitePay pending (тестові / недоплачені інвойси).
 *
 *   php run/cron_expire_whitepay_pending.php [days=3] [--dry-run]
 *
 * Cron (ADM.TOOLS) — НЕ на ту ж хвилину, що site_audit (concurrent → ERROR #1):
 *   10 0 * * * /usr/local/php84/bin/php /home/bot20/strim.in.ua/run/cron_expire_whitepay_pending.php 3 >> /home/bot20/strim.in.ua/logs/expire_whitepay.log 2>&1
 *
 * Webhook знаходить ордер по external_id / invoice_id незалежно від status —
 * пізня оплата все одно застосується (статус → paid).
 */
$root = dirname(__DIR__);
$logFile = $root . '/logs/expire_whitepay.log';

$log = static function (string $msg) use ($logFile): void {
    $line = date('c') . ' ' . $msg . "\n";
    echo $line;
    @file_put_contents($logFile, $line, FILE_APPEND);
};

try {
    require $root . '/www/vendor/autoload.php';
    require_once __DIR__ . '/cron_singleton.php';

    cron_singleton_acquire('cron_expire_whitepay_pending');
    set_time_limit(60);

    Dotenv\Dotenv::createImmutable($root)->safeLoad();

    $days = 3;
    $dry = false;
    foreach (array_slice($argv, 1) as $a) {
        if ($a === '--dry-run') {
            $dry = true;
            continue;
        }
        if (ctype_digit((string) $a)) {
            $days = max(1, min(90, (int) $a));
        }
    }

    mysqli_report(MYSQLI_REPORT_OFF);
    $db = new mysqli(
        (string) ($_ENV['DB_HOST'] ?? ''),
        (string) ($_ENV['DB_USER'] ?? ''),
        (string) ($_ENV['DB_PASS'] ?? ''),
        (string) ($_ENV['DB_NAME'] ?? '')
    );
    if ($db->connect_errno) {
        $log('db_error: ' . $db->connect_error);
        exit(0); // не ERROR у панелі — ретрай наступної ночі
    }
    $db->set_charset('utf8mb4');

    $tq = $db->query("SHOW TABLES LIKE 'whitepay_orders'");
    if (!$tq || $tq->num_rows === 0) {
        $log('expire_whitepay: no table');
        exit(0);
    }

    $before = $db->query('SELECT status, COUNT(*) c FROM whitepay_orders GROUP BY status');
    $parts = [];
    while ($before && ($r = $before->fetch_assoc())) {
        $parts[] = $r['status'] . '=' . $r['c'];
    }
    $log('expire_whitepay days=' . $days . ($dry ? ' DRY' : '') . ' before: ' . implode(' ', $parts));

    $elig = $db->query(
        "SELECT COUNT(*) c FROM whitepay_orders
         WHERE status = 'pending'
           AND created_at < (NOW() - INTERVAL {$days} DAY)"
    );
    $n = ($elig && ($er = $elig->fetch_assoc())) ? (int) ($er['c'] ?? 0) : 0;
    $log("eligible_pending={$n}");

    if ($dry || $n <= 0) {
        $log('done (no updates)');
        exit(0);
    }

    $db->query(
        "UPDATE whitepay_orders
         SET status = 'expired'
         WHERE status = 'pending'
           AND created_at < (NOW() - INTERVAL {$days} DAY)"
    );
    $upd = (int) $db->affected_rows;
    $log("expired={$upd}");

    $after = $db->query('SELECT status, COUNT(*) c FROM whitepay_orders GROUP BY status');
    $parts = [];
    while ($after && ($r = $after->fetch_assoc())) {
        $parts[] = $r['status'] . '=' . $r['c'];
    }
    $log('after: ' . implode(' ', $parts));
    exit(0);
} catch (Throwable $e) {
    $log('fatal: ' . $e->getMessage());
    exit(0);
}
