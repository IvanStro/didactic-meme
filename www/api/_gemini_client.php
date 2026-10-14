<?php
declare(strict_types=1);

/**
 * Google Gemini (Generative Language API) — мінімальний REST-клієнт без SDK.
 *
 * .env:
 *   GEMINI_API_KEY=...
 *   GEMINI_MODEL=gemini-2.5-flash   (опційно)
 */

/**
 * @param array<string,mixed> $genConfig додаткові поля generationConfig (maxOutputTokens тощо)
 * @return array{ok:bool,text:string,error:string,finish_reason:string}
 */
function strim_gemini_generate(string $userPrompt, ?string $systemInstruction = null, array $genConfig = []): array
{
    $apiKey = trim((string)(getenv('GEMINI_API_KEY') ?: ($_ENV['GEMINI_API_KEY'] ?? '')));
    if ($apiKey === '') {
        return ['ok' => false, 'text' => '', 'error' => 'GEMINI_API_KEY missing', 'finish_reason' => ''];
    }

    $model = trim((string)(getenv('GEMINI_MODEL') ?: ($_ENV['GEMINI_MODEL'] ?? 'gemini-2.5-flash')));
    if ($model === '') {
        $model = 'gemini-2.5-flash';
    }

    $userPrompt = trim($userPrompt);
    if ($userPrompt === '') {
        return ['ok' => false, 'text' => '', 'error' => 'empty prompt', 'finish_reason' => ''];
    }

    $generationConfig = array_merge(
        [
            'temperature' => 0.75,
            'maxOutputTokens' => 2048,
        ],
        $genConfig
    );

    $body = [
        'contents' => [
            [
                'role' => 'user',
                'parts' => [['text' => $userPrompt]],
            ],
        ],
        'generationConfig' => $generationConfig,
    ];

    if ($systemInstruction !== null && trim($systemInstruction) !== '') {
        $body['systemInstruction'] = [
            'parts' => [['text' => trim($systemInstruction)]],
        ];
    }

    $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
        . rawurlencode($model)
        . ':generateContent?key=' . rawurlencode($apiKey);

    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'text' => '', 'error' => 'curl_init failed', 'finish_reason' => ''];
    }

    $json = json_encode($body, JSON_UNESCAPED_UNICODE);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => $json,
    ]);

    $resp = curl_exec($ch);
    $errNo = curl_errno($ch);
    $err = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errNo) {
        return ['ok' => false, 'text' => '', 'error' => "curl=$errNo $err", 'finish_reason' => ''];
    }

    $data = is_string($resp) ? json_decode($resp, true) : null;
    if (!is_array($data)) {
        return ['ok' => false, 'text' => '', 'error' => 'bad json from gemini', 'finish_reason' => ''];
    }

    if ($http >= 400 || !empty($data['error'])) {
        $msg = '';
        if (!empty($data['error']['message'])) {
            $msg = (string)$data['error']['message'];
        } else {
            $msg = 'http=' . $http;
        }
        return ['ok' => false, 'text' => '', 'error' => $msg, 'finish_reason' => ''];
    }

    $finish = (string)($data['candidates'][0]['finishReason'] ?? '');
    $text = strim_gemini_extract_text($data);
    if ($text === '') {
        return ['ok' => false, 'text' => '', 'error' => 'empty model response', 'finish_reason' => $finish];
    }

    return ['ok' => true, 'text' => $text, 'error' => '', 'finish_reason' => $finish];
}

/**
 * @param array<string,mixed> $data
 */
function strim_gemini_extract_text(array $data): string
{
    $parts = $data['candidates'][0]['content']['parts'] ?? null;
    if (!is_array($parts)) {
        return '';
    }

    $chunks = [];
    foreach ($parts as $part) {
        if (!is_array($part)) {
            continue;
        }
        $t = trim((string)($part['text'] ?? ''));
        if ($t !== '') {
            $chunks[] = $t;
        }
    }

    return trim(implode("\n", $chunks));
}

/**
 * Генерація поста для Telegram-каналу (довший ліміт, без «думок» моделі).
 *
 * @return array{ok:bool,text:string,error:string,finish_reason:string}
 */
