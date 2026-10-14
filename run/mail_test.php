<?php
declare(strict_types=1);

/**
 * Тест відправки листа з сервера (CLI):
 *   php run/mail_test.php your@email.com
 */
require __DIR__ . '/../www/vendor/autoload.php';
require_once __DIR__ . '/../www/api/_whitepay_dotenv.php';

Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
strim_whitepay_maybe_load_home_env();

require_once __DIR__ . '/../www/api/_mail_send.php';

$to = trim((string) ($argv[1] ?? ''));
if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage: php run/mail_test.php recipient@example.com\n");
    exit(1);
}

$from = strim_mail_from_address();
$body = "Тестовий лист StrIM.\nFrom: {$from}\nЧас: " . date('Y-m-d H:i:s') . "\n";

$res = strim_mail_send($to, 'StrIM — тест пошти', $body);
echo json_encode([
    'to' => $to,
    'from' => $from,
    'envelope' => strim_mail_envelope_from(),
    'transport_mode' => strim_mail_use_smtp() ? 'smtp' : 'mail',
    'smtp_host' => trim((string) ($_ENV['MAIL_SMTP_HOST'] ?? '')),
    'smtp_user_set' => trim((string) ($_ENV['MAIL_SMTP_USER'] ?? '')) !== '',
    'smtp_pass_set' => trim((string) ($_ENV['MAIL_SMTP_PASS'] ?? '')) !== '',
    'result' => $res,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

exit(!empty($res['ok']) ? 0 : 1);
