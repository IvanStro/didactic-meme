<?php
declare(strict_types=1);

require __DIR__ . '/../www/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

$m = new mysqli(
    (string) ($_ENV['DB_HOST'] ?? ''),
    (string) ($_ENV['DB_USER'] ?? ''),
    (string) ($_ENV['DB_PASS'] ?? ''),
    (string) ($_ENV['DB_NAME'] ?? '')
);
$m->set_charset('utf8mb4');

$email = $argv[1] ?? 'user@example.invalid';
$nick = $argv[2] ?? '1pavlo1';

$stmt = $m->prepare('SELECT id, email, nickname, tg_enabled, tg_chat_id, telegram FROM users WHERE email = ? OR nickname = ? LIMIT 5');
$stmt->bind_param('ss', $email, $nick);
$stmt->execute();
$res = $stmt->get_result();
$users = [];
while ($row = $res->fetch_assoc()) {
    $users[] = $row;
}
$stmt->close();

$codes = [];
if ($users !== []) {
    $uid = (int) $users[0]['id'];
    $c = $m->query("SELECT id, user_id, code, created_at, used_at, chat_id FROM tg_link_codes WHERE user_id={$uid} ORDER BY id DESC LIMIT 10");
    while ($c && ($row = $c->fetch_assoc())) {
        $codes[] = $row;
    }
}

$token = trim((string) ($_ENV['TG_BOT_TOKEN'] ?? ''));
$webhookInfo = null;
$botMe = null;
if ($token !== '') {
    $wh = @file_get_contents('https://api.telegram.org/bot' . $token . '/getWebhookInfo');
    $webhookInfo = $wh ? json_decode($wh, true) : null;
    $me = @file_get_contents('https://api.telegram.org/bot' . $token . '/getMe');
    $botMe = $me ? json_decode($me, true) : null;
}

echo json_encode([
    'users' => $users,
    'codes' => $codes,
    'bot_username' => $botMe['result']['username'] ?? null,
    'webhook' => $webhookInfo['result'] ?? null,
    'has_tg_webhook_secret' => (string) ($_ENV['TG_WEBHOOK_SECRET'] ?? '') !== '',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
