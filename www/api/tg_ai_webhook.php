<?php
declare(strict_types=1);

/**
 * Webhook бота StrIM AI (@strim_ai) + Gemini.
 *
 * Сценарії:
 *   A — приватні повідомлення боту → відповідь Gemini.
 *   B — коментарі в групі обговорення каналу → відповідь reply у треді.
 *
 * .env:
 *   STRIM_AI_TG_BOT_TOKEN=...
 *   STRIM_AI_WEBHOOK_SECRET=...       (обовʼязково)
 *   GEMINI_API_KEY=...
 *   GEMINI_MODEL=gemini-2.0-flash     (опційно)
 *   STRIM_AI_DISCUSSION_GROUP_ID=-100...  (ID групи «Обговорення»; якщо порожньо — бот логує chat_id)
 *   STRIM_AI_CHANNEL_ID=@strim_ai       (для /post від адміна)
 *   STRIM_AI_ADMIN_IDS=123456789        (Telegram user id, через кому)
 *   STRIM_AI_SYSTEM_PROMPT=...          (опційно, замість дефолту)
 *
 * Webhook:
 *   curl -sS "https://api.telegram.org/bot<TOKEN>/setWebhook?url=https://strim.in.ua/api/tg_ai_webhook.php&secret_token=<SECRET>&allowed_updates=%5B%22message%22%5D"
 */

if (!defined('ALLOW_UNAUTHENTICATED')) {
    define('ALLOW_UNAUTHENTICATED', true);
}

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_gemini_client.php';
require_once __DIR__ . '/_strim_ai_telegram.php';
require_once __DIR__ . '/_strim_ai_knowledge.php';

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

$secret = trim((string)($_ENV['STRIM_AI_WEBHOOK_SECRET'] ?? ''));
if ($secret === '') {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'STRIM_AI_WEBHOOK_SECRET missing'], JSON_UNESCAPED_UNICODE);
    exit;
}

