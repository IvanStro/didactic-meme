<?php
declare(strict_types=1);
/**
 * Перевірка GA4 Measurement Protocol (.env + тестова подія).
 * Usage: php run/verify_ga4_measurement_protocol.php
 */
$root = dirname(__DIR__);
require $root . '/www/vendor/autoload.php';

use Dotenv\Dotenv;

Dotenv::createImmutable($root)->safeLoad();
require_once $root . '/www/api/_whitepay_dotenv.php';
strim_whitepay_maybe_load_home_env();

require $root . '/www/api/_ga4_measurement_protocol.php';

$mid = ga4_measurement_id();
$secret = ga4_mp_api_secret();
echo "GA4 measurement_id: {$mid}\n";
echo "API secret configured: " . ($secret !== '' ? 'yes (len=' . strlen($secret) . ')' : 'no') . "\n";

if (!ga4_mp_is_configured()) {
    fwrite(STDERR, "FAIL: set GA4_MEASUREMENT_PROTOCOL_API_SECRET (or Measurement_Protocol_API) in .env\n");
    exit(1);
}

$clientId = 'strim.verify.' . bin2hex(random_bytes(8));
$result = ga4_mp_send_event($clientId, 'strim_mp_verify', [
    'engagement_time_msec' => 1,
    'debug_verify' => 1,
]);

echo 'HTTP: ' . ($result['http'] ?? 0) . "\n";
if (!empty($result['body'])) {
    echo 'Body: ' . $result['body'] . "\n";
}

if (!empty($result['ok'])) {
    echo "OK: GA4 Measurement Protocol accepted the test event.\n";
    echo "Check GA4 → Admin → DebugView or Realtime (event: strim_mp_verify).\n";
    exit(0);
}

fwrite(STDERR, 'FAIL: ' . ($result['error'] ?? 'unknown') . "\n");
if (($result['http'] ?? 0) === 403) {
    fwrite(STDERR, "Hint: invalid api_secret or wrong measurement_id.\n");
}
exit(1);
