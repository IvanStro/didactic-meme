<?php
declare(strict_types=1);
/**
 * Форма підтримки з дашборду: зберігає звернення в БД (якщо є таблиця) і надсилає в Telegram адміну.
 *
 * .env (на сервері, не в git):
 *   SUPPORT_TG_BOT_TOKEN=...      (бот для вхідних звернень; якщо порожньо — береться TG_BOT_TOKEN)
 *   SUPPORT_TG_ADMIN_CHAT_ID=... (ваш числовий chat_id у Telegram, куди бот надсилає копії)
 *
 * Одночасні користувачі: кожен запит незалежний; повідомлення в Telegram йдуть окремими листами з user_id / ticket id — гонок немає.
 */

require_once __DIR__ . '/../auth/_session_user.php';
require_once __DIR__ . '/_tg_notify.php';
require_once __DIR__ . '/_csrf.php';
require_once __DIR__ . '/_onboarding.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$mysqli = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
if ($mysqli->connect_errno) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'db_connect'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
$mysqli->set_charset('utf8mb4');

$uid = current_user_id($mysqli);
if ($uid <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

require_csrf_or_403();

$raw = (string)file_get_contents('php://input');
$in = [];
if ($raw !== '') {
    $j = json_decode($raw, true);
    if (is_array($j)) {
        $in = $j;
    }
}
$body = trim((string)($in['message'] ?? $_POST['message'] ?? ''));
if (mb_strlen($body) < 12) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'message_too_short'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if (mb_strlen($body) > 4000) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'message_too_long'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Rate limit: до 8 звернень на годину на користувача (файловий лічильник)
$rlFile = sys_get_temp_dir() . '/ld_support_rl_' . (int)$uid . '.json';
$now = time();
$window = 3600;
$maxN = 8;
if (is_readable($rlFile)) {
    $st = json_decode((string)file_get_contents($rlFile), true);
    if (is_array($st) && isset($st['t'], $st['n']) && ($now - (int)$st['t']) < $window) {
        if ((int)$st['n'] >= $maxN) {
            http_response_code(429);
            echo json_encode(['ok' => false, 'error' => 'rate_limited'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
        $st['n'] = (int)$st['n'] + 1;
    } else {
        $st = ['t' => $now, 'n' => 1];
    }
} else {
    $st = ['t' => $now, 'n' => 1];
}
@file_put_contents($rlFile, json_encode($st), LOCK_EX);

$uRes = $mysqli->query('SELECT id, nickname, email, telegram, tg_chat_id, tg_enabled FROM users WHERE id=' . (int)$uid . ' LIMIT 1');
if (!$uRes || !($u = $uRes->fetch_assoc())) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'user_not_found'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$tgLinked = ((int)($u['tg_enabled'] ?? 0) === 1 && !empty($u['tg_chat_id']));
$tgChat = $tgLinked ? (string)$u['tg_chat_id'] : '';
$tgUser = trim((string)($u['telegram'] ?? ''));
$nick = trim((string)($u['nickname'] ?? ''));
$email = trim((string)($u['email'] ?? ''));

$ticketId = 0;
$ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$escBody = $mysqli->real_escape_string($body);
$escTg = $mysqli->real_escape_string($tgUser);
$tgChatSql = $tgLinked ? (int)$u['tg_chat_id'] : 'NULL';
$tgL = $tgLinked ? 1 : 0;
$escIp = $mysqli->real_escape_string(mb_substr($ip, 0, 45));
$sqlIns = "
  INSERT INTO support_messages (user_id, body, telegram_username, tg_chat_id, tg_linked, client_ip, created_at)
  VALUES (" . (int)$uid . ", '$escBody', " . ($tgUser !== '' ? "'$escTg'" : 'NULL') . ", $tgChatSql, $tgL, '$escIp', NOW())
";
try {
    if ($mysqli->query($sqlIns)) {
        $ticketId = (int)$mysqli->insert_id;
    }
} catch (Throwable $e) {
    // Немає таблиці (1146) або інша помилка DDL — не блокуємо відправку в Telegram
    $ticketId = 0;
}

if ($ticketId <= 0) {
    http_response_code(503);
    echo json_encode(['ok'=>false,'error'=>'support_storage_unavailable']);
    exit;
}
strim_ob_record($mysqli, $uid, 'help');

$token = trim((string)($_ENV['SUPPORT_TG_BOT_TOKEN'] ?? ''));
if ($token === '') {
    $token = trim((string)($_ENV['TG_BOT_TOKEN'] ?? ''));
}
$adminChat = trim((string)($_ENV['SUPPORT_TG_ADMIN_CHAT_ID'] ?? ''));
if ($adminChat === '') {
    $adminChat = trim((string)($_ENV['TG_CHAT_ID'] ?? ''));
}

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$html = "<b>StrIM — підтримка</b>\n";
if ($ticketId > 0) {
    $html .= 'Тікет: <code>#' . $ticketId . "</code>\n";
}
$html .= "User ID: <code>{$uid}</code>\n";
$html .= '<b>Нік:</b> ' . h($nick !== '' ? $nick : '—') . "\n";
$html .= '<b>Email:</b> ' . h($email !== '' ? $email : '—') . "\n";
$html .= '<b>Telegram (профіль):</b> ' . h($tgUser !== '' ? ('@' . ltrim($tgUser, '@')) : '—') . "\n";
$html .= '<b>TG для відповіді клієнту:</b> ' . ($tgLinked ? ('chat_id <code>' . h($tgChat) . '</code>') : 'не привʼязано') . "\n";
$html .= "<b>Текст:</b>\n" . h($body);

if ($token === '' || $adminChat === '') {
    echo json_encode(['ok'=>true,'ticket_id'=>$ticketId,'tg_reply_available'=>$tgLinked,'notification_sent'=>false]);
    exit;
}

[$sent, $info] = tg_send_html_raw($token, $adminChat, $html, []);
if (!$sent) {
    $plain = "StrIM — підтримка\n"
        . ($ticketId > 0 ? "Тікет: #{$ticketId}\n" : '')
        . "User ID: {$uid}\n"
        . 'Нік: ' . ($nick !== '' ? $nick : '—') . "\n"
        . 'Email: ' . ($email !== '' ? $email : '—') . "\n"
        . 'Telegram (профіль): ' . ($tgUser !== '' ? ('@' . ltrim($tgUser, '@')) : '—') . "\n"
        . 'TG для відповіді: ' . ($tgLinked ? ('chat_id ' . $tgChat) : 'не привʼязано') . "\n"
        . "Текст:\n" . $body;
    if (mb_strlen($plain) > 4000) {
        $plain = mb_substr($plain, 0, 3997) . '…';
    }
    [$sent2, $info2] = tg_send_html_raw($token, $adminChat, $plain, ['parse_mode' => '']);
    if (!$sent2) {
        error_log('strim_support admin notification failed; ticket=' . $ticketId);
        echo json_encode(['ok'=>true,'ticket_id'=>$ticketId,'tg_reply_available'=>$tgLinked,'notification_sent'=>false]);
        exit;
    }
}

echo json_encode([
    'ok' => true,
    'ticket_id' => $ticketId,
    'tg_reply_available' => $tgLinked,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
