<?php
declare(strict_types=1);

/**
 * Текст сповіщення про міграцію хостингу / новий IP Binance whitelist.
 */

function strim_migration_outbound_ip(): string
{
    $ip = trim((string) ($_ENV['STRIM_BINANCE_OUTBOUND_IP'] ?? getenv('STRIM_BINANCE_OUTBOUND_IP') ?: ''));
    if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
        return $ip;
    }

    return '185.104.44.194';
}

function strim_migration_notice_subject(): string
{
    return 'StrIM — оновіть IP у налаштуваннях Binance API';
}

function strim_migration_notice_email_body(string $displayName = ''): string
{
    $greeting = trim($displayName) !== '' ? 'Вітаємо, ' . trim($displayName) . '!' : 'Вітаємо!';
    $ip = strim_migration_outbound_ip();
    $site = trim((string) ($_ENV['APP_PUBLIC_URL'] ?? 'https://strim.in.ua'));
    $site = rtrim($site !== '' ? $site : 'https://strim.in.ua', '/');

    return $greeting . "\n\n"
        . "Для покращення стабільності та подальшого розвитку проєкту StrIM ми перенесли платформу на новий сервер (тариф Business 2G).\n\n"
        . "Щоб бот продовжив працювати з вашим акаунтом Binance, потрібно оновити білий список IP у налаштуваннях API-ключа:\n\n"
        . "  Новий IP сервера StrIM: {$ip}\n\n"
        . "Як зробити:\n"
        . "1. Binance → API Management → ваш ключ → Edit restrictions\n"
        . "2. У блоці «IP access restrictions» додайте IP {$ip}\n"
        . "   (старі IP StrIM можна видалити)\n"
        . "3. Збережіть зміни та оновіть кабінет: {$site}/dashboard.php\n\n"
        . "Якщо білий список IP не використовуєте — перевірте, що ключ активний і має права Spot + Read.\n\n"
        . "Детальна інструкція: {$site}/instruction.html\n"
        . "Підтримка: support@strim.in.ua\n\n"
        . "Дякуємо, що користуєтесь StrIM!\n"
        . "Команда StrIM\n";
}

function strim_migration_notice_telegram_html(string $displayName = ''): string
{
    $name = trim($displayName);
    $hello = $name !== '' ? 'Вітаємо, ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '!' : 'Вітаємо!';
    $ip = htmlspecialchars(strim_migration_outbound_ip(), ENT_QUOTES, 'UTF-8');
    $site = htmlspecialchars(
        rtrim(trim((string) ($_ENV['APP_PUBLIC_URL'] ?? 'https://strim.in.ua')) ?: 'https://strim.in.ua', '/'),
        ENT_QUOTES,
        'UTF-8'
    );

    return "<b>StrIM — оновлення після міграції сервера</b>\n\n"
        . "{$hello}\n\n"
        . "Для стабільності та розвитку проєкту ми перенесли StrIM на новий сервер (<b>Business 2G</b>).\n\n"
        . "Щоб торгівля продовжилась, <b>додайте новий IP у Binance API whitelist</b>:\n\n"
        . "<code>{$ip}</code>\n\n"
        . "Binance → API Management → Edit restrictions → IP access → додати цю адресу.\n"
        . "Старі IP StrIM можна прибрати.\n\n"
        . "Після зміни — оновіть <a href=\"{$site}/dashboard.php\">кабінет</a>.\n"
        . "Інструкція: <a href=\"{$site}/instruction.html\">instruction.html</a>\n\n"
        . "Питання: support@strim.in.ua";
}
