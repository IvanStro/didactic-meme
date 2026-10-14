<?php
declare(strict_types=1);

/**
 * Підтвердження email при реєстрації (6-значний код на пошту).
 */
function email_verify_required(): bool
{
    $v = $_ENV['EMAIL_VERIFY_REQUIRED'] ?? getenv('EMAIL_VERIFY_REQUIRED');
    if ($v === false || $v === null || $v === '') {
        return true;
    }
    $s = strtolower(trim((string) $v));

    return !in_array($s, ['0', 'false', 'no', 'off'], true);
}

function email_verify_ensure_table(mysqli $db): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $db->query("
        CREATE TABLE IF NOT EXISTS signup_email_verifications (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            email VARCHAR(255) NOT NULL,
            code_hash CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            verified_at DATETIME NULL,
            used_at DATETIME NULL,
            attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            request_ip VARCHAR(45) NULL,
            PRIMARY KEY (id),
            KEY idx_email_created (email, created_at),
            KEY idx_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $done = true;
}

function email_verify_normalize_email(string $email): string
{
    return strtolower(trim($email));
}

function email_verify_disposable_domain_list(): array
{
    static $list = null;
    if ($list !== null) {
        return $list;
    }

    $list = [
        // RFC / тестові
        'example.com', 'example.org', 'example.net', 'test.com', 'test.test',
        'invalid', 'localhost', 'email.test',
        // Популярні disposable (глобальні)
        'mailinator.com', 'yopmail.com', 'guerrillamail.com', 'guerrillamailblock.com',
        'sharklasers.com', 'grr.la', 'pokemail.net', 'spam4.me',
        'tempmail.com', 'temp-mail.org', 'temp-mail.io', 'tempmailo.com', 'tempmailo.org',
        '10minutemail.com', '10minutemail.net', '10minutemail.org', 'minuteinbox.com',
        'throwaway.email', 'throwawaymail.com', 'maildrop.cc', 'getnada.com', 'nada.ltd',
        'trashmail.com', 'trashmail.de', 'trashmail.me', 'trashmail.net',
        'mailnesia.com', 'discard.email', 'discardmail.com', 'fakeinbox.com',
        'mohmal.com', 'tempail.com', 'emailondeck.com', 'mintemail.com',
        'mytemp.email', 'tmpmail.net', 'tmpmail.org', 'tmpmail.com',
        'dispostable.com', 'mailcatch.com', 'spamgourmet.com', 'harakirimail.com',
        'burnermail.io', 'jetable.org', 'mailnull.com', 'mailforspam.com',
        'getairmail.com', 'mail-temp.com', 'tempr.email', 'inboxkitten.com',
        'crazymailing.com', 'mailpoof.com', 'fakemailgenerator.com',
        'emailfake.com', 'generator.email', 'mail-tester.com',
        // temp-mail.org / verifymail blocklist (ротаційні домени)
        'kinws.com', 'rfcdrive.com', 'letterprotect.net', 'lu.dog',
        'mailto.plus', 'fexpost.com', 'fexbox.org', 'mailbox.in.ua',
        'rover.info', 'merepost.com', 'dcctb.com', 'icznn.com',
        'laafd.com', 'ttirv.com', 'fxavaj.com', 'emlhub.com', 'emlpro.com',
        'emltmp.com', 'yoggm.com', 'wnbaldwy.com',
    ];

    return array_values(array_unique($list));
}

/** @return array{blocked:bool, reason?:string} */
function email_verify_blocked_domain(string $email): array
{
    $email = email_verify_normalize_email($email);
    if (!str_contains($email, '@')) {
        return ['blocked' => true, 'reason' => 'invalid_email'];
    }
    $domain = substr($email, (int) strrpos($email, '@') + 1);
    if ($domain === '' || !filter_var('x@' . $domain, FILTER_VALIDATE_EMAIL)) {
        return ['blocked' => true, 'reason' => 'invalid_email'];
    }

    if (in_array($domain, email_verify_disposable_domain_list(), true)) {
        return ['blocked' => true, 'reason' => 'blocked_domain'];
    }

    // Типові назви disposable-доменів (без блокування нормальних поштовиків)
    $domainPatterns = [
        '/(^|[.-])temp[-_.]?mail/i',
        '/(^|[.-])throwaway/i',
        '/(^|[.-])disposable/i',
        '/(^|[.-])trash[-_.]?mail/i',
        '/(^|[.-])fake[-_.]?mail/i',
        '/(^|[.-])spam[-_.]?box/i',
        '/guerrilla/i',
        '/mailinator/i',
        '/yopmail/i',
        '/10minutemail/i',
        '/minuteinbox/i',
        '/maildrop/i',
        '/getnada/i',
        '/mailnesia/i',
        '/dispostable/i',
        '/burnermail/i',
        '/tmpmail/i',
        '/tempail/i',
        '/fakemail/i',
        '/spamgourmet/i',
        '/harakirimail/i',
    ];
    foreach ($domainPatterns as $pattern) {
        if (preg_match($pattern, $domain)) {
            return ['blocked' => true, 'reason' => 'blocked_domain'];
        }
    }

    // Автотести/сканери: test-nonexistent-<digits>@...
    if (preg_match('/^(test|fake|null|noreply|no-reply)-/i', explode('@', $email)[0] ?? '')) {
        return ['blocked' => true, 'reason' => 'blocked_domain'];
    }

    return ['blocked' => false];
}

function email_verify_client_ip(): string
{
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return trim(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
    }

    return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
}

function email_verify_code_hash(string $email, string $code): string
{
    return hash('sha256', email_verify_normalize_email($email) . ':' . $code);
}

function email_verify_generate_code(): string
{
    return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

function email_verify_build_body(string $code, int $ttlMin): string
{
    return "Ваш код підтвердження для реєстрації в StrIM:\n\n"
        . "    {$code}\n\n"
        . "Код дійсний {$ttlMin} хвилин. Нікому не повідомляйте цей код.\n"
        . "Якщо ви не реєструвались на strim.in.ua — проігноруйте цей лист.\n\n"
        . "Підтримка: support@strim.in.ua\n";
}

/**
 * @return array{ok:bool, error?:string, retry_sec?:int}
 */
function email_verify_send_code(mysqli $db, string $email, int $ttlMinutes = 15): array
{
    email_verify_ensure_table($db);
    $email = email_verify_normalize_email($email);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'invalid_email'];
    }
    $block = email_verify_blocked_domain($email);
    if (!empty($block['blocked'])) {
        return ['ok' => false, 'error' => (string) ($block['reason'] ?? 'blocked_domain')];
    }

    $exists = $db->prepare('SELECT id FROM users WHERE email=? LIMIT 1');
    if ($exists) {
        $exists->bind_param('s', $email);
        $exists->execute();
        $er = $exists->get_result();
        if ($er && $er->fetch_assoc()) {
            $exists->close();

            return ['ok' => false, 'error' => 'email_taken'];
        }
        $exists->close();
    }

    $ip = email_verify_client_ip();
    $ipEsc = $db->real_escape_string($ip);
    $emailEsc = $db->real_escape_string($email);

    $ipCnt = $db->query(
        "SELECT COUNT(*) AS c FROM signup_email_verifications
         WHERE request_ip='{$ipEsc}' AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)"
    );
    if ($ipCnt && ($row = $ipCnt->fetch_assoc()) && (int) ($row['c'] ?? 0) >= 15) {
        return ['ok' => false, 'error' => 'rate_limit_ip', 'retry_sec' => 3600];
    }

    $mailCnt = $db->query(
        "SELECT COUNT(*) AS c FROM signup_email_verifications
         WHERE email='{$emailEsc}' AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)"
    );
    if ($mailCnt && ($row = $mailCnt->fetch_assoc()) && (int) ($row['c'] ?? 0) >= 5) {
        return ['ok' => false, 'error' => 'rate_limit_email', 'retry_sec' => 3600];
    }

    $recent = $db->query(
        "SELECT TIMESTAMPDIFF(SECOND, created_at, NOW()) AS age
         FROM signup_email_verifications
         WHERE email='{$emailEsc}' AND created_at > DATE_SUB(NOW(), INTERVAL 2 MINUTE)
         ORDER BY id DESC LIMIT 1"
    );
    if ($recent && ($row = $recent->fetch_assoc())) {
        $age = (int) ($row['age'] ?? 0);
        $wait = max(1, 120 - $age);

        return ['ok' => false, 'error' => 'cooldown', 'retry_sec' => $wait];
    }

    $code = email_verify_generate_code();
    $hash = email_verify_code_hash($email, $code);
    $ttl = max(5, min(60, $ttlMinutes));
    $stmt = $db->prepare(
        'INSERT INTO signup_email_verifications (email, code_hash, expires_at, request_ip)
         VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE), ?)'
    );
    if (!$stmt) {
        return ['ok' => false, 'error' => 'db_error'];
    }
    $stmt->bind_param('ssis', $email, $hash, $ttl, $ip);
    if (!$stmt->execute()) {
        $stmt->close();

        return ['ok' => false, 'error' => 'db_error'];
    }
    $insertId = (int) $stmt->insert_id;
    $stmt->close();

    require_once __DIR__ . '/_mail_send.php';
    $subject = 'StrIM — код підтвердження email';
    $body = email_verify_build_body($code, $ttl);
    $mailRes = strim_mail_send($email, $subject, $body, 'support@strim.in.ua');
    if (empty($mailRes['ok'])) {
        if ($insertId > 0) {
            $db->query('DELETE FROM signup_email_verifications WHERE id=' . $insertId . ' LIMIT 1');
        }
        error_log(
            'email_verify send failed email=' . $email
            . ' err=' . ($mailRes['error'] ?? 'unknown')
            . ' transport=' . ($mailRes['transport'] ?? '')
        );

        return ['ok' => false, 'error' => 'mail_failed'];
    }

    return ['ok' => true];
}

