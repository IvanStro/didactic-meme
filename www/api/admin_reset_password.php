<?php
declare(strict_types=1);

require_once __DIR__ . '/_api_bootstrap.php';
require_once __DIR__ . '/_csrf.php';
require_once __DIR__ . '/_admin_audit.php';
require_once __DIR__ . '/_password_reset.php';

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

$adminUid = current_user_id($mysqli);
if ($adminUid <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'auth'], JSON_UNESCAPED_UNICODE);
    exit;
}
$admRes = $mysqli->query('SELECT is_admin FROM users WHERE id=' . (int) $adminUid . ' LIMIT 1');
$admRow = $admRes ? $admRes->fetch_assoc() : null;
if (!$admRow || (int) ($admRow['is_admin'] ?? 0) !== 1) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}
require_csrf_or_403();

$targetUserId = (int) ($_POST['user_id'] ?? $_GET['user_id'] ?? 0);
$mode = trim((string) ($_POST['mode'] ?? 'set'));
$newPassword = (string) ($_POST['password'] ?? $_POST['new_password'] ?? '');

if ($targetUserId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid user_id'], JSON_UNESCAPED_UNICODE);
    exit;
}

$uRes = $mysqli->query(
    'SELECT id, email, nickname FROM users WHERE id=' . (int) $targetUserId . ' LIMIT 1'
);
$uRow = $uRes ? $uRes->fetch_assoc() : null;
if (!$uRow) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'user_not_found'], JSON_UNESCAPED_UNICODE);
    exit;
}

$generated = false;
if ($mode === 'generate' || $newPassword === '') {
    $newPassword = password_reset_generate_plain(12);
    $generated = true;
}

if (strlen($newPassword) < 6) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'password_too_short'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!password_reset_set_password($mysqli, $targetUserId, $newPassword)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'update_failed'], JSON_UNESCAPED_UNICODE);
    exit;
}

admin_audit_log($mysqli, $adminUid, 'password_reset_admin', $targetUserId, [
    'mode' => $mode,
    'generated' => $generated,
], 'ok');

$mysqli->close();

$out = [
    'ok'      => true,
    'user_id' => $targetUserId,
    'message' => 'Пароль оновлено. Усі сесії користувача скинуто.',
];
if ($generated) {
    $out['generated'] = true;
    $out['password'] = $newPassword;
}

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
