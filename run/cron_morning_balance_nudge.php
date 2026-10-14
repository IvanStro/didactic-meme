<?php
declare(strict_types=1);
/**
 * Щоденне м’яке нагадування про баланс платформи → Telegram користувача.
 * Комісія 10% від +realized → баланс X USDT «покриває» ≈ X/0.10 профіту.
 *
 * Cron (Kyiv, сервер EEST): 0 9 * * *
 *   /bin/bash /home/bot20/strim.in.ua/run/cron_morning_balance_nudge.sh
 *
 * Env:
 *   MORNING_BALANCE_NUDGE=1   (0 = вимкнути)
 *   MORNING_BALANCE_NUDGE_DRY=1
 */
$root = dirname(__DIR__);
require $root . '/www/vendor/autoload.php';
require_once __DIR__ . '/cron_singleton.php';
require_once __DIR__ . '/cron_host_guard.php';

cron_singleton_acquire('cron_morning_balance_nudge');
set_time_limit(180);

Dotenv\Dotenv::createImmutable($root)->safeLoad();
cron_host_guard_or_exit('cron_morning_balance_nudge');

require_once $root . '/www/api/_billing_access.php';
require_once $root . '/www/api/_tg_notify.php';

$enabled = (string)(getenv('MORNING_BALANCE_NUDGE') ?: ($_ENV['MORNING_BALANCE_NUDGE'] ?? '1'));
if ($enabled === '0') {
    echo date('c') . " morning_balance_nudge disabled\n";
    exit(0);
}

$dry = ((string)(getenv('MORNING_BALANCE_NUDGE_DRY') ?: ($_ENV['MORNING_BALANCE_NUDGE_DRY'] ?? '0')) === '1')
    || in_array('--dry', $argv ?? [], true);

$token = trim((string)($_ENV['TG_BOT_TOKEN'] ?? ''));
if ($token === '') {
    fwrite(STDERR, date('c') . " TG_BOT_TOKEN empty\n");
    exit(1);
}

$db = new mysqli(
    (string)($_ENV['DB_HOST'] ?? ''),
    (string)($_ENV['DB_USER'] ?? ''),
    (string)($_ENV['DB_PASS'] ?? ''),
    (string)($_ENV['DB_NAME'] ?? '')
);
if ($db->connect_errno) {
    fwrite(STDERR, date('c') . ' db: ' . $db->connect_error . "\n");
    exit(1);
}
$db->set_charset('utf8mb4');

date_default_timezone_set('Europe/Kyiv');

$feeRate = 0.10;
$payUrl = 'https://strim.in.ua/tariffs.html';

/**
 * @param array<string,mixed> $u
 */
function morning_nudge_html(array $u, float $feeRate, string $payUrl): string
{
    $name = trim((string)($u['nickname'] ?? ''));
    if ($name === '') {
        $name = trim((string)($u['full_name'] ?? ''));
    }
    $hello = $name !== '' ? ('Доброго ранку, <b>' . htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</b>!') : 'Доброго ранку!';

    $eff = function_exists('billing_user_row_effective_status')
        ? billing_user_row_effective_status($u)
        : 'trial';
    $bal = (float)($u['balance_usdt'] ?? 0);
    $sf = (float)($u['fee_shortfall_usdt'] ?? 0);
    $cover = ($feeRate > 1e-12) ? ($bal / $feeRate) : 0.0;

    $lines = [];
    $lines[] = '☀️ ' . $hello;
    $lines[] = '';
    $lines[] = 'Коротке нагадування про баланс StrIM.';

    if ($eff === 'blocked') {
        $lines[] = '';
        $lines[] = 'Акаунт тимчасово обмежено. Напишіть у підтримку: @strim_ai';
        return implode("\n", $lines);
    }

    if ($eff === 'trial') {
        $lines[] = '';
        $lines[] = 'Зараз у вас <b>trial</b> (до першого поповнення): до 3 пар, FBA ≤ 10 USDT.';
        $lines[] = 'Після поповнення від <b>500 грн</b> баланс піде на комісію <b>10%</b> лише від позитивного реалізованого прибутку.';
        $lines[] = '';
        $lines[] = 'Поповнити: ' . $payUrl;
        return implode("\n", $lines);
    }

    if ($sf > 1e-6) {
        $lines[] = '';
        $lines[] = 'Баланс: <b>' . number_format(max(0.0, $bal), 2, '.', ' ') . ' USDT</b>';
        $lines[] = 'Є незакритий борг комісії ≈ <b>' . number_format($sf, 2, '.', ' ') . ' USDT</b> — наступне поповнення погасить його автоматично.';
        $lines[] = '';
        $lines[] = 'Поповнити від 500 грн: ' . $payUrl;
        return implode("\n", $lines);
    }

    if ($eff === 'exhausted' || $bal <= 1e-8) {
        $lines[] = '';
        $lines[] = 'Баланс вичерпано. Нові докупки на паузі; відкриті позиції можуть дозакриватися по TP.';
        $lines[] = 'Поповнення від <b>500 грн</b> знову відкриє торгівлю.';
        $lines[] = '';
        $lines[] = 'Поповнити: ' . $payUrl;
        return implode("\n", $lines);
    }

    // funded / active
    $lines[] = '';
    $lines[] = 'Ваш баланс: <b>' . number_format($bal, 2, '.', ' ') . ' USDT</b>';
    $lines[] = 'При комісії 10% від позитивного реалізованого прибутку цього вистачить приблизно на <b>~'
        . number_format($cover, 2, '.', ' ')
        . ' USDT</b> такого профіту (поки баланс не скінчиться).';
    $lines[] = '';
    $lines[] = 'Кабінет / поповнення: ' . $payUrl;
    $lines[] = 'Гарного дня 🌿';

    return implode("\n", $lines);
}

$cols = 'id, email, nickname, full_name, tg_chat_id, billing_status, trial_until, paid_until';
if (billing_percent_mode_active($db)) {
    $cols .= ', balance_usdt, fee_shortfall_usdt, wallet_activated_at';
}

$sql = "SELECT {$cols}
        FROM users
        WHERE tg_enabled = 1
          AND tg_chat_id IS NOT NULL
          AND TRIM(tg_chat_id) <> ''
        ORDER BY id ASC";
$res = $db->query($sql);
if (!$res) {
    fwrite(STDERR, date('c') . " query fail: {$db->error}\n");
    exit(1);
}

$stats = ['candidates' => 0, 'ok' => 0, 'fail' => 0, 'skip' => 0];
echo date('c') . ' morning_balance_nudge mode=' . ($dry ? 'dry' : 'send') . "\n";

while ($row = $res->fetch_assoc()) {
    $stats['candidates']++;
    $uid = (int)$row['id'];
    $chat = trim((string)$row['tg_chat_id']);
    $html = morning_nudge_html($row, $feeRate, $payUrl);
    $nick = (string)($row['nickname'] ?: $row['email'] ?: ('uid' . $uid));
    echo "uid={$uid} {$nick} chat={$chat}\n";

    if ($dry) {
        echo "  [dry] " . str_replace("\n", ' | ', preg_replace('/<[^>]+>/', '', $html)) . "\n";
        $stats['ok']++;
        continue;
    }

    [$ok, $err] = tg_send_html_raw($token, $chat, $html, ['silent' => true]);
    if ($ok) {
        echo "  tg OK\n";
        $stats['ok']++;
    } else {
        echo "  tg FAIL {$err}\n";
        $stats['fail']++;
    }
    usleep(350_000);
}

echo json_encode($stats, JSON_UNESCAPED_UNICODE) . "\n";
exit($stats['fail'] > 0 ? 1 : 0);
