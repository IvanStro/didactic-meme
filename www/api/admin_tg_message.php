<?php
declare(strict_types=1);

/**
 * Адмін → повідомлення користувачу в Telegram (текст і/або фото).
 * POST multipart: user_id, text?, photo?, csrf_token
 */

require_once __DIR__ . '/_api_bootstrap.php';
require_once __DIR__ . '/_csrf.php';
require_once __DIR__ . '/_admin_audit.php';
require_once __DIR__ . '/_tg_notify.php';

header('Content-Type: application/json; charset=utf-8');

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method'], JSON_UNESCAPED_UNICODE);
    exit;
}

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

$adminUid = current_user_id($mysqli);
if ($adminUid <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'auth'], JSON_UNESCAPED_UNICODE);
    exit;
}
$admRes = $mysqli->query('SELECT is_admin FROM users WHERE id=' . (int)$adminUid . ' LIMIT 1');
$admRow = $admRes ? $admRes->fetch_assoc() : null;
if (!$admRow || (int)($admRow['is_admin'] ?? 0) !== 1) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}
require_csrf_or_403();

$targetUserId = (int)($_POST['user_id'] ?? 0);
$text = trim((string)($_POST['text'] ?? ''));
$hasPhoto = isset($_FILES['photo']) && is_array($_FILES['photo']) && (int)($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

if ($targetUserId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_user_id'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($text === '' && !$hasPhoto) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'empty_message'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (mb_strlen($text) > 4096) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'text_too_long'], JSON_UNESCAPED_UNICODE);
    exit;
}

$uRes = $mysqli->query(
    'SELECT id, email, nickname, tg_chat_id, tg_enabled FROM users WHERE id=' . (int)$targetUserId . ' LIMIT 1'
);
$uRow = $uRes ? $uRes->fetch_assoc() : null;
if (!$uRow) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'user_not_found'], JSON_UNESCAPED_UNICODE);
    exit;
}

$chatId = trim((string)($uRow['tg_chat_id'] ?? ''));
$tgEnabled = (int)($uRow['tg_enabled'] ?? 0) === 1;
if (!$tgEnabled || $chatId === '' || $chatId === '0') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'tg_not_linked'], JSON_UNESCAPED_UNICODE);
    exit;
}

$token = trim((string)($_ENV['TG_BOT_TOKEN'] ?? ''));
if ($token === '') {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'tg_token_missing'], JSON_UNESCAPED_UNICODE);
    exit;
}

$tmpPhoto = '';
$photoName = '';
$photoSize = 0;
try {
    if ($hasPhoto) {
        $err = (int)($_FILES['photo']['error'] ?? UPLOAD_ERR_OK);
        if ($err !== UPLOAD_ERR_OK) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'upload_error_' . $err], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $photoSize = (int)($_FILES['photo']['size'] ?? 0);
        if ($photoSize <= 0 || $photoSize > 10 * 1024 * 1024) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'photo_size'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $tmp = (string)($_FILES['photo']['tmp_name'] ?? '');
        $photoName = basename((string)($_FILES['photo']['name'] ?? 'photo.jpg'));
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmp) ?: '';
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
        if (!isset($allowed[$mime])) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'photo_type', 'mime' => $mime], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $tmpPhoto = sys_get_temp_dir() . '/strim_tg_' . bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
        if (!move_uploaded_file($tmp, $tmpPhoto)) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'photo_store'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Фото + підпис (або лише фото)
        [$ok, $msg] = tg_send_photo_raw($token, $chatId, $tmpPhoto, $text, ['parse_mode' => '']);
        if (!$ok) {
            admin_audit_log($mysqli, $adminUid, 'tg_message_admin', $targetUserId, [
                'has_photo' => true,
                'text_len' => mb_strlen($text),
                'error' => $msg,
            ], 'fail');
            http_response_code(502);
            echo json_encode(['ok' => false, 'error' => 'tg_send_failed', 'detail' => $msg], JSON_UNESCAPED_UNICODE);
            exit;
        }
    } else {
        [$ok, $msg] = tg_send_html_raw($token, $chatId, $text, ['parse_mode' => '']);
        if (!$ok) {
            admin_audit_log($mysqli, $adminUid, 'tg_message_admin', $targetUserId, [
                'has_photo' => false,
                'text_len' => mb_strlen($text),
                'error' => $msg,
            ], 'fail');
            http_response_code(502);
            echo json_encode(['ok' => false, 'error' => 'tg_send_failed', 'detail' => $msg], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    admin_audit_log($mysqli, $adminUid, 'tg_message_admin', $targetUserId, [
        'has_photo' => $hasPhoto,
        'photo_name' => $photoName,
        'photo_size' => $photoSize,
        'text_len' => mb_strlen($text),
        'text_preview' => mb_substr($text, 0, 120),
    ], 'ok');

    echo json_encode([
        'ok' => true,
        'message' => 'sent',
        'user_id' => $targetUserId,
        'has_photo' => $hasPhoto,
    ], JSON_UNESCAPED_UNICODE);
} finally {
    if ($tmpPhoto !== '' && is_file($tmpPhoto)) {
        @unlink($tmpPhoto);
    }
    $mysqli->close();
}