function strim_gemini_generate_channel_post(string $topic): array
{
    $topic = trim($topic);
    $prompt = strim_ai_channel_post_user_prompt($topic);
    $system = strim_ai_channel_post_system_prompt();

    $genConfig = [
        'temperature' => 0.88,
        'maxOutputTokens' => 8192,
        'thinkingConfig' => ['thinkingBudget' => 0],
    ];

    $result = strim_gemini_generate($prompt, $system, $genConfig);
    if (!$result['ok']) {
        unset($genConfig['thinkingConfig']);
        $result = strim_gemini_generate($prompt, $system, $genConfig);
    }

    if (!$result['ok']) {
        return $result;
    }

    $plain = strim_ai_format_channel_post_plain($result['text']);
    $truncated = strim_ai_post_looks_truncated($plain)
        || strtoupper($result['finish_reason']) === 'MAX_TOKENS';

    if ($truncated) {
        $cont = strim_gemini_generate(
            "Допиши цей пост до логічного завершення (1–2 речення + короткий висновок + CTA). "
            . "Поверни ВЕСЬ пост цілком, не лише продовження:\n\n" . $result['text'],
            $system,
            ['temperature' => 0.7, 'maxOutputTokens' => 4096, 'thinkingConfig' => ['thinkingBudget' => 0]]
        );
        if ($cont['ok'] && mb_strlen($cont['text']) > mb_strlen($result['text'])) {
            $plain = strim_ai_format_channel_post_plain($cont['text']);
        }
    }

    $result['text'] = $plain;
    return $result;
}

function strim_ai_channel_post_system_prompt(): string
{
    return <<<'PROMPT'
Ти — редактор Telegram-каналу StrIM (strim.in.ua): спот-торгівля на Binance, дисципліна, автоматизація, українська аудиторія.

Стиль поста:
- Живий, експертний, без води та канцеляриту; не суха «стаття».
- Сильний заголовок (перший рядок, можна 1 доречний емодзі).
- 4–6 коротких абзаців; між абзацами — порожній рядок.
- 1 блок з 3–4 пунктами через символ • (конкретика, не загальні фрази).
- 1 рядок про ризики (без гарантій прибутку).
- Фінал: заклик підписатися / спробувати StrIM → strim.in.ua

Формат: ЗВИЧАЙНИЙ ТЕКСТ для Telegram. Заборонено HTML, markdown, теги <b>, **, #.
Заголовок — ПЕРШИЙ РЯДОК капсом або з 1 емодзі на початку (без зірочок і тегів).
Обсяг: 900–1500 символів. Останнє речення ОБОВʼЯЗКОВО завершене (. ! ?).
PROMPT;
}

function strim_ai_channel_post_user_prompt(string $topic): string
{
    return "Напиши готовий пост для каналу StrIM на тему: «{$topic}».\n"
        . "Не пояснюй, що ти робиш — лише текст поста для публікації.";
}

/**
 * Пост для каналу — лише чистий текст (без HTML-тегів у повідомленні).
 */
function strim_ai_format_channel_post_plain(string $raw): string
{
    $t = trim($raw);
    $t = preg_replace('/^#+\s*/m', '', $t);
    $t = preg_replace('/\r\n?/', "\n", $t);
    $t = preg_replace('/<\/?[bi]>/iu', '', $t);
    $t = preg_replace('/\*\*(.+?)\*\*/us', '$1', $t);
    $t = preg_replace('/__(.+?)__/us', '$1', $t);
    $t = preg_replace('/\*(.+?)\*/us', '$1', $t);
    $t = strip_tags($t);
    $t = preg_replace("/\n{3,}/", "\n\n", $t);

    return trim($t);
}

function strim_ai_post_looks_truncated(string $text): bool
{
    $plain = trim(strip_tags($text));
    if (mb_strlen($plain) < 80) {
        return false;
    }
    if (preg_match('/[.!?…»"\'\d]\s*$/u', $plain)) {
        return false;
    }

    return (bool)preg_match('/[\p{L}]$/u', $plain);
}

/** @deprecated використовуй strim_ai_build_system_prompt() */
function strim_ai_default_system_prompt(): string
{
    return strim_ai_build_system_prompt();
}
