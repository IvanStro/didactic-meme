<?php
declare(strict_types=1);

require __DIR__ . '/../www/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/**
 * Align PHP wall clock with MySQL DATETIME on this host (Europe/Kyiv).
 * Due checks must still prefer MySQL NOW() — see scaling_maybe_enqueue_signal_exec_reconcile.
 */
function engine_app_timezone(): string
{
    static $tz = null;
    if ($tz !== null) {
        return $tz;
    }
    $raw = getenv('APP_TIMEZONE') ?: ($_ENV['APP_TIMEZONE'] ?? 'Europe/Kyiv');
    $raw = trim((string)$raw);
    $tz = $raw !== '' ? $raw : 'Europe/Kyiv';
    if (function_exists('date_default_timezone_set')) {
        @date_default_timezone_set($tz);
    }
    return $tz;
}

engine_app_timezone();

/**
 * Long-running worker.php keeps one PHP process; $_ENV from first boot is stale after .env edits.
 * Call before each job so TRADING_ENABLED and other flags match the file on disk.
 */
function refresh_env_from_disk(): void {
    $dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
    $dotenv->safeLoad();
    foreach ($_ENV as $k => $v) {
        if (is_array($v) || $v === null) {
            continue;
        }
        putenv($k . '=' . (string)$v);
    }
    if (isset($_ENV['TRADING_ENABLED'])) {
        $te = $_ENV['TRADING_ENABLED'];
        if (is_array($te)) {
            $te = end($te);
        }
        $te = (string)$te;
        $_ENV['TRADING_ENABLED'] = $te;
        putenv('TRADING_ENABLED=' . $te);
    }
}

function db(): mysqli {
    static $db = null;

    if ($db instanceof mysqli) {
        try {
            if (@$db->ping()) {
                return $db;
            }
        } catch (Throwable $e) {
            // stale connection — reconnect below
        }
        try {
            @$db->close();
        } catch (Throwable $e) {
            // ignore
        }
        $db = null;
    }

    $db = new mysqli(
        $_ENV['DB_HOST'] ?? '',
        $_ENV['DB_USER'] ?? '',
        $_ENV['DB_PASS'] ?? '',
        $_ENV['DB_NAME'] ?? ''
    );

    $db->set_charset('utf8mb4');
    return $db;
}
