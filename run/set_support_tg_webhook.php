<?php
declare(strict_types=1);

/**
 * Webhook бота підтримки (SUPPORT_TG_BOT_TOKEN → tg_support_webhook.php).
 * Usage: php run/set_support_tg_webhook.php [https://strim.in.ua/api/tg_support_webhook.php]
 */

require __DIR__ . '/../www/vendor/autoload.php';

Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
require_once __DIR__ . '/../www/api/_whitepay_dotenv.php';
strim_whitepay_maybe_load_home_env();

$token = trim((string) ($_ENV['SUPPORT_TG_BOT_TOKEN'] ?? ''));
if ($token === '') {
    fwrite(STDERR, "SUPPORT_TG_BOT_TOKEN missing in .env\n");
    exit(1);
}

$base = $argv[1] ?? trim((string) ($_ENV['SUPPORT_WEBHOOK_URL'] ?? 'https://strim.in.ua/api/tg_support_webhook.php'));
if ($base === '') {
    $base = 'https://strim.in.ua/api/tg_support_webhook.php';
}

$secret = trim((string) ($_ENV['SUPPORT_WEBHOOK_SECRET'] ?? ''));
if ($secret === '') {
    fwrite(STDERR, "SUPPORT_WEBHOOK_SECRET missing in .env (required for tg_support_webhook.php)\n");
    exit(1);
}

$params = [
    'url' => $base,
    'secret_token' => $secret,
    'allowed_updates' => json_encode(['message', 'edited_message'], JSON_UNESCAPED_UNICODE),
    'drop_pending_updates' => 'true',
];

$ch = curl_init('https://api.telegram.org/bot' . $token . '/setWebhook');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $params,
    CURLOPT_TIMEOUT => 20,
]);
$raw = curl_exec($ch);
$err = curl_error($ch);
curl_close($ch);

if ($raw === false) {
    fwrite(STDERR, "curl error: {$err}\n");
    exit(1);
}

echo $raw . "\n";
$j = json_decode((string) $raw, true);
if (empty($j['ok'])) {
    exit(1);
}

$info = json_decode((string) file_get_contents('https://api.telegram.org/bot' . $token . '/getWebhookInfo'), true);
echo json_encode($info['result'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
exit(0);
