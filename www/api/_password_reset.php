<?php
declare(strict_types=1);

/**
 * Скидання пароля: токени в БД, інвалідація сесій, сповіщення email/Telegram.
 */
function password_reset_ensure_table(mysqli $db): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $db->query("
        CREATE TABLE IF NOT EXISTS password_reset_tokens (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT UNSIGNED NOT NULL,
            token_hash CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            request_ip VARCHAR(45) NULL,
            PRIMARY KEY (id),
            KEY idx_user_created (user_id, created_at),
            KEY idx_token_hash (token_hash),
            KEY idx_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $done = true;
}

function password_reset_base_url(): string
{
    $fromEnv = trim((string) ($_ENV['APP_PUBLIC_URL'] ?? $_ENV['SITE_URL'] ?? ''));
    if ($fromEnv !== '') {
        return rtrim($fromEnv, '/');
    }
    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? 'strim.in.ua'));
    if ($host === '') {
        $host = 'strim.in.ua';
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

    return ($https ? 'https' : 'https') . '://' . $host;
}

function password_reset_client_ip(): string
{
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return trim(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
    }

    return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
}

function password_reset_invalidate_sessions(mysqli $db, int $userId): void
{
    if ($userId <= 0) {
        return;
    }
    $db->query('DELETE FROM sessions_auth WHERE user_id=' . (int) $userId);
}

function password_reset_set_password(mysqli $db, int $userId, string $plainPassword): bool
{
    if ($userId <= 0 || strlen($plainPassword) < 6) {
        return false;
    }
    $hash = password_hash($plainPassword, PASSWORD_BCRYPT);
    $stmt = $db->prepare('UPDATE users SET pass_hash=? WHERE id=? LIMIT 1');
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('si', $hash, $userId);
    $ok = $stmt->execute();
    $stmt->close();
    if ($ok) {
        password_reset_invalidate_sessions($db, $userId);
    }

    return $ok;
}

function password_reset_generate_plain(int $length = 12): string
{
    $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $out = '';
    $max = strlen($chars) - 1;
    for ($i = 0; $i < $length; $i++) {
        $out .= $chars[random_int(0, $max)];
    }

    return $out;
}

/**
 * @return array{ok:bool, token?:string, error?:string}
 */
function password_reset_create_token(mysqli $db, int $userId, int $ttlMinutes = 60): array
{
    password_reset_ensure_table($db);
    if ($userId <= 0) {
        return ['ok' => false, 'error' => 'invalid_user'];
    }

    $recent = $db->query(
        'SELECT COUNT(*) AS c FROM password_reset_tokens WHERE user_id=' . (int) $userId .
        " AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR) AND used_at IS NULL"
    );
    $cnt = 0;
    if ($recent && ($r = $recent->fetch_assoc())) {
        $cnt = (int) ($r['c'] ?? 0);
    }
    if ($cnt >= 5) {
        return ['ok' => false, 'error' => 'rate_limit'];
    }

    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    $ip = password_reset_client_ip();
    $stmt = $db->prepare(
        'INSERT INTO password_reset_tokens (user_id, token_hash, expires_at, request_ip) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE), ?)'
    );
    if (!$stmt) {
        return ['ok' => false, 'error' => 'db_prepare'];
    }
    $stmt->bind_param('isis', $userId, $hash, $ttlMinutes, $ip);
    if (!$stmt->execute()) {
        $stmt->close();

        return ['ok' => false, 'error' => 'db_insert'];
    }
    $stmt->close();

    return ['ok' => true, 'token' => $token];
}

/**
 * @return array{ok:bool, user_id?:int, error?:string}
 */
function password_reset_consume_token(mysqli $db, string $token): array
{
    password_reset_ensure_table($db);
    $token = trim($token);
    if ($token === '' || strlen($token) < 20) {
        return ['ok' => false, 'error' => 'invalid_token'];
    }
    $hash = hash('sha256', $token);

    $stmt = $db->prepare(
        'SELECT id, user_id FROM password_reset_tokens
         WHERE token_hash=? AND used_at IS NULL AND expires_at > NOW()
         ORDER BY id DESC LIMIT 1'
    );
    if (!$stmt) {
        return ['ok' => false, 'error' => 'db_prepare'];
    }
    $stmt->bind_param('s', $hash);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if (!$row) {
        return ['ok' => false, 'error' => 'token_not_found'];
    }

    $rowId = (int) $row['id'];
    $userId = (int) $row['user_id'];
    $db->query('UPDATE password_reset_tokens SET used_at=NOW() WHERE id=' . $rowId . ' LIMIT 1');

    return ['ok' => true, 'user_id' => $userId];
}

function password_reset_send_notifications(mysqli $db, int $userId, string $resetLink): array
{
    $res = $db->query(
        'SELECT email, nickname, tg_enabled, tg_chat_id FROM users WHERE id=' . (int) $userId . ' LIMIT 1'
    );
    $u = $res ? $res->fetch_assoc() : null;
    if (!$u) {
        return ['email' => false, 'telegram' => false];
    }

    $email = trim((string) ($u['email'] ?? ''));
    $nick = trim((string) ($u['nickname'] ?? ''));
    $sentEmail = false;
    $sentTg = false;

    $body = "Вітаємо" . ($nick !== '' ? ", {$nick}" : '') . "!\n\n"
        . "Ви запросили скидання пароля StrIM.\n"
        . "Перейдіть за посиланням (діє 60 хв):\n{$resetLink}\n\n"
        . "Якщо це не ви — проігноруйте лист.\n"
        . "Підтримка: support@strim.in.ua\n";

    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        require_once __DIR__ . '/_mail_send.php';
        $mailRes = strim_mail_send($email, 'StrIM — скидання пароля', $body, 'support@strim.in.ua');
        $sentEmail = !empty($mailRes['ok']);
    }

    if ((int) ($u['tg_enabled'] ?? 0) === 1 && !empty($u['tg_chat_id'])) {
        $token = trim((string) ($_ENV['TG_BOT_TOKEN'] ?? ''));
        $chatId = (string) $u['tg_chat_id'];
        if ($token !== '' && $chatId !== '') {
            $tgText = "🔐 <b>StrIM</b>: скидання пароля\n\n"
                . "Перейдіть за посиланням (60 хв):\n" . htmlspecialchars($resetLink, ENT_QUOTES, 'UTF-8');
            $url = 'https://api.telegram.org/bot' . $token . '/sendMessage';
            $ch = curl_init($url);
            if ($ch !== false) {
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 10,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => [
                        'chat_id' => $chatId,
                        'text' => $tgText,
                        'parse_mode' => 'HTML',
                        'disable_web_page_preview' => true,
                    ],
                ]);
                $raw = curl_exec($ch);
                curl_close($ch);
                $j = is_string($raw) ? json_decode($raw, true) : null;
                $sentTg = is_array($j) && !empty($j['ok']);
            }
        }
    }

    return ['email' => $sentEmail, 'telegram' => $sentTg];
}
