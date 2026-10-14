<?php
declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/_session_user.php';

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(dirname(__DIR__, 2));
$dotenv->safeLoad();

/** DB */
$mysqli = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
if ($mysqli->connect_errno) {
  http_response_code(500);
  echo "DB error";
  exit;
}
$mysqli->set_charset('utf8mb4');

/** session check */
$uid = current_user_id($mysqli);
$next = '/dashboard.php';

if ($uid <= 0) {
  header('Location: /auth/login.php?next='.urlencode($next), true, 302);
  exit;
}

/** API-ключі опційні на вході — кабінет SaaS */
header('Location: /dashboard_saas.html?v=20261012_reinvest');
exit;