/**
 * Перевірка коду перед створенням акаунта (споживає код).
 *
 * @return array{ok:bool, error?:string}
 */
function email_verify_consume_code(mysqli $db, string $email, string $code): array
{
    if (!email_verify_required()) {
        return ['ok' => true];
    }

    email_verify_ensure_table($db);
    $email = email_verify_normalize_email($email);
    $code = preg_replace('/\D+/', '', trim($code)) ?? '';
    if ($email === '' || strlen($code) !== 6) {
        return ['ok' => false, 'error' => 'invalid_code'];
    }

    $stmt = $db->prepare(
        "SELECT id, code_hash, attempts FROM signup_email_verifications
         WHERE email=? AND expires_at > NOW() AND used_at IS NULL
         ORDER BY id DESC LIMIT 1"
    );
    if (!$stmt) {
        return ['ok' => false, 'error' => 'db_error'];
    }
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if (!$row) {
        return ['ok' => false, 'error' => 'code_expired'];
    }

    $id = (int) ($row['id'] ?? 0);
    $attempts = (int) ($row['attempts'] ?? 0);
    if ($attempts >= 5) {
        return ['ok' => false, 'error' => 'too_many_attempts'];
    }

    $expected = (string) ($row['code_hash'] ?? '');
    $actual = email_verify_code_hash($email, $code);
    if (!hash_equals($expected, $actual)) {
        $db->query('UPDATE signup_email_verifications SET attempts=attempts+1 WHERE id=' . $id . ' LIMIT 1');

        return ['ok' => false, 'error' => 'wrong_code'];
    }

    $db->query(
        'UPDATE signup_email_verifications SET verified_at=NOW(), used_at=NOW() WHERE id=' . $id . ' LIMIT 1'
    );

    return ['ok' => true];
}

/** Людські повідомлення для UI */
function email_verify_error_message(string $code): string
{
    return match ($code) {
        'invalid_email' => 'Введіть коректний email.',
        'blocked_domain' => 'Ця поштова адреса не підходить для реєстрації. Вкажіть реальний email (Gmail, ukr.net, iCloud тощо).',
        'email_taken' => 'Цей email вже зареєстрований. Увійдіть або відновіть пароль.',
        'rate_limit_ip', 'rate_limit_email' => 'Забагато запитів. Спробуйте пізніше.',
        'cooldown' => 'Зачекайте перед повторним надсиланням коду.',
        'mail_failed' => 'Не вдалося надіслати лист. Спробуйте пізніше або напишіть support@strim.in.ua.',
        'invalid_code' => 'Введіть 6-значний код з листа.',
        'code_expired' => 'Код застарів або не надсилався. Запишіть новий код.',
        'too_many_attempts' => 'Забагато невірних спроб. Запишіть новий код.',
        'wrong_code' => 'Невірний код. Перевірте лист або надішліть код знову.',
        'code_required' => 'Підтвердіть email: введіть код з листа.',
        default => 'Помилка підтвердження email.',
    };
}
