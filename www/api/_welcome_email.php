<?php
declare(strict_types=1);

/**
 * Вітальний лист після реєстрації.
 *
 * .env (опційно):
 *   STRIM_SUPPORT_EMAIL=support@strim.in.ua
 *   STRIM_INFO_TELEGRAM_URL=https://t.me/strim_ai
 *   STRIM_INFO_TELEGRAM_LABEL=@strim_ai
 *   APP_PUBLIC_URL=https://strim.in.ua
 */
function strim_welcome_support_email(): string
{
    $email = trim((string) ($_ENV['STRIM_SUPPORT_EMAIL'] ?? 'support@strim.in.ua'));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'support@strim.in.ua';
    }

    return $email;
}

function strim_welcome_telegram_url(): string
{
    $url = trim((string) ($_ENV['STRIM_INFO_TELEGRAM_URL'] ?? 'https://t.me/strim_ai'));
    if ($url === '' || !preg_match('~^https?://~i', $url)) {
        return 'https://t.me/strim_ai';
    }

    return $url;
}

function strim_welcome_telegram_label(): string
{
    $label = trim((string) ($_ENV['STRIM_INFO_TELEGRAM_LABEL'] ?? '@strim_ai'));

    return $label !== '' ? $label : '@strim_ai';
}

function strim_welcome_site_url(): string
{
    $fromEnv = trim((string) ($_ENV['APP_PUBLIC_URL'] ?? $_ENV['SITE_URL'] ?? ''));
    if ($fromEnv !== '') {
        return rtrim($fromEnv, '/');
    }

    return 'https://strim.in.ua';
}

function strim_welcome_build_body(string $displayName): string
{
    $name = trim($displayName);
    $greeting = $name !== '' ? "Вітаємо, {$name}!" : 'Вітаємо!';
    $support = strim_welcome_support_email();
    $tgUrl = strim_welcome_telegram_url();
    $tgLabel = strim_welcome_telegram_label();
    $site = strim_welcome_site_url();
    $dashboard = $site . '/dashboard.php';

    return $greeting . "\n\n"
        . "Дякуємо за реєстрацію в StrIM — сервісі автоматизованої спотової торгівлі на Binance.\n\n"
        . "Бажаємо вам вдалих і продуктивних торгів за допомогою нашого сервісу!\n\n"
        . "Після входу в кабінет ({$dashboard}) діє тест до першого поповнення: до 3 стратегій, перша покупка до 10 USDT. Це реальні угоди з ризиком збитків. Аванс від 500 грн, комісія 10% позитивного результату кожного продажу; збитки інших угод не зменшують її. Прийнята оферта: {$site}/legal/2026-09-23/offer.html . Усі документи: {$site}/legal/2026-09-23/ . Повернення та звернення: {$site}/consumer.html .\n"
        . "Далі можна підключити API-ключі Binance та налаштувати стратегію.\n\n"
        . "Якщо виникли труднощі з реєстрацією або входом — напишіть нам: {$support}\n\n"
        . "Також рекомендуємо підписатися на інформативний Telegram-канал:\n"
        . "{$tgLabel} — {$tgUrl}\n\n"
        . "З повагою,\n"
        . "Команда StrIM\n"
        . $site . "\n";
}

/**
 * @return array{ok:bool, error?:string, transport?:string}
 */
function strim_welcome_send(string $email, string $displayName = ''): array
{
    $email = trim($email);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'invalid_to'];
    }

    require_once __DIR__ . '/_mail_send.php';

    $subject = 'StrIM — ласкаво просимо!';
    $body = strim_welcome_build_body($displayName);

    return strim_mail_send($email, $subject, $body, strim_welcome_support_email());
}
