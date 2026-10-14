<?php
declare(strict_types=1);

/**
 * Telegram helpers для бота @strim_ai (окремий токен).
 */

/**
 * @return array{0:bool,1:string}
 */
function strim_ai_tg_api(string $token, string $method, array $params): array
{
    $token = trim($token);
    if ($token === '') {
        return [false, 'missing token'];
    }

    $url = 'https://api.telegram.org/bot' . $token . '/' . $method;
    $ch = curl_init($url);
    if ($ch === false) {
        return [false, 'curl_init'];
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($params),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);

    $resp = (string)curl_exec($ch);
    $errNo = curl_errno($ch);
    $err = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errNo) {
        return [false, "curl=$errNo $err"];
    }

    $j = json_decode($resp, true);
    if (is_array($j) && isset($j['ok']) && $j['ok'] === true) {
        return [true, 'ok'];
    }

    $desc = is_array($j) ? (string)($j['description'] ?? 'unknown') : mb_substr($resp, 0, 200);
    return [false, "http=$http tg=$desc"];
}

function strim_ai_tg_typing(string $token, string $chatId): void
{
    strim_ai_tg_api($token, 'sendChatAction', [
        'chat_id' => $chatId,
        'action' => 'typing',
    ]);
}

/**
 * @param array<string,mixed> $opts reply_to_message_id, message_thread_id, parse_mode
 * @return array{0:bool,1:string}
 */
function strim_ai_tg_send_text(string $token, string $chatId, string $text, array $opts = []): array
{
    $chatId = trim($chatId);
    $text = trim($text);
    if ($chatId === '' || $text === '') {
        return [false, 'empty chat or text'];
    }

    $chunks = strim_ai_split_text($text, 4096);
    $last = [false, 'no chunks'];
    foreach ($chunks as $i => $chunk) {
        $params = [
            'chat_id' => $chatId,
            'text' => $chunk,
            'disable_web_page_preview' => '1',
        ];

        $parseMode = (string)($opts['parse_mode'] ?? '');
        if ($parseMode !== '') {
            $params['parse_mode'] = $parseMode;
        }

        if ($i === 0) {
            if (!empty($opts['reply_to_message_id'])) {
                $params['reply_to_message_id'] = (int)$opts['reply_to_message_id'];
            }
            if (!empty($opts['message_thread_id'])) {
                $params['message_thread_id'] = (int)$opts['message_thread_id'];
            }
        }

        $last = strim_ai_tg_api($token, 'sendMessage', $params);
        if (!$last[0]) {
            return $last;
        }
    }

    return $last;
}

/**
 * @return list<string>
 */
function strim_ai_split_text(string $text, int $maxLen = 4096): array
{
    $text = trim($text);
    if ($text === '') {
        return [];
    }
    if (mb_strlen($text) <= $maxLen) {
        return [$text];
    }

    $out = [];
    $rest = $text;
    while (mb_strlen($rest) > $maxLen) {
        $slice = mb_substr($rest, 0, $maxLen);
        $breakAt = max(
            (int)mb_strrpos($slice, "\n\n"),
            (int)mb_strrpos($slice, "\n"),
            (int)mb_strrpos($slice, '. ')
        );
        if ($breakAt < (int)($maxLen * 0.5)) {
            $breakAt = $maxLen;
        }
        $out[] = trim(mb_substr($rest, 0, $breakAt));
        $rest = trim(mb_substr($rest, $breakAt));
    }
    if ($rest !== '') {
        $out[] = $rest;
    }

    return $out;
}

function strim_ai_log(string $line): void
{
    $root = dirname(__DIR__, 2);
    $dir = $root . '/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $file = $dir . '/tg_ai_webhook.log';
    $ts = date('Y-m-d H:i:s');
    @file_put_contents($file, "[$ts] $line\n", FILE_APPEND | LOCK_EX);
}

/**
 * Простий rate-limit: N повідомлень на вікно секунд для user_id.
 */
function strim_ai_rate_limited(string $userId, int $maxPerWindow = 12, int $windowSec = 60): bool
{
    $userId = preg_replace('/\D+/', '', $userId);
    if ($userId === '') {
        return true;
    }

    $root = dirname(__DIR__, 2);
    $dir = $root . '/tmp/strim_ai_rate';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    $file = $dir . '/u_' . $userId . '.json';
    $now = time();
    $hits = [];

    if (is_file($file)) {
        $raw = @file_get_contents($file);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($decoded) && isset($decoded['hits']) && is_array($decoded['hits'])) {
            foreach ($decoded['hits'] as $t) {
                $t = (int)$t;
                if ($t > $now - $windowSec) {
                    $hits[] = $t;
                }
            }
        }
    }

    if (count($hits) >= $maxPerWindow) {
        return true;
    }

    $hits[] = $now;
    @file_put_contents($file, json_encode(['hits' => $hits]), LOCK_EX);

    return false;
}

/**
 * @return list<string>
 */
function strim_ai_admin_ids(): array
{
    $raw = trim((string)(getenv('STRIM_AI_ADMIN_IDS') ?: ($_ENV['STRIM_AI_ADMIN_IDS'] ?? '')));
    if ($raw === '') {
        return [];
    }
    return array_values(array_filter(array_map('trim', explode(',', $raw))));
}

function strim_ai_is_admin(string $userId): bool
{
    $admins = strim_ai_admin_ids();
    if ($admins === []) {
        return false;
    }
    return in_array(trim($userId), $admins, true);
}

/**
 * Опції reply для груп / тредів коментарів каналу.
 *
 * @param array<string,mixed> $msg
 * @return array<string,mixed>
 */
function strim_ai_thread_opts(array $msg, bool $isGroup): array
{
    $opts = [];
    if (!$isGroup) {
        return $opts;
    }

    $threadId = (int)($msg['message_thread_id'] ?? 0);
    if ($threadId > 0) {
        $opts['message_thread_id'] = $threadId;
    }

    return $opts;
}

/**
 * @param array<string,mixed> $msg
 * @return array<string,mixed>
 */
function strim_ai_reply_opts(array $msg, bool $isGroup, bool $withReply = true): array
{
    $opts = strim_ai_thread_opts($msg, $isGroup);
    if (!$isGroup || !$withReply) {
        return $opts;
    }

    $replyId = (int)($msg['message_id'] ?? 0);
    if ($replyId > 0) {
        $opts['reply_to_message_id'] = $replyId;
    }

    return $opts;
}

function strim_ai_is_chatid_command(string $text): bool
{
    $text = trim($text);
    if ($text === '') {
        return false;
    }

    $base = preg_replace('/@[\w_]+$/u', '', $text);
    $base = trim((string)$base);

    return strcasecmp($base, '/chatid') === 0;
}
