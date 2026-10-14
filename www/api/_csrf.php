<?php
declare(strict_types=1);

function ensure_csrf_token(): string {
  $token = isset($_COOKIE['csrf_token']) ? (string)$_COOKIE['csrf_token'] : '';
  if ($token === '' || strlen($token) < 32) {
    $token = bin2hex(random_bytes(16));
    setcookie('csrf_token', $token, [
      'expires' => time() + 3600,
      'path' => '/',
      'secure' => true,
      'httponly' => false,
      'samesite' => 'Lax',
    ]);
  }
  return $token;
}

function require_csrf_or_403(): void {
  $cookie = isset($_COOKIE['csrf_token']) ? (string)$_COOKIE['csrf_token'] : '';
  $incoming = '';
  if (!empty($_SERVER['HTTP_X_CSRF_TOKEN'])) {
    $incoming = (string)$_SERVER['HTTP_X_CSRF_TOKEN'];
  } elseif (isset($_POST['csrf_token'])) {
    $incoming = (string)$_POST['csrf_token'];
  }
  if ($cookie === '' || $incoming === '' || !hash_equals($cookie, $incoming)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'csrf']);
    exit;
  }
}
