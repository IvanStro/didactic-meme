<?php
declare(strict_types=1);
/**
 * Webhook для бота ПІДТРИМКИ (SUPPORT_TG_BOT_TOKEN).
 *
 * Сценарій: ви відповідаєте **Reply** на повідомлення з тікетом (рядок «Тікет: #123») —
 * текст відправляється користувачу в Telegram **торговим ботом** (TG_BOT_TOKEN → той самий чат,
 * куди вже йдуть технічні сповіщення).
 *
 * Налаштування .env:
 *   SUPPORT_TG_BOT_TOKEN=...          (бот, куди падають звернення з дашборду)
 *   SUPPORT_TG_ADMIN_CHAT_ID=123     (ваш числовий Telegram ID; можна кілька через кому)
 *   TG_BOT_TOKEN=...                  (торговий / основний бот користувача)
 *   SUPPORT_WEBHOOK_SECRET=...      (довільний довгий рядок)
 *
 * Webhook (один раз):
 *   curl -sS "https://api.telegram.org/bot<SUPPORT_TG_BOT_TOKEN>/setWebhook?url=https://YOURDOMAIN/api/tg_support_webhook.php&secret_token=<SUPPORT_WEBHOOK_SECRET>"
 *
 * Telegram передає secret у заголовку X-Telegram-Bot-Api-Secret-Token.
 */

if (!defined('ALLOW_UNAUTHENTICATED')) {
    define('ALLOW_UNAUTHENTICATED', true);
}

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_tg_notify.php';
require_once __DIR__ . '/_support_conversation.php';

use Dotenv\Dotenv;

header('Content-Type: application/json; charset=utf-8');

$dotenv = Dotenv::createImmutable(dirname(__DIR__, 2));
$dotenv->safeLoad();

$raw = file_get_contents('php://input');
if ($raw === false || trim($raw) === '') {
    echo json_encode(['ok' => true]);
    exit;
}

$upd = json_decode($raw, true);
if (!is_array($upd)) {
    echo json_encode(['ok' => true]);
    exit;
}

