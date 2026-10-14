<?php
declare(strict_types=1);

/**
 * Відправка листів StrIM.
 *
 * Рекомендовано на ADM.TOOLS / Hosting Ukraine: SMTP (DKIM працює лише через SMTP).
 *
 * .env:
 *   MAIL_FROM=support@strim.in.ua
 *   MAIL_FROM_NAME=StrIM
 *   MAIL_TRANSPORT=smtp          (smtp | mail | auto)
 *   MAIL_SMTP_HOST=mail.adm.tools
 *   MAIL_SMTP_PORT=587
 *   MAIL_SMTP_SECURE=tls           (tls для 587, ssl для 465)
 *   MAIL_SMTP_USER=support@strim.in.ua
 *   MAIL_SMTP_PASS=********
 */
function strim_mail_from_address(): string
{
    $from = trim((string) ($_ENV['MAIL_FROM'] ?? 'support@strim.in.ua'));
    if ($from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
        return 'support@strim.in.ua';
    }

    return $from;
}

function strim_mail_from_name(): string
{
    $name = trim((string) ($_ENV['MAIL_FROM_NAME'] ?? 'StrIM'));

    return $name !== '' ? $name : 'StrIM';
}

function strim_mail_envelope_from(): string
{
    $env = trim((string) ($_ENV['MAIL_ENVELOPE_FROM'] ?? ''));
    if ($env !== '' && filter_var($env, FILTER_VALIDATE_EMAIL)) {
        return $env;
    }

    return strim_mail_from_address();
}

function strim_mail_use_smtp(): bool
{
    $mode = strtolower(trim((string) ($_ENV['MAIL_TRANSPORT'] ?? 'auto')));
    if ($mode === 'mail') {
        return false;
    }
    if ($mode === 'smtp') {
        return true;
    }
    $user = trim((string) ($_ENV['MAIL_SMTP_USER'] ?? ''));
    $pass = trim((string) ($_ENV['MAIL_SMTP_PASS'] ?? ''));

    return $user !== '' && $pass !== '';
}

/**
 * @return array{ok:bool, error?:string, transport?:string}
 */
function strim_mail_send(string $to, string $subject, string $body, ?string $replyTo = null): array
{
    $to = trim($to);
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'invalid_to'];
    }

    if (strim_mail_use_smtp()) {
        $smtp = strim_mail_send_smtp($to, $subject, $body, $replyTo);
        if (!empty($smtp['ok'])) {
            $smtp['transport'] = 'smtp';

            return $smtp;
        }
        $fallback = strtolower(trim((string) ($_ENV['MAIL_SMTP_FALLBACK'] ?? '1')));
        if ($fallback === '0' || $fallback === 'false') {
            $smtp['transport'] = 'smtp';

            return $smtp;
        }
    }

    $php = strim_mail_send_php($to, $subject, $body, $replyTo);
    $php['transport'] = 'mail';

    return $php;
}

/**
 * @return array{ok:bool, error?:string}
 */
function strim_mail_send_php(string $to, string $subject, string $body, ?string $replyTo): array
{
    $fromAddr = strim_mail_from_address();
    $fromName = strim_mail_from_name();
    $reply = strim_mail_reply_address($replyTo, $fromAddr);

    $encodedName = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
    $headers = 'From: ' . $encodedName . ' <' . $fromAddr . ">\r\n"
        . 'Reply-To: ' . $reply . "\r\n"
        . "MIME-Version: 1.0\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: 8bit\r\n";

    $subjectEncoded = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $envelope = strim_mail_envelope_from();
    $params = '-f' . $envelope;

    $ok = @mail($to, $subjectEncoded, $body, $headers, $params);
    if (!$ok) {
        return ['ok' => false, 'error' => 'mail_failed'];
    }

    return ['ok' => true];
}

function strim_mail_reply_address(?string $replyTo, string $default): string
{
    $reply = $replyTo !== null && $replyTo !== '' ? trim($replyTo) : $default;

    return filter_var($reply, FILTER_VALIDATE_EMAIL) ? $reply : $default;
}

/**
 * @return array{ok:bool, error?:string}
 */
