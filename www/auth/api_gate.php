<?php
declare(strict_types=1);

/**
 * Gate для /api/*.php (див. www/.htaccess).
 * Без валідної сесії — 401 JSON. Помилки — завжди JSON, без HTML ErrorDocument.
 */
header('Content-Type: application/json; charset=utf-8');

try {
    require __DIR__ . '/../vendor/autoload.php';
    require_once __DIR__ . '/_session_user.php';

    $dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__, 2));
    $dotenv->safeLoad();

    if (is_file(__DIR__ . '/../api/_whitepay_dotenv.php')) {
        require_once __DIR__ . '/../api/_whitepay_dotenv.php';
        if (function_exists('strim_whitepay_maybe_load_home_env')) {
            try {
                strim_whitepay_maybe_load_home_env();
            } catch (Throwable $e) {
                // open_basedir / missing home .env — не валимо весь API
            }
        }
    }

    $mysqli = @new mysqli(
        (string) ($_ENV['DB_HOST'] ?? ''),
        (string) ($_ENV['DB_USER'] ?? ''),
        (string) ($_ENV['DB_PASS'] ?? ''),
        (string) ($_ENV['DB_NAME'] ?? '')
    );
    if ($mysqli->connect_errno) {
        http_response_code(503);
        echo json_encode(['ok' => false, 'error' => 'db_connect_failed'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $mysqli->set_charset('utf8mb4');

    $uid = current_user_id($mysqli);
    if ($uid <= 0) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'unauthorized'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Issue CSRF cookie for SPA double-submit on mutating APIs.
    if (is_file(__DIR__ . '/../api/_csrf.php')) {
        require_once __DIR__ . '/../api/_csrf.php';
        if (function_exists('ensure_csrf_token')) {
            ensure_csrf_token();
        }
    }

    $path = (string) ($_GET['path'] ?? '');
    if ($path === '' || !preg_match('~^[a-zA-Z0-9_/\.-]+$~', $path)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'bad path'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $path = ltrim(str_replace(['..', '\\'], '', $path), '/');
    $baseName = strtolower(basename($path));
    // Defense in depth: Apache Files Deny does not apply to gate include().
    static $blockedApiScripts = [
        'debug_orders.php' => true,
        'health_debug.php' => true,
        'test_tg_notify.php' => true,
        'tick_strategy.php' => true,
        'sync_from_master.php' => true,
        'sync_all_paused_from_master.php' => true,
        'sync_positions_from_orders.php' => true,
        'sync_positions_from_orders_v2.php' => true,
        'migrate_add_user_fields.php' => true,
        'migrate_billing.php' => true,
        'migrate_referrals.php' => true,
        'migrate_telegram.php' => true,
        'migrate_positions.php' => true,
        'migrate_create_tables.php' => true,
        'migrate_add_status.php' => true,
        'migrate_orders.php' => true,
        'migrate_history.php' => true,
        'migrate_users.php' => true,
        'save_strategy.php' => true,
    ];
    if (isset($blockedApiScripts[$baseName]) || str_starts_with($baseName, 'migrate_')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $file = realpath(__DIR__ . '/../api/' . $path);
    $base = realpath(__DIR__ . '/../api');
    if (!$file || !$base || strpos($file, $base) !== 0 || !is_file($file)) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'not found'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    require $file;
} catch (Throwable $e) {
    error_log('api_gate: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['ok' => false, 'error' => 'server_error'], JSON_UNESCAPED_UNICODE);
}
