<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../api/_whitepay_dotenv.php';

use Dotenv\Dotenv;

$root = dirname(__DIR__, 2);
Dotenv::createImmutable($root)->safeLoad();
strim_whitepay_maybe_load_home_env();

require_once __DIR__ . '/../api/_password_reset.php';

$email = trim((string) ($_POST['email'] ?? ''));
$redirectSent = '/auth/forgot_password.php?sent=1';
$redirectErr = static function (string $msg) use ($redirectSent): void {
    header('Location: /auth/forgot_password.php?msg=' . urlencode($msg));
    exit;
};

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $redirectErr('Вкажіть коректний email');
}

$mysqli = new mysqli(
    (string) ($_ENV['DB_HOST'] ?? ''),
    (string) ($_ENV['DB_USER'] ?? ''),
    (string) ($_ENV['DB_PASS'] ?? ''),
    (string) ($_ENV['DB_NAME'] ?? '')
);
if ($mysqli->connect_errno) {
    $redirectErr('Тимчасова помилка сервера. Спробуйте пізніше.');
}
$mysqli->set_charset('utf8mb4');

$stmt = $mysqli->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
$stmt->bind_param('s', $email);
$stmt->execute();
$res = $stmt->get_result();
$row = $res ? $res->fetch_assoc() : null;
$stmt->close();

// Не розкриваємо, чи є користувач
if (!$row) {
    header('Location: ' . $redirectSent);
    exit;
}

$userId = (int) $row['id'];
$created = password_reset_create_token($mysqli, $userId, 60);
if (empty($created['ok']) || empty($created['token'])) {
    header('Location: ' . $redirectSent);
    exit;
}

$link = password_reset_base_url() . '/auth/reset_password.php?token=' . urlencode((string) $created['token']);
password_reset_send_notifications($mysqli, $userId, $link);
$mysqli->close();

header('Location: ' . $redirectSent);
exit;
