<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../auth/_session_user.php';
require_once __DIR__ . '/_ws_price.php';
require_once __DIR__ . '/_admin_audit.php';

Dotenv\Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();
header('Content-Type: application/json; charset=utf-8');

$mysqli = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
if ($mysqli->connect_errno) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'db_connect'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
$mysqli->set_charset('utf8mb4');

$adminUid = current_user_id($mysqli);
if ($adminUid <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
$admRes = $mysqli->query('SELECT is_admin FROM users WHERE id=' . (int)$adminUid . ' LIMIT 1');
$admRow = $admRes ? $admRes->fetch_assoc() : null;
if (!$admRow || (int)($admRow['is_admin'] ?? 0) !== 1) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$targetUserId = (int)(
    $_REQUEST['user_id'] ??
    $_REQUEST['id'] ??
    $_GET['user_id'] ??
    $_GET['id'] ??
    $_POST['user_id'] ??
    $_POST['id'] ??
    0
);
$extraUsdt = (float)(
    $_REQUEST['extra_usdt'] ??
    $_GET['extra_usdt'] ??
    $_POST['extra_usdt'] ??
    0
);
if ($extraUsdt < 0) {
    $extraUsdt = 0.0;
}

if ($targetUserId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'invalid_user_id'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$root = dirname(__DIR__, 2);
require_once $root . '/engine/engine_helpers.php';
require_once $root . '/engine/engine_logic.php';
require_once $root . '/engine/ladder_unfreeze_burst.php';
require_once $root . '/engine/spot_funds_freeze.php';

$report = ladder_user_unfreeze_burst_report($mysqli, $targetUserId, $extraUsdt);
if (empty($report['ok'])) {
    admin_audit_log($mysqli, $adminUid, 'unfreeze_burst_plan', $targetUserId, [
        'extra_usdt' => $extraUsdt,
        'error' => (string)($report['error'] ?? 'failed'),
    ], 'invalid_input');
    echo json_encode([
        'ok' => false,
        'error' => (string)($report['error'] ?? 'failed'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

admin_audit_log($mysqli, $adminUid, 'unfreeze_burst_plan', $targetUserId, [
    'extra_usdt' => $extraUsdt,
    'burst_levels' => (int)($report['totals']['burst_levels'] ?? 0),
    'burst_usdt_needed' => (float)($report['totals']['burst_usdt_needed'] ?? 0),
    'frozen' => !empty($report['frozen']['frozen']),
], 'ok');

echo json_encode([
    'ok' => true,
    'report' => $report,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
