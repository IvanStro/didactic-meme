<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

require __DIR__ . '/../../engine/engine_boot.php';
require_once __DIR__ . '/../auth/_session_user.php';
require_once __DIR__ . '/_billing_access.php';
require_once __DIR__ . '/_strategy_status.php';
require_once __DIR__ . '/_csrf.php';

$uid = current_user_id($mysqli);
if ($uid <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method']);
    exit;
}
ensure_csrf_token();
require_csrf_or_403();

// Stopping new purchases remains available regardless of a funded balance.

$strategyId = (int)($_POST['strategy_id'] ?? ($_POST['sid'] ?? 0));
$row = $strategyId > 0
    ? strategy_resolve_owned($mysqli, $uid, '', $strategyId)
    : null;

if (!$row) {
    $res = $mysqli->query("SELECT id, symbol, status FROM strategies WHERE owner_id=$uid ORDER BY id DESC LIMIT 1");
    $row = $res ? $res->fetch_assoc() : null;
}
if (!$row) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'no strategy']);
    exit;
}

$sid = (int)$row['id'];
$apply = strategy_apply_status($mysqli, $uid, $sid, 'pause');
if (!$apply['ok']) {
    http_response_code(500);
    echo json_encode($apply, JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'ok' => true,
    'strategy_id' => $sid,
    'status' => 'paused',
    'changed' => !empty($apply['changed']),
    'prev_status' => (string)($apply['prev_status'] ?? ''),
], JSON_UNESCAPED_UNICODE);
