<?php
declare(strict_types=1);

/**
 * База знань StrIM AI (публічне / заборонене) + ескалація помилок адміну.
 */

function strim_ai_knowledge_public(): string
{
    return <<<'KNOW'
## Про StrIM (можна розповідати)
- StrIM — український SaaS-кабінет для **спот-торгівлі на Binance** (strim.in.ua).
- Юрособа: ТОВ «СТРІМ АЛГО» (ЄДРПОУ 46241790).
- Користувач підключає **власні** API-ключі Binance (read + spot trade); кошти залишаються на Binance.
- Тест до першого поповнення: до 3 стратегій, FBA до 10 USDT, без фіксованого строку. Аванс від 500 грн, далі 10% позитивного результату КОЖНОГО продажу; збитки інших продажів не зменшують комісію. Після поповнення покупки поновлюються лише ручним Стартом. Умови та звернення: https://strim.in.ua/consumer.html. Власний невикористаний аванс можна повернути за процедурою https://strim.in.ua/returns.html. Прямі перекази USDT на гаманець StrIM не приймаються; Whitepay перераховує ТОВ гривні.
- Оплата: WhitePay (картка/крипто), сторінки /payment/wait.php → success після підтвердження в БД.
- Кабінет: стратегії, рівні сітки, журнал подій, портфель з Binance, підключення Telegram для сповіщень про угоди.
- Підтримка: support@strim.in.ua, тел. +380970664804.
- Канал: @strim_ai, бот спільноти: @ChatStrIM_bot.
- Торгівля ризикована; **немає гарантій прибутку**.

## Як користуватися (загальні поради)
- Підключити API Binance у кабінеті → обрати пару → налаштувати рівні → запустити стратегію.
- Запуск залежить від серверного права доступу та прийняття актуальних умов. Пауза покупок не припиняє продажі відкритих позицій. Не рекомендуй конкретні активи, суми, момент угоди або дії з портфелем конкретної людини. На персональне торгове запитання поясни межу: лише загальна технічна інструкція, рішення користувача.
- Telegram: у кабінеті згенерувати код → написати торговому боту /start CODE (окремий бот, не ChatStrIM).

## Заборонено розкривати користувачам
- Чужі персональні дані, email, API-ключі, паролі, tg_chat_id інших користувачів.
- Внутрішні шляхи сервера, .env, структуру БД, адмін-панель, SSH, вихідний код engine детально.
- Точні алгоритми «секретної» логіки engine, якщо не описано публічно на сайті.
- Обіцянки прибутку, «гарантовані» стратегії, інсайди.
- Не просити у користувача пароль від Binance, seed, 2FA-коди, повний API secret у чаті.

## Якщо питають про помилку в кабінеті
- Уточни: сторінка, пара, що натиснув, текст помилки, час (за Києвом).
- Запропонуй: оновити сторінку, перевірити API на Binance, підписку, support@strim.in.ua.
- Скажи, що звернення **передано команді** (якщо це схоже на збій — ми сповістимо адміна автоматично).
KNOW;
}

function strim_ai_build_system_prompt(): string
{
    $custom = trim((string)(getenv('STRIM_AI_SYSTEM_PROMPT') ?: ($_ENV['STRIM_AI_SYSTEM_PROMPT'] ?? '')));
    $base = $custom !== '' ? $custom : strim_ai_default_system_prompt_core();

    return $base . "\n\n---\nБАЗА ЗНАНЬ STRIM:\n" . strim_ai_knowledge_public();
}

function strim_ai_default_system_prompt_core(): string
{
    return <<<'PROMPT'
Ти — StrIM AI (@ChatStrIM_bot), асистент українською для спільноти StrIM (strim.in.ua, канал @strim_ai).

Стиль: дружній експерт, без води, без хайпу «заробляй мільйон».
Відповідай коротко (зазвичай до ~1200 символів), структуровано.
Не видавай себе за юриста чи інвестиційного радника.
Дотримуйся розділів «можна / заборонено» у базі знань нижче.
PROMPT;
}

