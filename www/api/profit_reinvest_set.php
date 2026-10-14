<?php
declare(strict_types=1);

/**
 * Toggle profit reinvest for current user.
 * POST: enabled=1|0 (or true/false / on/off)
 */
require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../auth/_session_user.php';
require_once __DIR__ . '/_csrf.php';
require_once dirname(__DIR__, 2) . '/engine/profit_reinvest.php';

Dotenv\Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();
header('Content-Type: application/json; charset=utf-8');

$mysqli = new mysqli(
    (string)($_ENV['DB_HOST'] ?? ''),
    (string)($_ENV['DB_USER'] ?? ''),
    (string)($_ENV['DB_PASS'] ?? ''),
    (string)($_ENV['DB_NAME'] ?? '')
);
if ($mysqli->connect_errno) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'db_connect'], JSON_UNESCAPED_UNICODE);
    exit;
}
$mysqli->set_charset('utf8mb4');

$uid = current_user_id($mysqli);
if ($uid <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_csrf_or_403();

if (!profit_reinvest_schema_ready($mysqli)) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'schema_missing'], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = $_POST['enabled'] ?? $_REQUEST['enabled'] ?? null;
if ($raw === null) {
    $body = file_get_contents('php://input');
    if (is_string($body) && $body !== '') {
        $j = json_decode($body, true);
        if (is_array($j) && array_key_exists('enabled', $j)) {
            $raw = $j['enabled'];
        }
    }
}

$enabled = null;
if (is_bool($raw)) {
    $enabled = $raw;
} elseif (is_numeric($raw)) {
    $enabled = ((int)$raw) === 1;
} elseif (is_string($raw)) {
    $v = strtolower(trim($raw));
    if (in_array($v, ['1', 'true', 'on', 'yes'], true)) {
        $enabled = true;
    } elseif (in_array($v, ['0', 'false', 'off', 'no'], true)) {
        $enabled = false;
    }
}

if ($enabled === null) {
    echo json_encode(['ok' => false, 'error' => 'invalid_enabled'], JSON_UNESCAPED_UNICODE);
    exit;
}

$res = profit_reinvest_set($mysqli, $uid, $enabled);
if (empty($res['ok'])) {
    echo json_encode(['ok' => false, 'error' => $res['error'] ?? 'set_failed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$st = $res['state'] ?? profit_reinvest_user_state($mysqli, $uid);

// Bust short-lived saas_core cache so toggle is visible immediately.
foreach (glob(sys_get_temp_dir() . '/saas_core_' . (int)$uid . '_*.json') ?: [] as $cf) {
    @unlink($cf);
}

echo json_encode([
    'ok' => true,
    'profit_reinvest' => !empty($st['enabled']),
    'profit_reserve_usdt' => round((float)($st['reserve_usdt'] ?? 0), 8),
    'profit_reserve_since' => $st['reserve_since'] ?? null,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
