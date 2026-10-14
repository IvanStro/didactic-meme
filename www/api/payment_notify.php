<?php
declare(strict_types=1);
/**
 * Користувач повідомляє, що здійснив оплату банком або USDT (ручна перевірка адміном).
 */
require_once __DIR__ . '/_api_bootstrap.php';
require_once __DIR__ . '/_tg_notify.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method'], JSON_UNESCAPED_UNICODE);
    exit;
}

$mysqli = new mysqli(
    (string) ($_ENV['DB_HOST'] ?? ''),
    (string) ($_ENV['DB_USER'] ?? ''),
    (string) ($_ENV['DB_PASS'] ?? ''),
    (string) ($_ENV['DB_NAME'] ?? '')
);
if ($mysqli->connect_errno) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'db_connect'], JSON_UNESCAPED_UNICODE);
    exit;
}
$mysqli->set_charset('utf8mb4');

$uid = current_user_id($mysqli);
if ($uid <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'login_required'], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = (string) file_get_contents('php://input');
$in = [];
if ($raw !== '') {
    $j = json_decode($raw, true);
    if (is_array($j)) {
        $in = $j;
    }
}
$method = strtolower(trim((string) ($in['method'] ?? $_POST['method'] ?? '')));
$comment = trim((string) ($in['comment'] ?? $_POST['comment'] ?? ''));
$amountUah = (float) ($in['amount_uah'] ?? $_POST['amount_uah'] ?? 500);
if ($amountUah < 500) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'min_deposit_uah', 'min_uah' => 500], JSON_UNESCAPED_UNICODE);
    exit;
}
$amountUah = round($amountUah, 2);

$allowed = ['bank' => 'Банківський переказ (≥500 грн)'];
if (!isset($allowed[$method])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_method'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (mb_strlen($comment) > 500) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'comment_too_long'], JSON_UNESCAPED_UNICODE);
    exit;
}

