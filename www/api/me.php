<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

try {
    require_once __DIR__ . '/../vendor/autoload.php';
    require_once __DIR__ . '/../auth/_session_user.php';

    $dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__, 2));
    $dotenv->safeLoad();

    $mysqli = new mysqli(
        (string) ($_ENV['DB_HOST'] ?? ''),
        (string) ($_ENV['DB_USER'] ?? ''),
        (string) ($_ENV['DB_PASS'] ?? ''),
        (string) ($_ENV['DB_NAME'] ?? '')
    );
    if ($mysqli->connect_errno) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'db'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $mysqli->set_charset('utf8mb4');

    $uid = current_user_id($mysqli);
    if ($uid <= 0) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'unauthorized'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $q = $mysqli->query('SELECT id, nickname, email, tg_enabled, is_admin, can_edit_strategy FROM users WHERE id=' . (int) $uid . ' LIMIT 1');
    if (!$q || !($u = $q->fetch_assoc())) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'user_not_found'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode([
        'ok' => true,
        'user' => [
            'id' => (int) $u['id'],
            'nickname' => $u['nickname'],
            'email' => $u['email'],
            'tg_enabled' => (int) $u['tg_enabled'],
            'is_admin' => (int) $u['is_admin'],
            'can_edit_strategy' => (int) $u['can_edit_strategy'],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('me.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server_error'], JSON_UNESCAPED_UNICODE);
}