function strim_mail_send_smtp(string $to, string $subject, string $body, ?string $replyTo): array
{
    $host = trim((string) ($_ENV['MAIL_SMTP_HOST'] ?? 'mail.adm.tools'));
    $port = (int) ($_ENV['MAIL_SMTP_PORT'] ?? 587);
    $secure = strtolower(trim((string) ($_ENV['MAIL_SMTP_SECURE'] ?? 'tls')));
    $user = trim((string) ($_ENV['MAIL_SMTP_USER'] ?? strim_mail_from_address()));
    $pass = (string) ($_ENV['MAIL_SMTP_PASS'] ?? '');

    if ($host === '' || $user === '' || $pass === '') {
        return ['ok' => false, 'error' => 'smtp_not_configured'];
    }

    $fromAddr = strim_mail_from_address();
    $fromName = strim_mail_from_name();
    $reply = strim_mail_reply_address($replyTo, $fromAddr);

    $remote = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $fp = @stream_socket_client($remote, $errno, $errstr, 20, STREAM_CLIENT_CONNECT);
    if (!$fp) {
        return ['ok' => false, 'error' => 'smtp_connect: ' . $errstr];
    }
    stream_set_timeout($fp, 20);

    $read = static function ($fp): string {
        $out = '';
        while ($line = fgets($fp, 515)) {
            $out .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }

        return $out;
    };
    $expect = static function (string $resp, array $codes): bool {
        $code = (int) substr($resp, 0, 3);

        return in_array($code, $codes, true);
    };
    $cmd = static function ($fp, string $command) use ($read): string {
        fwrite($fp, $command . "\r\n");

        return $read($fp);
    };

    $greet = $read($fp);
    if (!$expect($greet, [220])) {
        fclose($fp);

        return ['ok' => false, 'error' => 'smtp_greet'];
    }

    $ehloHost = 'strim.in.ua';
    $resp = $cmd($fp, 'EHLO ' . $ehloHost);
    if (!$expect($resp, [250])) {
        fclose($fp);

        return ['ok' => false, 'error' => 'smtp_ehlo'];
    }

    if ($secure === 'tls') {
        $resp = $cmd($fp, 'STARTTLS');
        if (!$expect($resp, [220])) {
            fclose($fp);

            return ['ok' => false, 'error' => 'smtp_starttls'];
        }
        $crypto = @stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        if (!$crypto) {
            fclose($fp);

            return ['ok' => false, 'error' => 'smtp_tls'];
        }
        $resp = $cmd($fp, 'EHLO ' . $ehloHost);
        if (!$expect($resp, [250])) {
            fclose($fp);

            return ['ok' => false, 'error' => 'smtp_ehlo2'];
        }
    }

    $resp = $cmd($fp, 'AUTH LOGIN');
    if (!$expect($resp, [334])) {
        fclose($fp);

        return ['ok' => false, 'error' => 'smtp_auth'];
    }
    $resp = $cmd($fp, base64_encode($user));
    if (!$expect($resp, [334])) {
        fclose($fp);

        return ['ok' => false, 'error' => 'smtp_user'];
    }
    $resp = $cmd($fp, base64_encode($pass));
    if (!$expect($resp, [235])) {
        fclose($fp);

        return ['ok' => false, 'error' => 'smtp_pass'];
    }

    $resp = $cmd($fp, 'MAIL FROM:<' . $fromAddr . '>');
    if (!$expect($resp, [250])) {
        fclose($fp);

        return ['ok' => false, 'error' => 'smtp_mail_from'];
    }
    $resp = $cmd($fp, 'RCPT TO:<' . $to . '>');
    if (!$expect($resp, [250, 251])) {
        fclose($fp);

        return ['ok' => false, 'error' => 'smtp_rcpt'];
    }
    $resp = $cmd($fp, 'DATA');
    if (!$expect($resp, [354])) {
        fclose($fp);

        return ['ok' => false, 'error' => 'smtp_data'];
    }

    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $encodedName = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
    $msg = 'From: ' . $encodedName . ' <' . $fromAddr . ">\r\n"
        . 'To: <' . $to . ">\r\n"
        . 'Reply-To: ' . $reply . "\r\n"
        . 'Subject: ' . $encodedSubject . "\r\n"
        . "MIME-Version: 1.0\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: 8bit\r\n"
        . "\r\n"
        . str_replace(["\r\n", "\r"], "\n", $body);
    $msg = str_replace("\n", "\r\n", $msg);
    $msg = preg_replace('/^\./m', '..', $msg) ?? $msg;
    fwrite($fp, $msg . "\r\n.\r\n");
    $resp = $read($fp);
    $cmd($fp, 'QUIT');
    fclose($fp);

    if (!$expect($resp, [250])) {
        return ['ok' => false, 'error' => 'smtp_send'];
    }

    return ['ok' => true];
}
