<?php
declare(strict_types=1);

require_once __DIR__ . '/_api_bootstrap.php';
require_once __DIR__ . '/_journal_query.php';

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
if ($adminUid <= 0 || !journal_user_is_admin($mysqli, $adminUid)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

$targetUserId = (int) ($_GET['user_id'] ?? $_GET['id'] ?? 0);
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

$strategies = journal_list_user_strategies($mysqli, $targetUserId);
$listOnly = !empty($_GET['list_strategies']) && empty($_GET['strategy_id']) && !isset($_GET['sid']);

$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 80;
$sid = isset($_GET['strategy_id']) ? (int) $_GET['strategy_id'] : 0;
if ($sid <= 0 && isset($_GET['sid'])) {
    $sid = (int) $_GET['sid'];
}

$items = [];
if (!$listOnly) {
    if ($sid <= 0 && $strategies !== []) {
        $sid = (int) $strategies[0]['id'];
    }
    if ($sid > 0) {
        $items = journal_fetch_items($mysqli, $targetUserId, $sid, $limit, true);
    }
}

$mysqli->close();

echo json_encode([
    'ok'         => true,
    'user'       => [
        'id'       => (int) $uRow['id'],
        'email'    => (string) ($uRow['email'] ?? ''),
        'nickname' => (string) ($uRow['nickname'] ?? ''),
    ],
    'strategies' => $strategies,
    'strategy_id'=> $sid,
    'items'      => $items,
    'include_internal' => true,
    'ts'         => date('Y-m-d H:i:s'),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
