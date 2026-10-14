<?php
declare(strict_types=1);

/**
 * Метадані торгового Telegram-бота (TG_BOT_TOKEN).
 */
function tg_trading_bot_username(): string
{
    static $cached = null;
    if (is_string($cached) && $cached !== '') {
        return $cached;
    }

    $fromEnv = trim((string) ($_ENV['TG_BOT_USERNAME'] ?? ''));
    if ($fromEnv !== '') {
        $cached = ltrim($fromEnv, '@');

        return $cached;
    }

    $token = trim((string) ($_ENV['TG_BOT_TOKEN'] ?? ''));
    if ($token === '') {
        $cached = 'strim_ai';

        return $cached;
    }

    $url = 'https://api.telegram.org/bot' . $token . '/getMe';
    $ctx = stream_context_create(['http' => ['timeout' => 8]]);
    $raw = @file_get_contents($url, false, $ctx);
    $j = is_string($raw) ? json_decode($raw, true) : null;
    $u = is_array($j) ? (string) ($j['result']['username'] ?? '') : '';
    $cached = $u !== '' ? $u : 'strim_ai';

    return $cached;
}

function tg_trading_bot_deep_link(string $startPayload): string
{
    $user = tg_trading_bot_username();
    $payload = rawurlencode($startPayload);

    return 'https://t.me/' . $user . '?start=' . $payload;
}
