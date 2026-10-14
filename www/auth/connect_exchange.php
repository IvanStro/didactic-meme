<?php
declare(strict_types=1);

/**
 * Старий екран «Підключення біржі» після реєстрації вимкнено.
 * API Key / Secret вводяться лише в кабінеті StrIM.
 */
require __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/_session_user.php';

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(dirname(__DIR__, 2));
$dotenv->safeLoad();

$mysqli = new mysqli(
  $_ENV['DB_HOST'],
  $_ENV['DB_USER'],
  $_ENV['DB_PASS'],
  $_ENV['DB_NAME']
);
$mysqli->set_charset('utf8mb4');

$uid = current_user_id($mysqli);
$next = '/dashboard.php';
if (isset($_GET['next']) && is_string($_GET['next']) && str_starts_with($_GET['next'], '/')) {
  $next = (string)$_GET['next'];
}

if ($uid <= 0) {
  header('Location: /auth/login.php?next=' . urlencode($next), true, 302);
  exit;
}

header('Location: ' . $next, true, 302);
exit;
