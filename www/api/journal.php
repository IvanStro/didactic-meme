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

$uid = current_user_id($mysqli);
if ($uid <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not authenticated'], JSON_UNESCAPED_UNICODE);
    exit;
}

$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 200;
$sid = isset($_GET['sid']) ? (int) $_GET['sid'] : 0;
if ($sid <= 0 && isset($_GET['strategy_id'])) {
    $sid = (int) $_GET['strategy_id'];
}
if ($sid <= 0 && isset($_GET['id'])) {
    $sid = (int) $_GET['id'];
}

$includeInternal = !empty($_GET['include_internal']) && journal_user_is_admin($mysqli, $uid);

$items = journal_fetch_items($mysqli, $uid, $sid, $limit, $includeInternal);
$mysqli->close();

echo json_encode([
    'ok'    => true,
    'items' => $items,
    'ts'    => date('Y-m-d H:i:s'),
    'filtered_internal' => !$includeInternal,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
