<?php

require __DIR__.'/../vendor/autoload.php';
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(dirname(__DIR__, 2));
$dotenv->safeLoad();

require_once __DIR__ . '/_session_user.php';

function strim_safe_next(string $fallback = '/dashboard_saas.html'): string {
  $next = $fallback;
  if (isset($_POST['next'])) {
    $cand = (string)$_POST['next'];
    if (str_starts_with($cand, '/') && !str_starts_with($cand, '//') && !preg_match('~^https?://~i', $cand)) {
      $next = $cand;
    }
  }
  return $next;
}

function strim_login_rate_path(): string {
  $dir = dirname(__DIR__, 2) . '/logs/cache/login_rate';
  if (!is_dir($dir)) {
    @mkdir($dir, 0755, true);
  }
  return $dir;
}

function strim_login_rate_key(string $ip, string $name): string {
  return hash('sha256', strtolower(trim($name)) . '|' . $ip);
}

function strim_login_rate_blocked(string $ip, string $name): bool {
  $file = strim_login_rate_path() . '/' . strim_login_rate_key($ip, $name) . '.json';
  if (!is_readable($file)) {
    return false;
  }
  $raw = @file_get_contents($file);
  $j = $raw ? json_decode($raw, true) : null;
  if (!is_array($j)) {
    return false;
  }
  $fails = (int)($j['fails'] ?? 0);
  $until = (int)($j['until'] ?? 0);
  if ($until > time()) {
    return true;
  }
  // Soft window: 8 fails / 15 min
  if ($fails >= 8 && (time() - (int)($j['first'] ?? 0)) < 900) {
    return true;
  }
  return false;
}

function strim_login_rate_fail(string $ip, string $name): void {
  $file = strim_login_rate_path() . '/' . strim_login_rate_key($ip, $name) . '.json';
  $j = ['fails' => 0, 'first' => time(), 'until' => 0];
  if (is_readable($file)) {
    $raw = @file_get_contents($file);
    $prev = $raw ? json_decode($raw, true) : null;
    if (is_array($prev)) {
      $j = $prev;
    }
  }
  $now = time();
  if (($now - (int)($j['first'] ?? 0)) > 900) {
    $j = ['fails' => 0, 'first' => $now, 'until' => 0];
  }
  $j['fails'] = (int)($j['fails'] ?? 0) + 1;
  if ((int)$j['fails'] >= 8) {
    $j['until'] = $now + 900;
  }
  @file_put_contents($file, json_encode($j), LOCK_EX);
}

function strim_login_rate_clear(string $ip, string $name): void {
  $file = strim_login_rate_path() . '/' . strim_login_rate_key($ip, $name) . '.json';
  if (is_file($file)) {
    @unlink($file);
  }
}

$name = '';
if (isset($_POST['name'])) {
  $name = trim((string)$_POST['name']);
}

$pass = '';
if (isset($_POST['pass'])) {
  $pass = (string)$_POST['pass'];
}

$next = strim_safe_next('/dashboard_saas.html');

$ip = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '';
$ua = isset($_SERVER['HTTP_USER_AGENT']) ? substr((string)$_SERVER['HTTP_USER_AGENT'], 0, 255) : '';

if ($name === '' || $pass === '') {
  header(
    'Location: /auth/login.php?msg='.
    urlencode('Введіть логін і пароль').
    '&next='.urlencode($next)
  );
  exit;
}

if (strim_login_rate_blocked($ip, $name)) {
  header(
    'Location: /auth/login.php?msg='.
    urlencode('Забагато спроб. Спробуйте через 15 хв.').
    '&next='.urlencode($next)
  );
  exit;
}

$mysqli = new mysqli(
  $_ENV['DB_HOST'],
  $_ENV['DB_USER'],
  $_ENV['DB_PASS'],
  $_ENV['DB_NAME']
);
$mysqli->set_charset('utf8mb4');

// Логін: шукаємо або за nickname, або за email
$n   = $mysqli->real_escape_string($name);
$sql = "SELECT id, pass_hash, email FROM users WHERE nickname='$n' OR email='$n' LIMIT 1";
$res = $mysqli->query($sql);

if (!($res && ($row = $res->fetch_assoc()))) {
  strim_login_rate_fail($ip, $name);
  header(
    'Location: /auth/login.php?msg='.
    urlencode('Невірний логін або пароль').
    '&next='.urlencode($next)
  );
  exit;
}

$uid  = isset($row['id']) ? (int)$row['id'] : 0;
$hash = isset($row['pass_hash']) ? (string)$row['pass_hash'] : '';

if ($uid <= 0) {
  strim_login_rate_fail($ip, $name);
  header(
    'Location: /auth/login.php?msg='.
    urlencode('Невірний логін або пароль').
    '&next='.urlencode($next)
  );
  exit;
}

// Empty hash: force password reset — never claim account via first login.
if ($hash === '' || $hash === null) {
  strim_login_rate_fail($ip, $name);
  header(
    'Location: /auth/forgot_password.php?msg='.
    urlencode('Пароль не встановлено. Скиньте пароль через email.')
  );
  exit;
}

if (!password_verify($pass, $hash)) {
  strim_login_rate_fail($ip, $name);
  header(
    'Location: /auth/login.php?msg='.
    urlencode('Невірний логін або пароль').
    '&next='.urlencode($next)
  );
  exit;
}

strim_login_rate_clear($ip, $name);

// Створюємо сесію
$token = bin2hex(random_bytes(16));

$ipEsc  = $mysqli->real_escape_string($ip);
$uaEsc  = $mysqli->real_escape_string($ua);
$tokEsc = $mysqli->real_escape_string($token);

$mysqli->query(
  "INSERT INTO sessions_auth(token,ip,ua,user_id)
   VALUES('$tokEsc','$ipEsc','$uaEsc',$uid)"
);

setcookie(
  'sid',
  $token,
  array(
    'expires'  => time() + auth_session_cookie_sec(),
    'path'     => '/',
    'secure'   => true,
    'httponly' => true,
    'samesite' => 'Lax',
  )
);

header('Location: '.$next);
exit;
