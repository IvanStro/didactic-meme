<?php
declare(strict_types=1);
/**
 * Єдине підключення env + сесії для endpoint-ів у /api/.
 * require_onc на початку кожного *.php (або лише через auth/api_gate.php).
 */
if (defined('STRIM_API_BOOTSTRAPPED')) {
    return;
}
define('STRIM_API_BOOTSTRAPPED', true);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_whitepay_dotenv.php';

use Dotenv\Dotenv;

Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();
strim_whitepay_maybe_load_home_env();

require_once __DIR__ . '/../auth/_session_user.php';
