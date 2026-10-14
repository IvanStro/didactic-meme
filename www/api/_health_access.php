<?php
declare(strict_types=1);

/**
 * Доступ до /api/health.php — лише loopback, HEALTH_TOKEN або admin-сесія.
 */
function health_remote_ip(): string
{
    $xff = trim((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
    if ($xff !== '') {
        $parts = explode(',', $xff);

        return trim($parts[0]);
    }

    return trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
}

function health_is_private_ip(string $ip): bool
{
    if ($ip === '' || $ip === '127.0.0.1' || $ip === '::1') {
        return true;
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return !filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
    }

    return false;
}

function health_access_allowed(): bool
{
    $secret = trim((string)(getenv('HEALTH_TOKEN') ?: ($_ENV['HEALTH_TOKEN'] ?? '')));
    $provided = trim((string)($_GET['token'] ?? $_SERVER['HTTP_X_STRIM_HEALTH_TOKEN'] ?? ''));

    if ($secret !== '' && $provided !== '' && hash_equals($secret, $provided)) {
        return true;
    }

    if (health_is_private_ip(health_remote_ip())) {
        return true;
    }

    return false;
}
