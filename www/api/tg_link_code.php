<?php
declare(strict_types=1);

require_once __DIR__ . '/_api_bootstrap.php';
require_once __DIR__ . '/_tg_bot_meta.php';

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

$botUsername = tg_trading_bot_username();

$u = $mysqli->query('SELECT tg_enabled, tg_chat_id FROM users WHERE id=' . (int) $uid . ' LIMIT 1');
$ur = $u ? $u->fetch_assoc() : null;
if ($ur && (int) ($ur['tg_enabled'] ?? 0) === 1 && !empty($ur['tg_chat_id'])) {
    echo json_encode([
        'ok'            => true,
        'already_linked'=> true,
        'tg_chat_id'    => $ur['tg_chat_id'],
        'bot_username'  => $botUsername,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$reuse = $mysqli->query(
    'SELECT code, created_at FROM tg_link_codes WHERE user_id=' . (int) $uid .
    ' AND used_at IS NULL ORDER BY id DESC LIMIT 1'
);
if ($reuse && ($rr = $reuse->fetch_assoc())) {
    $created = strtotime((string) $rr['created_at']);
    if ($created !== false && (time() - $created) <= 600) {
        $code = (string) $rr['code'];
        echo json_encode([
            'ok'           => true,
            'code'         => $code,
            'bot_username' => $botUsername,
            'bot_link'     => tg_trading_bot_deep_link($code),
            'note'         => "Send /start {$code} to the bot",
            'ts'           => date('Y-m-d H:i:s'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

$mysqli->query(
    'UPDATE tg_link_codes SET used_at=NOW() WHERE user_id=' . (int) $uid . ' AND used_at IS NULL'
);

$code = bin2hex(random_bytes(6));

$stmt = $mysqli->prepare('INSERT INTO tg_link_codes (user_id, code, created_at) VALUES (?, ?, NOW())');
$stmt->bind_param('is', $uid, $code);
if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'insert_failed', 'detail' => $stmt->error], JSON_UNESCAPED_UNICODE);
    exit;
}
$stmt->close();
$mysqli->close();

echo json_encode([
    'ok'           => true,
    'code'         => $code,
    'bot_username' => $botUsername,
    'bot_link'     => tg_trading_bot_deep_link($code),
    'note'         => "Send /start {$code} to the bot",
    'ts'           => date('Y-m-d H:i:s'),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
