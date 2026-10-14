<?php
declare(strict_types=1);
/**
 * Cron (рекомендовано кожні 15 хв): paused для running стратегій з простроченою підпискою.
 *   php run/cron_billing_pause_expired.php
 */

$root = dirname(__DIR__);
require $root . '/www/vendor/autoload.php';
require_once $root . '/www/api/_billing_access.php';

Dotenv\Dotenv::createImmutable($root)->safeLoad();

$m = new mysqli(
    (string)($_ENV['DB_HOST'] ?? ''),
    (string)($_ENV['DB_USER'] ?? ''),
    (string)($_ENV['DB_PASS'] ?? ''),
    (string)($_ENV['DB_NAME'] ?? '')
);
if ($m->connect_errno) {
    fwrite(STDERR, 'billing_pause: db ' . $m->connect_error . PHP_EOL);
    exit(1);
}
$m->set_charset('utf8mb4');

$n = billing_sync_expired_users($m);
echo date('c') . " billing_pause_expired expired_users={$n['expired_users']} paused_strategies={$n['paused_strategies']}\n";

// Ops visibility: trial ends within 24h (wind-down already via billing_sync when due)
$soon = $m->query(
    "SELECT id, email, trial_until,
            TIMESTAMPDIFF(HOUR, NOW(), trial_until) AS hours_left,
            (SELECT COUNT(*) FROM strategies s WHERE s.owner_id = users.id AND s.status = 'running') AS running
     FROM users
     WHERE billing_status = 'trial'
       AND trial_until IS NOT NULL
       AND trial_until > NOW()
       AND trial_until <= (NOW() + INTERVAL 24 HOUR)
     ORDER BY trial_until ASC"
);
while ($soon && ($r = $soon->fetch_assoc())) {
    echo date('c') . ' trial_ending_soon id=' . (int)$r['id']
        . ' email=' . (string)$r['email']
        . ' until=' . (string)$r['trial_until']
        . ' hours_left=' . (int)$r['hours_left']
        . ' running=' . (int)$r['running'] . "\n";
}