$got = (string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');
if (!hash_equals($secret, $got)) {
    echo json_encode(['ok' => true, 'note' => 'secret']);
    exit;
}

$token = trim((string)($_ENV['STRIM_AI_TG_BOT_TOKEN'] ?? ''));
if ($token === '') {
    strim_ai_log('STRIM_AI_TG_BOT_TOKEN missing');
    echo json_encode(['ok' => true, 'note' => 'token']);
    exit;
}

$msg = $upd['message'] ?? null;
if (!is_array($msg)) {
    echo json_encode(['ok' => true]);
    exit;
}

$from = $msg['from'] ?? [];
if (!empty($from['is_bot'])) {
    echo json_encode(['ok' => true, 'note' => 'bot']);
    exit;
}

$chat = $msg['chat'] ?? [];
$chatId = isset($chat['id']) ? (string)$chat['id'] : '';
$chatType = (string)($chat['type'] ?? '');
$userId = isset($from['id']) ? (string)$from['id'] : '';
$text = trim((string)($msg['text'] ?? $msg['caption'] ?? ''));

if ($chatId === '' || $text === '') {
    echo json_encode(['ok' => true, 'note' => 'no text']);
    exit;
}

strim_ai_log('in ' . $chatType . ' chat=' . $chatId . ' uid=' . $userId . ' text=' . mb_substr($text, 0, 60));

$discussionId = trim((string)($_ENV['STRIM_AI_DISCUSSION_GROUP_ID'] ?? ''));
$isPrivate = ($chatType === 'private');
$isGroup = in_array($chatType, ['group', 'supergroup'], true);

if (!$isPrivate && !$isGroup) {
    echo json_encode(['ok' => true, 'note' => 'chat type']);
    exit;
}

// Службові команди — до фільтра групи (щоб /chatid працював у будь-якому чаті)
$isChatIdCmd = strim_ai_is_chatid_command($text);

if ($isChatIdCmd) {
    $info = "ID цього чату:\n{$chatId}\nТип: {$chatType}";
    if ($isGroup) {
        $info .= "\n\nДодайте в .env на сервері:\nSTRIM_AI_DISCUSSION_GROUP_ID={$chatId}";
    }
    // Без reply_to — інакше Telegram інколи повертає «message to be replied not found»
    [$okCid, $errCid] = strim_ai_tg_send_text($token, $chatId, $info, strim_ai_thread_opts($msg, $isGroup));
    if (!$okCid) {
        strim_ai_log("chatid send fail chat={$chatId} err={$errCid}");
    }
    echo json_encode(['ok' => true, 'chatid' => $okCid]);
    exit;
}

if ($isGroup && !$isChatIdCmd) {
    if ($discussionId !== '' && $chatId !== $discussionId) {
        echo json_encode(['ok' => true, 'note' => 'other group']);
        exit;
    }
    if ($discussionId === '') {
        strim_ai_log("hint: set STRIM_AI_DISCUSSION_GROUP_ID=$chatId (group message from {$userId})");
    }
}

// Команди
if (preg_match('~^/start(?:@\w+)?(?:\s|$)~u', $text)) {
    $welcome = "Вітаю! Я <b>StrIM AI</b> — асистент на базі Gemini.\n\n"
        . "• Допоможу розібратися з функціями StrIM. Не підбираю активи чи суми для особистих інвестицій.\n"
        . "• У коментарях каналу @strim_ai (група обговорення) — відповідаю на ваші коментарі.\n\n"
        . "Повідомлення обробляють Telegram і Google Gemini. Не надсилайте паролі, API-ключі чи документи. Деталі: https://strim.in.ua/privacy.html\nКоманда: /help";
    strim_ai_tg_send_text($token, $chatId, $welcome, [
        'parse_mode' => 'HTML',
        'reply_to_message_id' => $isGroup ? (int)($msg['message_id'] ?? 0) : 0,
        'message_thread_id' => (int)($msg['message_thread_id'] ?? 0) ?: null,
    ]);
    echo json_encode(['ok' => true]);
    exit;
}

if (preg_match('~^/help(?:@\w+)?\s*$~u', $text)) {
    $help = "Доступні команди:\n"
        . "/start — привітання\n"
        . "/help — ця довідка\n"
        . "/chatid — ID поточного чату (для групи обговорення)\n";
    if (strim_ai_is_admin($userId)) {
        $help .= "/post &lt;тема&gt; — згенерувати пост у канал (лише адмін)\n"
            . "/whoami — ваш Telegram user id\n";
    }
    strim_ai_tg_send_text($token, $chatId, $help, [
        'parse_mode' => 'HTML',
        'reply_to_message_id' => $isGroup ? (int)($msg['message_id'] ?? 0) : 0,
    ]);
    echo json_encode(['ok' => true]);
    exit;
}

if (preg_match('~^/whoami(?:@\w+)?\s*$~u', $text) && strim_ai_is_admin($userId)) {
    strim_ai_tg_send_text($token, $chatId, 'Ваш Telegram user id: ' . $userId, [
        'reply_to_message_id' => $isGroup ? (int)($msg['message_id'] ?? 0) : 0,
    ]);
    echo json_encode(['ok' => true]);
    exit;
}

if (preg_match('~^/post(?:@\w+)?\s+(.+)$~us', $text, $m) && strim_ai_is_admin($userId)) {
    $topic = trim((string)$m[1]);
    $channelId = trim((string)($_ENV['STRIM_AI_CHANNEL_ID'] ?? '@strim_ai'));
    if ($channelId === '') {
        strim_ai_tg_send_text($token, $chatId, 'STRIM_AI_CHANNEL_ID не задано.');
        echo json_encode(['ok' => true]);
        exit;
    }

    strim_ai_tg_typing($token, $chatId);
    $gen = strim_gemini_generate_channel_post($topic);
    if (!$gen['ok']) {
        strim_ai_log('gemini post fail: ' . $gen['error']);
        strim_ai_tg_send_text($token, $chatId, 'Не вдалося згенерувати пост: ' . $gen['error']);
        echo json_encode(['ok' => true]);
        exit;
    }

    [$sent, $err] = strim_ai_tg_send_text($token, $channelId, $gen['text']);
    if ($sent) {
        strim_ai_tg_send_text($token, $chatId, 'Опубліковано в канал.');
    } else {
        strim_ai_tg_send_text($token, $chatId, 'Помилка публікації: ' . $err);
    }
    echo json_encode(['ok' => true, 'posted' => $sent]);
    exit;
}

// Невідома команда
if (isset($text[0]) && $text[0] === '/') {
    strim_ai_tg_send_text(
        $token,
        $chatId,
        "Невідома команда. Спробуйте /help або /chatid",
        strim_ai_thread_opts($msg, $isGroup)
    );
    echo json_encode(['ok' => true, 'note' => 'unknown command']);
    exit;
}

if (strim_ai_rate_limited($userId)) {
    strim_ai_tg_send_text($token, $chatId, 'Занадто багато запитів. Зачекайте хвилину.', [
        'reply_to_message_id' => $isGroup ? (int)($msg['message_id'] ?? 0) : 0,
        'message_thread_id' => (int)($msg['message_thread_id'] ?? 0) ?: null,
    ]);
    echo json_encode(['ok' => true, 'note' => 'rate']);
    exit;
}

$escalated = false;
if (!strim_ai_is_admin($userId) && strim_ai_is_incident_report($text)) {
    strim_ai_escalate_to_admins($token, $msg, $text);
    $escalated = true;
}

$system = strim_ai_build_system_prompt();

$contextLabel = $isPrivate ? 'приватний чат з ботом' : 'коментар у групі обговорення @strim_ai';
$userPrompt = "Контекст: {$contextLabel}.\n\nПитання користувача:\n" . $text;
if ($escalated) {
    $userPrompt .= "\n\n[Система: звернення вже передано адміністратору. Подякуй, попроси деталі (сторінка, пара, час, скрін) і вкажи support@strim.in.ua.]";
}

strim_ai_tg_typing($token, $chatId);
$gen = strim_gemini_generate($userPrompt, $system);

if (!$gen['ok']) {
    strim_ai_log('gemini fail uid=' . $userId . ' err=' . $gen['error']);
    strim_ai_tg_send_text(
        $token,
        $chatId,
        "Тимчасово не можу відповісти. Спробуйте пізніше.\n(" . $gen['error'] . ')',
        [
            'reply_to_message_id' => $isGroup ? (int)($msg['message_id'] ?? 0) : 0,
            'message_thread_id' => (int)($msg['message_thread_id'] ?? 0) ?: null,
        ]
    );
    echo json_encode(['ok' => true, 'note' => 'gemini error']);
    exit;
}

$answer = strim_ai_format_channel_post_plain($gen['text']);
[$okSend, $sendErr] = strim_ai_tg_send_text($token, $chatId, $answer, strim_ai_reply_opts($msg, $isGroup));
if (!$okSend) {
    strim_ai_log('tg send fail: ' . $sendErr);
}

echo json_encode(['ok' => true, 'answered' => $okSend], JSON_UNESCAPED_UNICODE);
