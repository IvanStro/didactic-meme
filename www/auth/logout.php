<?php
declare(strict_types=1);
require __DIR__.'/../vendor/autoload.php';
use Dotenv\Dotenv;
$dotenv = Dotenv::createImmutable(dirname(__DIR__, 2)); $dotenv->safeLoad();
$token = $_COOKIE['sid'] ?? '';
$mysqli = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
$mysqli->set_charset('utf8mb4');
if ($token) {
  $tokEsc = $mysqli->real_escape_string($token);
  $mysqli->query("DELETE FROM sessions_auth WHERE token='$tokEsc'");
  setcookie('sid','', time()-3600, '/');
}
$next = $_GET['next'] ?? '/auth/login.php';
header('Location: '.$next); exit;
