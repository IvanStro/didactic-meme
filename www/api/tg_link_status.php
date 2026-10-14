<?php
declare(strict_types=1);

require_once __DIR__ . '/_api_bootstrap.php';
require_once __DIR__ . '/_onboarding.php';

header('Content-Type: application/json; charset=utf-8');

$mysqli = new mysqli(
    (string) ($_ENV['DB_HOST'] ?? ''),
    (string) ($_ENV['DB_USER'] ?? ''),
    (string) ($_ENV['DB_PASS'] ?? ''),
    (string) ($_ENV['DB_NAME'] ?? '')
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

$res = $mysqli->query(
    'SELECT tg_enabled, tg_chat_id FROM users WHERE id=' . (int) $uid . ' LIMIT 1'
);
$row = $res ? $res->fetch_assoc() : null;


$linked = $row
    && (int) ($row['tg_enabled'] ?? 0) === 1
    && !empty($row['tg_chat_id']);

if ($linked) strim_ob_record($mysqli, $uid, 'telegram');
$mysqli->close();

echo json_encode([
    'ok'     => true,
    'linked' => $linked,
    'ts'     => date('Y-m-d H:i:s'),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