$mysqli->query(
    "CREATE TABLE IF NOT EXISTS payment_notifications (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      user_id BIGINT NOT NULL,
      method VARCHAR(16) NOT NULL,
      comment VARCHAR(512) DEFAULT NULL,
      client_ip VARCHAR(45) DEFAULT NULL,
      created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY idx_user_created (user_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci"
);

$cooldown = $mysqli->query(
    "SELECT id FROM payment_notifications
     WHERE user_id=" . (int) $uid . "
       AND method='" . $mysqli->real_escape_string($method) . "'
       AND created_at > DATE_SUB(NOW(), INTERVAL 30 MINUTE)
     ORDER BY id DESC LIMIT 1"
);
if ($cooldown && $cooldown->num_rows > 0) {
    echo json_encode([
        'ok' => true,
        'already_sent' => true,
        'message' => 'Ми вже отримали ваше повідомлення нещодавно. Адміністратор перевірить оплату найближчим часом.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$rlFile = sys_get_temp_dir() . '/ld_pay_notify_rl_' . (int) $uid . '.json';
$now = time();
$maxPerDay = 6;
if (is_readable($rlFile)) {
    $st = json_decode((string) file_get_contents($rlFile), true);
    if (is_array($st) && isset($st['t'], $st['n']) && ($now - (int) $st['t']) < 86400) {
        if ((int) $st['n'] >= $maxPerDay) {
            http_response_code(429);
            echo json_encode(['ok' => false, 'error' => 'rate_limited'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $st['n'] = (int) $st['n'] + 1;
    } else {
        $st = ['t' => $now, 'n' => 1];
    }
} else {
    $st = ['t' => $now, 'n' => 1];
}
@file_put_contents($rlFile, json_encode($st), LOCK_EX);

$uRes = $mysqli->query(
    'SELECT id, nickname, email, telegram, ref_code, billing_status, trial_until, paid_until, tg_enabled, tg_chat_id
     FROM users WHERE id=' . (int) $uid . ' LIMIT 1'
);
$u = $uRes ? $uRes->fetch_assoc() : null;
if (!$u) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'user_not_found'], JSON_UNESCAPED_UNICODE);
    exit;
}

$nick = trim((string) ($u['nickname'] ?? ''));
$email = trim((string) ($u['email'] ?? ''));
$ref = trim((string) ($u['ref_code'] ?? ''));
$tgUser = trim((string) ($u['telegram'] ?? ''));
$billing = (string) ($u['billing_status'] ?? '');
$trialUntil = (string) ($u['trial_until'] ?? '');
$paidUntil = (string) ($u['paid_until'] ?? '');
$tgLinked = ((int) ($u['tg_enabled'] ?? 0) === 1 && !empty($u['tg_chat_id']));

$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
$escMethod = $mysqli->real_escape_string($method);
$escComment = $comment !== '' ? "'" . $mysqli->real_escape_string($comment) . "'" : 'NULL';
$escIp = $mysqli->real_escape_string(mb_substr($ip, 0, 45));
$mysqli->query(
    "INSERT INTO payment_notifications (user_id, method, comment, client_ip, created_at)
     VALUES (" . (int) $uid . ", '$escMethod', $escComment, '$escIp', NOW())"
);
$notifyId = (int) $mysqli->insert_id;

function pay_notify_h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$methodLabel = $allowed[$method];
$html = "<b>💳 StrIM — повідомлення про оплату</b>\n";
if ($notifyId > 0) {
    $html .= 'Заявка: <code>#' . $notifyId . "</code>\n";
}
$html .= "Спосіб: <b>" . pay_notify_h($methodLabel) . "</b>\n";
$html .= "Сума: <b>" . pay_notify_h(number_format($amountUah, 2, '.', ' ')) . " грн</b> (мін. 500; депозит на баланс USDT)\n";
$html .= "User ID: <code>{$uid}</code>\n";
$html .= '<b>Нік:</b> ' . pay_notify_h($nick !== '' ? $nick : '—') . "\n";
$html .= '<b>Email:</b> ' . pay_notify_h($email !== '' ? $email : '—') . "\n";
$html .= '<b>Реф. код:</b> <code>' . pay_notify_h($ref !== '' ? $ref : '—') . "</code>\n";
$html .= '<b>Telegram:</b> ' . pay_notify_h($tgUser !== '' ? ('@' . ltrim($tgUser, '@')) : '—') . "\n";
$html .= '<b>Статус білінгу:</b> ' . pay_notify_h($billing !== '' ? $billing : '—');
if ($paidUntil !== '') {
    $html .= ' · paid_until ' . pay_notify_h($paidUntil);
} elseif ($trialUntil !== '') {
    $html .= ' · trial_until ' . pay_notify_h($trialUntil);
}
$html .= "\n";
$html .= '<b>TG для відповіді:</b> ' . ($tgLinked ? ('chat_id <code>' . pay_notify_h((string) $u['tg_chat_id']) . '</code>') : 'не привʼязано') . "\n";
if ($comment !== '') {
    $html .= "<b>Коментар:</b>\n" . pay_notify_h($comment) . "\n";
}
$html .= "\n<i>Потрібно: перевірити платіж і натиснути «+500 грн» в адмінці (або підтвердити фактичну суму).</i>";

$token = trim((string) ($_ENV['SUPPORT_TG_BOT_TOKEN'] ?? ''));
if ($token === '') {
    $token = trim((string) ($_ENV['TG_BOT_TOKEN'] ?? ''));
}
$adminChat = trim((string) ($_ENV['SUPPORT_TG_ADMIN_CHAT_ID'] ?? ''));
if ($adminChat === '') {
    $adminChat = trim((string) ($_ENV['TG_CHAT_ID'] ?? ''));
}

$tgOk = false;
if ($token !== '' && $adminChat !== '') {
    [$tgOk] = tg_send_html_raw($token, $adminChat, $html, []);
}
if (!$tgOk) {
    tg_notify(
        "💳 Оплата (повідомлення)\n"
        . $methodLabel . "\n"
        . "user: {$email} (#{$uid})\n"
        . "ref: {$ref}\n"
        . ($comment !== '' ? "comment: {$comment}\n" : '')
        . 'Перевірити в адмінці → +30 днів'
    );
    $tgOk = true;
}

echo json_encode([
    'ok' => true,
    'notify_id' => $notifyId,
    'telegram_sent' => $tgOk,
    'message' => 'Дякуємо! Ми отримали повідомлення. Після перевірки платежу адміністратор активує підписку (зазвичай протягом робочого дня).',
], JSON_UNESCAPED_UNICODE);
