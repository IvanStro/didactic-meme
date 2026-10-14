<?php
declare(strict_types=1);
/**
 * GET: аналіз спадку портфеля (read-only рекомендації).
 */
require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_binance_utils.php';
require_once __DIR__ . '/_user_keys.php';
require_once __DIR__ . '/../auth/_session_user.php';
require_once __DIR__ . '/_csrf.php';
require_once __DIR__ . '/_legacy_portfolio.php';

use Dotenv\Dotenv;

header('Content-Type: application/json; charset=utf-8');

try {
    Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'env']);
    exit;
}

$db = @new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
if ($db->connect_errno) {
    echo json_encode(['ok' => false, 'error' => 'db']);
    exit;
}
$db->set_charset('utf8mb4');

$uid = current_user_id($db);
if ($uid <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}
ensure_csrf_token();

[$apiKey, $secret] = user_keys($db, $uid);
if (!$apiKey || !$secret) {
    echo json_encode(['ok' => false, 'error' => 'no_api_keys']);
    exit;
}

$scan = legacy_portfolio_scan_user($db, $uid, $apiKey, $secret);
if (empty($scan['ok'])) {
    http_response_code(502);
    echo json_encode($scan, JSON_UNESCAPED_UNICODE);
    exit;
}

// Enrich with UA labels for UI
foreach ($scan['items'] as &$it) {
    $it['primary_label'] = ''; // No personalized recommendation.
    $it['reason_labels'] = array_map('legacy_human_reason_uk', (array)$it['reasons']);
    $it['alternative_labels'] = [];
    foreach ((array)$it['alternatives'] as $a) {
        $it['alternative_labels'][] = ['code' => $a, 'label' => legacy_human_action_uk($a)];
    }
}
unset($it);

echo json_encode($scan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