/**
 * Чи схоже повідомлення на збій / звернення по кабінету.
 */
function strim_ai_is_incident_report(string $text): bool
{
    $t = mb_strtolower(trim($text));
    if ($t === '' || mb_strlen($t) < 12) {
        return false;
    }

    $needles = [
        'помилк', 'error', 'exception', 'не працю', 'не работ', 'зламал', 'баг', 'bug',
        'кабінет', 'dashboard', 'strim.in.ua', 'не вдалось', 'не вдалося', 'failed',
        '401', '403', '500', 'білінг', 'оплат', 'whitepay', 'api ключ', 'api key',
        'не запуска', 'не старту', 'стратегі', 'ордер', 'binance', 'withdraw',
    ];

    $hits = 0;
    foreach ($needles as $n) {
        if (mb_strpos($t, $n) !== false) {
            $hits++;
        }
    }

    if ($hits >= 2) {
        return true;
    }

    return $hits >= 1 && (
        preg_match('/\d{3}/', $t)
        || preg_match('/не\s+(працю|робить|заходить|відкрива)/u', $t)
        || preg_match('/(що|чому)\s+не/u', $t)
    );
}

/**
 * Сповістити адмінів (особисті повідомлення від бота).
 *
 * @param array<string,mixed> $msg Telegram message
 */
function strim_ai_escalate_to_admins(string $token, array $msg, string $userText): void
{
    $admins = strim_ai_admin_ids();
    if ($admins === []) {
        return;
    }

    $from = $msg['from'] ?? [];
    $uid = isset($from['id']) ? (string)$from['id'] : '';
    $uname = trim((string)($from['username'] ?? ''));
    $name = trim(((string)($from['first_name'] ?? '')) . ' ' . ((string)($from['last_name'] ?? '')));

    $chat = $msg['chat'] ?? [];
    $chatId = isset($chat['id']) ? (string)$chat['id'] : '';
    $chatType = (string)($chat['type'] ?? '');
    $chatTitle = trim((string)($chat['title'] ?? ''));

    if ($uid !== '' && strim_ai_incident_rate_limited($uid)) {
        return;
    }

    $who = $uname !== '' ? '@' . $uname : trim($name);
    if ($who === '') {
        $who = 'id ' . $uid;
    }

    $lines = [
        '🛠 <b>StrIM AI — звернення про проблему</b>',
        '',
        '<b>Від:</b> ' . htmlspecialchars($who, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . ' (user_id <code>' . htmlspecialchars($uid, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code>)',
        '<b>Чат:</b> ' . htmlspecialchars($chatType, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . ($chatTitle !== '' ? ' «' . htmlspecialchars($chatTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '»' : '')
            . ' <code>' . htmlspecialchars($chatId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code>',
        '',
        '<b>Текст:</b>',
        htmlspecialchars(mb_substr($userText, 0, 3500), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
    ];

    $owner = trim((string)(getenv('STRIM_AI_OWNER_USERNAME') ?: ($_ENV['STRIM_AI_OWNER_USERNAME'] ?? '')));
    if ($owner !== '') {
        $lines[] = '';
        $lines[] = 'Адмін: ' . htmlspecialchars($owner, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    $body = implode("\n", $lines);

    foreach ($admins as $adminChatId) {
        strim_ai_tg_send_text($token, $adminChatId, $body, ['parse_mode' => 'HTML']);
    }

    strim_ai_log('escalate uid=' . $uid . ' chat=' . $chatId);
}

function strim_ai_incident_rate_limited(string $userId): bool
{
    $userId = preg_replace('/\D+/', '', $userId);
    if ($userId === '') {
        return false;
    }

    $root = dirname(__DIR__, 2);
    $dir = $root . '/tmp/strim_ai_escalate';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    $file = $dir . '/u_' . $userId . '.ts';
    $now = time();
    $window = 1800; // 30 хв

    if (is_file($file)) {
        $prev = (int)@file_get_contents($file);
        if ($prev > $now - $window) {
            return true;
        }
    }

    @file_put_contents($file, (string)$now, LOCK_EX);
    return false;
}
