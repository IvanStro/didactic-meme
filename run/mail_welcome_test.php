<?php
declare(strict_types=1);

/**
 * Превʼю / тест вітального листа (CLI):
 *   php run/mail_welcome_test.php your@email.com "Імʼя"
 */
require __DIR__ . '/../www/vendor/autoload.php';
require_once __DIR__ . '/../www/api/_whitepay_dotenv.php';

Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
strim_whitepay_maybe_load_home_env();

require_once __DIR__ . '/../www/api/_welcome_email.php';

$to = trim((string) ($argv[1] ?? ''));
$name = trim((string) ($argv[2] ?? 'Тестовий користувач'));
if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage: php run/mail_welcome_test.php recipient@example.com [display_name]\n");
    exit(1);
}

$res = strim_welcome_send($to, $name);
echo json_encode([
    'to' => $to,
    'name' => $name,
    'subject' => 'StrIM — ласкаво просимо!',
    'body_preview' => strim_welcome_build_body($name),
    'result' => $res,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

exit(!empty($res['ok']) ? 0 : 1);
