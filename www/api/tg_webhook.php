<?php
declare(strict_types=1);

/**
 * Telegram webhook endpoint (PUBLIC).
 *
 * IMPORTANT:
 * - This endpoint must be reachable without your site auth/session.
 * - If you have a global API-auth guard, it must allow this script.
 *
 * Recommended hardening (optional):
 * - Set a Telegram webhook secret token and verify it via header:
 *   X-Telegram-Bot-Api-Secret-Token
 */

// Allow global API guards to bypass auth for this endpoint (if you implement such guard)
if (!defined('ALLOW_UNAUTHENTICATED')) {
  define('ALLOW_UNAUTHENTICATED', true);
}

require_once __DIR__ . '/_api_bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

// Read raw input
$raw = file_get_contents('php://input');
if ($raw === false || trim($raw) === '') {
  echo json_encode(['ok' => true, 'note' => 'empty'], JSON_UNESCAPED_UNICODE);
  exit;
}

$update = json_decode($raw, true);
if (!is_array($update)) {
  echo json_encode(['ok' => true, 'note' => 'bad json'], JSON_UNESCAPED_UNICODE);
  exit;
}

/**
 * REQUIRED: verify Telegram secret token header.
 * 1) Set TG_WEBHOOK_SECRET in .env
 * 2) Set webhook with secret_token via run/set_trading_tg_webhook.php
 */
$expectedSecret = trim((string)($_ENV['TG_WEBHOOK_SECRET'] ?? ''));
if ($expectedSecret === '') {
  http_response_code(503);
  echo json_encode(['ok' => false, 'error' => 'TG_WEBHOOK_SECRET missing'], JSON_UNESCAPED_UNICODE);
  exit;
}
$gotSecret = (string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');
if (!hash_equals($expectedSecret, $gotSecret)) {
  // Reply 200 so Telegram doesn't spam retries, but we ignore the update
  echo json_encode(['ok' => true, 'note' => 'bad secret'], JSON_UNESCAPED_UNICODE);
  exit;
}

// DB connect
$host = (string)($_ENV['DB_HOST'] ?? '');
$name = (string)($_ENV['DB_NAME'] ?? '');
$user = (string)($_ENV['DB_USER'] ?? '');
$pass = (string)($_ENV['DB_PASS'] ?? '');

$mysqli = @new mysqli($host, $user, $pass, $name);
if ($mysqli->connect_errno) {
  echo json_encode(['ok' => true, 'note' => 'db connect fail'], JSON_UNESCAPED_UNICODE);
  exit;
}
$mysqli->set_charset('utf8mb4');

// Telegram bot token
$token = (string)($_ENV['TG_BOT_TOKEN'] ?? '');
if ($token === '') {
  echo json_encode(['ok' => true, 'note' => 'TG_BOT_TOKEN missing'], JSON_UNESCAPED_UNICODE);
  exit;
}

function tg_send(string $token, string $chatId, string $text): void {
  $url = "https://api.telegram.org/bot{$token}/sendMessage";
  $payload = [
    'chat_id' => $chatId,
    'text' => $text,
    'parse_mode' => 'HTML',
    'disable_web_page_preview' => true,
  ];

  $ch = curl_init($url);
  if ($ch === false) return;

  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
  ]);
  curl_exec($ch);
  curl_close($ch);
}

// Extract message
$msg = $update['message'] ?? ($update['edited_message'] ?? null);
if (!is_array($msg)) {
  echo json_encode(['ok' => true, 'note' => 'no message'], JSON_UNESCAPED_UNICODE);
  exit;
}

$chatId = (string)($msg['chat']['id'] ?? '');
$text = trim((string)($msg['text'] ?? ''));

if ($chatId === '' || $text === '') {
  echo json_encode(['ok' => true, 'note' => 'no chat/text'], JSON_UNESCAPED_UNICODE);
  exit;
}

// Accept: /start CODE (CODE is 6..32 alnum)
if (!preg_match('~^/start(?:\s+([A-Za-z0-9]{6,32}))?\s*$~u', $text, $m)) {
  echo json_encode(['ok' => true, 'note' => 'not start'], JSON_UNESCAPED_UNICODE);
  exit;
}

$code = isset($m[1]) ? trim((string)$m[1]) : '';
if ($code === '') {
  tg_send(
    $token,
    $chatId,
    "Щоб привʼязати Telegram: згенеруй код у кабінеті і надішли мені:\n<b>/start CODE</b>"
  );
  echo json_encode(['ok' => true, 'note' => 'no code'], JSON_UNESCAPED_UNICODE);
  exit;
}

// Find unused code (case-insensitive) and mark used + link chat_id
// NOTE: Your tg_link_codes schema has: (id, user_id, code, created_at, used_at, chat_id)
$linkId = null;
$userId = null;

try {
  $mysqli->begin_transaction();

  // Lock the latest matching unused code row to avoid race
  $stmt = $mysqli->prepare("
    SELECT id, user_id
    FROM tg_link_codes
    WHERE used_at IS NULL AND LOWER(code) = LOWER(?)
    ORDER BY id DESC
    LIMIT 1
    FOR UPDATE
  ");
  if (!$stmt) {
    throw new RuntimeException('prepare select failed');
  }
  $stmt->bind_param('s', $code);
  $stmt->execute();
  $res = $stmt->get_result();
  $row = $res ? $res->fetch_assoc() : null;
  $stmt->close();

  if (!$row) {
    $mysqli->rollback();
    tg_send($token, $chatId, "Код <b>{$code}</b> не знайдено або він вже використаний. Згенеруй новий у кабінеті.");
    echo json_encode(['ok' => true, 'note' => 'code not found'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $linkId = (int)$row['id'];
  $userId = (int)$row['user_id'];

  // chat_id can be large (int64), store as signed bigint compatible value
  $chatIdInt = (int)$chatId;

  $stmt2 = $mysqli->prepare("
    UPDATE tg_link_codes
    SET used_at = NOW(), chat_id = ?
    WHERE id = ? AND used_at IS NULL
  ");
  if (!$stmt2) {
    throw new RuntimeException('prepare update code failed');
  }
  $stmt2->bind_param('ii', $chatIdInt, $linkId);
  $stmt2->execute();
  $stmt2->close();

  $stmt3 = $mysqli->prepare("
    UPDATE users
    SET tg_chat_id = ?, tg_enabled = 1
    WHERE id = ?
  ");
  if (!$stmt3) {
    throw new RuntimeException('prepare update user failed');
  }
  $stmt3->bind_param('ii', $chatIdInt, $userId);
  $stmt3->execute();
  $stmt3->close();

  $mysqli->commit();

  tg_send($token, $chatId, "✅ Telegram привʼязано успішно.\nТепер повідомлення по стратегіях будуть приходити сюди.");
  tg_send($token, $chatId, "🚀 Система готова до роботи");
  echo json_encode(['ok' => true, 'linked' => true, 'user_id' => $userId], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  try { $mysqli->rollback(); } catch (Throwable $e2) {}
  // Reply 200 (ok:true) to prevent Telegram retry storms, but include note for debugging
  echo json_encode(['ok' => true, 'note' => 'exception', 'err' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}