// Якщо секрет не заданий — будь-який може підробити JSON і від імені адміна слати клієнтам повідомлення торговим ботом.
$secret = trim((string)($_ENV['SUPPORT_WEBHOOK_SECRET'] ?? ''));
if ($secret === '') {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'SUPPORT_WEBHOOK_SECRET missing'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$got = (string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');
if (!hash_equals($secret, $got)) {
    @file_put_contents(
        dirname(__DIR__, 2) . '/logs/tg_support_webhook.log',
        date('c') . " secret_mismatch from=" . ($_SERVER['REMOTE_ADDR'] ?? '') . "\n",
        FILE_APPEND | LOCK_EX
    );
    echo json_encode(['ok' => true, 'note' => 'secret']);
    exit;
}

$supportToken = trim((string)($_ENV['SUPPORT_TG_BOT_TOKEN'] ?? ''));
$adminRaw = trim((string)($_ENV['SUPPORT_TG_ADMIN_CHAT_ID'] ?? ''));
$tradeToken = trim((string)($_ENV['TG_BOT_TOKEN'] ?? ''));

if ($supportToken === '' || $adminRaw === '') {
    echo json_encode(['ok' => true, 'note' => 'env']);
    exit;
}

$adminIds = array_values(array_filter(array_map('trim', explode(',', $adminRaw))));

$msg = $upd['message'] ?? null;
if (!is_array($msg)) {
    echo json_encode(['ok' => true]);
    exit;
}

$fromId = isset($msg['from']['id']) ? (string)$msg['from']['id'] : '';
if ($fromId === '' || !in_array($fromId, $adminIds, true)) {
    echo json_encode(['ok' => true, 'note' => 'not_admin']);
    exit;
}

$replyText = trim((string)($msg['text'] ?? $msg['caption'] ?? ''));
if ($replyText === '') {
    echo json_encode(['ok' => true]);
    exit;
}

$parent = $msg['reply_to_message'] ?? null;
if (!is_array($parent)) {
    tg_send_html_raw($supportToken, (string)$msg['chat']['id'], "<b>Підтримка</b>\nЩоб відповісти клієнту в чат <b>торгового бота</b>, зробіть <b>Відповідь (Reply)</b> на повідомлення з рядком <code>Тікет: #…</code>.", []);
    echo json_encode(['ok' => true, 'note' => 'need_reply']);
    exit;
}

function support_extract_ticket_id(array $m): int
{
    for ($d = 0; $d < 10; $d++) {
        $text = (string)($m['text'] ?? $m['caption'] ?? '');
        if (preg_match('/Тікет:\s*#(\d+)/u', $text, $mm)) {
            return (int)$mm[1];
        }
        if (preg_match('/Ticket:\s*#(\d+)/u', $text, $mm)) {
            return (int)$mm[1];
        }
        $next = $m['reply_to_message'] ?? null;
        if (!is_array($next)) {
            break;
        }
        $m = $next;
    }
    return 0;
}

$ticketId = support_extract_ticket_id($parent);
if ($ticketId <= 0) {
    tg_send_html_raw($supportToken, (string)$msg['chat']['id'], "<b>Підтримка</b>\nНе знайдено номер тікета в ланцюжку reply. Відповідайте на картку звернення з полем <code>Тікет: #…</code>.", []);
    echo json_encode(['ok' => true, 'note' => 'no_ticket']);
    exit;
}

if (mb_strlen($replyText) > 3500) {
    $replyText = mb_substr($replyText, 0, 3497) . '…';
}

$host = (string)($_ENV['DB_HOST'] ?? '');
$name = (string)($_ENV['DB_NAME'] ?? '');
$user = (string)($_ENV['DB_USER'] ?? '');
$pass = (string)($_ENV['DB_PASS'] ?? '');

$mysqli = @new mysqli($host, $user, $pass, $name);
if ($mysqli->connect_errno) {
    echo json_encode(['ok' => true, 'note' => 'db']);
    exit;
}
$mysqli->set_charset('utf8mb4');

$tid = (int)$ticketId;
$q = $mysqli->query("
  SELECT sm.user_id, u.tg_chat_id, u.tg_enabled, u.nickname
  FROM support_messages sm
  JOIN users u ON u.id = sm.user_id
  WHERE sm.id = {$tid}
  LIMIT 1
");
$row = $q ? $q->fetch_assoc() : null;
if (!$row) {
    tg_send_html_raw($supportToken, (string)$msg['chat']['id'], "<b>Підтримка</b>\nТікет <code>#{$tid}</code> не знайдено в БД.", []);
    echo json_encode(['ok' => true, 'note' => 'ticket_missing']);
    exit;
}

$uid = (int)$row['user_id'];
try {
    $key = 'tg:' . (string)$msg['chat']['id'] . ':' . (int)$msg['message_id'];
    $reply = strim_support_add_reply($mysqli, $uid, $tid, null, $replyText, 'telegram', $key);
    if (!$reply['created']) { echo json_encode(['ok'=>true,'duplicate'=>true]); exit; }
    $notice = strim_support_notify_reply($mysqli, $uid, $reply['id'], $replyText);
    $html = '<b>Підтримка StrIM</b>\nВідповідь збережено в кабінеті користувача <code>#' . $uid . '</code>. Сповіщення: ' . htmlspecialchars($notice['channel'] . ' / ' . $notice['status'], ENT_QUOTES, 'UTF-8');
    tg_send_html_raw($supportToken, (string)$msg['chat']['id'], $html, []);
    echo json_encode(['ok'=>true,'stored'=>true,'notification'=>$notice]);
} catch (Throwable $e) {
    error_log('strim_support reply failed; ticket=' . $tid . ' code=' . (int)$e->getCode());
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'reply_storage_failed']);
}
