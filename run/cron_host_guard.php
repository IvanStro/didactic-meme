<?php
declare(strict_types=1);

/**
 * Cron має виконуватись лише на одному хості (shared NFS + одна БД).
 * Якщо STRIM_CRON_HOST не задано — guard вимкнений (backward compatible).
 *
 * .env:
 *   STRIM_CRON_HOST=business-117.default-host.net
 */
function cron_host_allowed(): bool
{
    $allowed = trim((string)(getenv('STRIM_CRON_HOST') ?: ($_ENV['STRIM_CRON_HOST'] ?? '')));
    if ($allowed === '') {
        return true;
    }
    $host = (string)gethostname();
  // Дозволити кілька імен через кому (primary,failover).
    foreach (preg_split('/\s*,\s*/', $allowed) as $part) {
        if ($part !== '' && strcasecmp($part, $host) === 0) {
            return true;
        }
    }
    return false;
}

function cron_host_guard_or_exit(string $scriptName): void
{
    if (cron_host_allowed()) {
        return;
    }
    $host = (string)gethostname();
    $allowed = trim((string)(getenv('STRIM_CRON_HOST') ?: ($_ENV['STRIM_CRON_HOST'] ?? '')));
    echo date('c') . " {$scriptName}: skip wrong host={$host} allowed={$allowed}\n";
    exit(0);
}
