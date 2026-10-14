<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../api/_password_reset.php';

use Dotenv\Dotenv;

Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();

$token = trim((string) ($_POST['token'] ?? ''));
$pass = (string) ($_POST['pass'] ?? '');
$pass2 = (string) ($_POST['pass2'] ?? '');

$redirectErr = static function (string $msg, string $token): void {
    header(
        'Location: /auth/reset_password.php?token=' . urlencode($token) .
        '&msg=' . urlencode($msg)
    );
    exit;
};

if ($token === '') {
    header('Location: /auth/reset_password.php?msg=' . urlencode('Недійсне посилання'));
    exit;
}

if (strlen($pass) < 6) {
    $redirectErr('Пароль має бути не менше 6 символів', $token);
}

if ($pass !== $pass2) {
    $redirectErr('Паролі не збігаються', $token);
}

$mysqli = new mysqli(
    (string) ($_ENV['DB_HOST'] ?? ''),
    (string) ($_ENV['DB_USER'] ?? ''),
    (string) ($_ENV['DB_PASS'] ?? ''),
    (string) ($_ENV['DB_NAME'] ?? '')
);
if ($mysqli->connect_errno) {
    $redirectErr('Тимчасова помилка сервера', $token);
}
$mysqli->set_charset('utf8mb4');

$consumed = password_reset_consume_token($mysqli, $token);
if (empty($consumed['ok']) || empty($consumed['user_id'])) {
    header('Location: /auth/reset_password.php?msg=' . urlencode('Посилання прострочене або вже використане'));
    exit;
}

$userId = (int) $consumed['user_id'];
if (!password_reset_set_password($mysqli, $userId, $pass)) {
    $redirectErr('Не вдалося зберегти пароль', $token);
}

$mysqli->close();

header('Location: /auth/reset_password.php?ok=1');
exit;
