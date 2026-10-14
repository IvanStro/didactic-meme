<?php
declare(strict_types=1);

/**
 * Розсилка про новий IP Binance після міграції хостингу.
 *
 *   php run/notify_binance_ip_migration.php --dry-run
 *   php run/notify_binance_ip_migration.php --send
 *   php run/notify_binance_ip_migration.php --send --user-id=5
 *   php run/notify_binance_ip_migration.php --send --only-with-api-keys
 */
$root = dirname(__DIR__);
require $root . '/www/vendor/autoload.php';
require_once $root . '/www/api/_whitepay_dotenv.php';
require_once $root . '/www/api/_migration_notice.php';
require_once $root . '/www/api/_mail_send.php';
require_once $root . '/www/api/_tg_notify.php';

Dotenv\Dotenv::createImmutable($root)->safeLoad();
strim_whitepay_maybe_load_home_env();

$send = in_array('--send', $argv, true);
$dryRun = !$send;
$onlyApi = in_array('--only-with-api-keys', $argv, true);
$onlyTg = in_array('--tg-only', $argv, true);
$onlyEmail = in_array('--email-only', $argv, true);
$userIdFilter = 0;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--user-id=')) {
        $userIdFilter = (int) substr($arg, 10);
    }
}

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

$where = ['1=1'];
if ($userIdFilter > 0) {
    $where[] = 'id=' . (int) $userIdFilter;
}
if ($onlyApi) {
    $where[] = "api_key_enc IS NOT NULL AND api_key_enc <> ''";
}
$sql = 'SELECT id, email, nickname, full_name, tg_enabled, tg_chat_id FROM users WHERE ' . implode(' AND ', $where) . ' ORDER BY id ASC';
$res = $m->query($sql);
if (!$res) {
    fwrite(STDERR, 'query failed' . PHP_EOL);
    exit(1);
}

$ip = strim_migration_outbound_ip();
$token = trim((string) ($_ENV['TG_BOT_TOKEN'] ?? ''));
$subject = strim_migration_notice_subject();

echo date('c') . ' mode=' . ($dryRun ? 'dry-run' : 'send') . " ip={$ip} only_api=" . ($onlyApi ? '1' : '0') . PHP_EOL;

$stats = ['users' => 0, 'email_ok' => 0, 'email_fail' => 0, 'email_skip' => 0, 'tg_ok' => 0, 'tg_fail' => 0, 'tg_skip' => 0];

while ($row = $res->fetch_assoc()) {
    $stats['users']++;
    $uid = (int) ($row['id'] ?? 0);
    $email = trim((string) ($row['email'] ?? ''));
    $name = trim((string) ($row['nickname'] ?? ''));
    if ($name === '') {
        $name = trim((string) ($row['full_name'] ?? ''));
    }
    $tgOk = (int) ($row['tg_enabled'] ?? 0) === 1 && trim((string) ($row['tg_chat_id'] ?? '')) !== '';
    $chatId = trim((string) ($row['tg_chat_id'] ?? ''));

    echo "uid={$uid} email=" . ($email !== '' ? $email : '-') . ' tg=' . ($tgOk ? $chatId : '-') . PHP_EOL;

    if (!$onlyTg && $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $body = strim_migration_notice_email_body($name);
        if ($dryRun) {
            echo "  [dry] email -> {$email}\n";
            $stats['email_ok']++;
        } else {
            $mailRes = strim_mail_send($email, $subject, $body, 'support@strim.in.ua');
            if (!empty($mailRes['ok'])) {
                echo "  email OK\n";
                $stats['email_ok']++;
            } else {
                echo '  email FAIL ' . ($mailRes['error'] ?? '?') . PHP_EOL;
                $stats['email_fail']++;
            }
            usleep(300_000);
        }
    } elseif (!$onlyTg) {
        $stats['email_skip']++;
    }

    if (!$onlyEmail && $tgOk && $token !== '') {
        $html = strim_migration_notice_telegram_html($name);
        if ($dryRun) {
            echo "  [dry] telegram -> {$chatId}\n";
            $stats['tg_ok']++;
        } else {
            [$ok, $err] = tg_send_html_raw($token, $chatId, $html);
            if ($ok) {
                echo "  tg OK\n";
                $stats['tg_ok']++;
            } else {
                echo "  tg FAIL {$err}\n";
                $stats['tg_fail']++;
            }
            usleep(400_000);
        }
    } elseif (!$onlyEmail) {
        $stats['tg_skip']++;
    }
}

echo json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
exit(($stats['email_fail'] + $stats['tg_fail']) > 0 ? 1 : 0);
