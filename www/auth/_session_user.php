<?php

/**
 * Макс. «простій» між запитами (сек): якщо last_seen старіший — сесія недійсна.
 * Налаштування: .env SESSION_TTL_SEC (за замовчуванням 18000 = 5 год).
 */
function auth_session_ttl_sec(): int {
  static $cached = null;
  if ($cached !== null) {
    return $cached;
  }
  $raw = $_ENV['SESSION_TTL_SEC'] ?? getenv('SESSION_TTL_SEC');
  $raw = is_string($raw) ? trim($raw) : '';
  $v = $raw !== '' ? (int)$raw : 18000;
  if ($v < 300) {
    $v = 300;
  }
  if ($v > 604800) {
    $v = 604800;
  }
  $cached = $v;
  return $cached;
}

/**
 * Термін куки sid (сек від now). За замовчуванням = SESSION_TTL_SEC.
 * Опційно: SESSION_COOKIE_SEC окремо від idle TTL.
 */
function auth_session_cookie_sec(): int {
  static $cached = null;
  if ($cached !== null) {
    return $cached;
  }
  $raw = $_ENV['SESSION_COOKIE_SEC'] ?? getenv('SESSION_COOKIE_SEC');
  $raw = is_string($raw) ? trim($raw) : '';
  $v = $raw !== '' ? (int)$raw : auth_session_ttl_sec();
  if ($v < 300) {
    $v = 300;
  }
  if ($v > 604800) {
    $v = 604800;
  }
  $cached = $v;
  return $cached;
}

/**
 * Повертає id поточного користувача за sid+IP, якщо сесія свіжа (TTL сек).
 * Якщо все ок — оновлює last_seen, щоб сесія продовжувалась під час активності.
 *
 * @param int|null $ttlSec null = auth_session_ttl_sec()
 */
function current_user_id($db, $ttlSec = null) {
  if ($ttlSec === null) {
    $ttlSec = auth_session_ttl_sec();
  }
  $sid = isset($_COOKIE['sid']) ? $_COOKIE['sid'] : '';
  // IP_FALLBACK_TOKEN_ONLY
  // Prefer X-Forwarded-For (first hop) if present, else REMOTE_ADDR
  $ip = '';
  if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    $ip = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
  } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
    $ip = $_SERVER['REMOTE_ADDR'];
  }

  if ($sid === '' || $ip === '') {
    return 0;
  }

  $tok = $db->real_escape_string($sid);
  $pip = $db->real_escape_string($ip);
  // 1) Try strict match: token + ip
  $sql = "SELECT user_id, UNIX_TIMESTAMP(last_seen) AS ls
          FROM sessions_auth
          WHERE token='$tok' AND ip='$pip'
          LIMIT 1";
  $res = $db->query($sql);

  // 2) Fallback: token only (handles proxy / changing IP)
  if (!$res || !$res->num_rows) {
    $sql = "SELECT user_id, UNIX_TIMESTAMP(last_seen) AS ls
            FROM sessions_auth
            WHERE token='$tok'
            LIMIT 1";
    $res = $db->query($sql);
  }
if ($res && ($row = $res->fetch_assoc())) {
    $uid = isset($row['user_id']) ? (int)$row['user_id'] : 0;
    $ls  = isset($row['ls']) ? (int)$row['ls'] : 0;

    if ($uid > 0 && (time() - $ls) <= $ttlSec) {
      // оновлюємо last_seen, щоб продовжити життя активної сесії
      $db->query("UPDATE sessions_auth SET last_seen = NOW() WHERE token='$tok'");
      return $uid;
    }
  }

  return 0;
}
