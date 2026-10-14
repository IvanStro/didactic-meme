<?php
declare(strict_types=1);

/**
 * Налаштування webhook торгового бота (TG_BOT_TOKEN → tg_webhook.php).
 * Usage: php run/set_trading_tg_webhook.php [https://strim.in.ua/api/tg_webhook.php]
 */

require __DIR__ . '/../www/vendor/autoload.php';

Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
require_once __DIR__ . '/../www/api/_whitepay_dotenv.php';
strim_whitepay_maybe_load_home_env();

$token = trim((string) ($_ENV['TG_BOT_TOKEN'] ?? ''));
if ($token === '') {
    fwrite(STDERR, "TG_BOT_TOKEN missing in .env\n");
    exit(1);
}

$base = $argv[1] ?? trim((string) ($_ENV['TG_WEBHOOK_URL'] ?? 'https://strim.in.ua/api/tg_webhook.php'));
if ($base === '') {
    $base = 'https://strim.in.ua/api/tg_webhook.php';
}

$secret = trim((string) ($_ENV['TG_WEBHOOK_SECRET'] ?? ''));
$params = [
    'url' => $base,
    'allowed_updates' => json_encode(['message', 'edited_message'], JSON_UNESCAPED_UNICODE),
    'drop_pending_updates' => 'false',
];
if ($secret !== '') {
    $params['secret_token'] = $secret;
}

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
exit(!empty($j['ok']) ? 0 : 1);
