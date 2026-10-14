<?php
declare(strict_types=1);
/**
 * Розсилка про оновлення білінгу (percent model).
 *
 *   php run/notify_billing_percent_update.php --dry-run
 *   php run/notify_billing_percent_update.php --send
 */
$root = dirname(__DIR__);
require $root . '/www/vendor/autoload.php';
require_once $root . '/www/api/_tg_notify.php';

Dotenv\Dotenv::createImmutable($root)->safeLoad();

$send = in_array('--send', $argv ?? [], true);
$dryRun = !$send;

$html = <<<'HTML'
🔔 <b>Оновлення системи оплати StrIM</b>

Шановні користувачі!

Змінили модель оплати користуванням сервісу.

<b>Було:</b> фіксована підписка 500 грн / 30 днів.

<b>Стало:</b>
• поповнення балансу від <b>500 грн</b> (зараховується в USDT за курсом Binance на момент зарахування);
• комісія платформи — <b>10% лише від позитивного реалізованого прибутку</b>.

<b>До першого поповнення (trial):</b> до 3 пар, FBA ≤ 10 USDT.

Користувачам, у яких на момент переходу вже було проплачене користування програмою, нараховано на баланс <b>еквівалент 500 грн</b> (у USDT за курсом на момент міграції).

Реквізити оплати без змін (Privat / Whitepay). Баланс і історія списань — у кабінеті.

Деталі: https://strim.in.ua/tariffs.html
Підтримка: @strim_ai · support@strim.in.ua

Дякуємо, що з нами.
Команда StrIM
HTML;

$m = new mysqli(
    (string) ($_ENV['DB_HOST'] ?? ''),
    (string) ($_ENV['DB_USER'] ?? ''),
    (string) ($_ENV['DB_PASS'] ?? ''),
    (string) ($_ENV['DB_NAME'] ?? '')
);
if ($m->connect_errno) {
    fwrite(STDERR, 'db: ' . $m->connect_error . PHP_EOL);
    exit(1);
}
$m->set_charset('utf8mb4');

$token = trim((string) ($_ENV['TG_BOT_TOKEN'] ?? ''));
if ($token === '') {
    fwrite(STDERR, "TG_BOT_TOKEN empty\n");
    exit(1);
}

$res = $m->query(
    "SELECT id, email, nickname, tg_chat_id
     FROM users
     WHERE tg_enabled = 1
       AND tg_chat_id IS NOT NULL
       AND TRIM(tg_chat_id) <> ''
     ORDER BY id ASC"
);
if (!$res) {
    fwrite(STDERR, "query failed\n");
    exit(1);
}

echo date('c') . ' mode=' . ($dryRun ? 'dry-run' : 'send') . PHP_EOL;

$stats = ['candidates' => 0, 'tg_ok' => 0, 'tg_fail' => 0];
while ($row = $res->fetch_assoc()) {
    $stats['candidates']++;
    $uid = (int) $row['id'];
    $chatId = trim((string) $row['tg_chat_id']);
    $nick = (string) ($row['nickname'] ?: $row['email'] ?: ('uid' . $uid));
    echo "uid={$uid} {$nick} chat={$chatId}\n";
    if ($dryRun) {
        $stats['tg_ok']++;
        continue;
    }
    if (function_exists('tg_send_html_raw')) {
        [$ok, $err] = tg_send_html_raw($token, $chatId, $html);
    } else {
        $url = "https://api.telegram.org/bot{$token}/sendMessage";
        $payload = [
            'chat_id' => $chatId,
            'text' => $html,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
        ]);
        $resp = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $ok = ($http > 0 && $http < 400);
        $err = $ok ? 'sent' : "http={$http} resp={$resp}";
    }
    if ($ok) {
        echo "  tg OK\n";
        $stats['tg_ok']++;
    } else {
        echo "  tg FAIL {$err}\n";
        $stats['tg_fail']++;
    }
    usleep(400_000);
}

echo json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
exit($stats['tg_fail'] > 0 ? 1 : 0);
