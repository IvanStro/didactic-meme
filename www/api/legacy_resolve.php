<?php
declare(strict_types=1);
/**
 * POST: підтвердження дії по спадку.
 * body/query: symbol, action=hold|ignore|adopt_spot, fba?=7
 */
require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_binance_utils.php';
require_once __DIR__ . '/_user_keys.php';
require_once __DIR__ . '/../auth/_session_user.php';
require_once __DIR__ . '/_csrf.php';
require_once __DIR__ . '/_billing_access.php';
require_once __DIR__ . '/_legacy_portfolio.php';

use Dotenv\Dotenv;

header('Content-Type: application/json; charset=utf-8');

try {
    Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'env']);
    exit;
}

require_csrf_or_403();

$db = @new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
if ($db->connect_errno) {
    echo json_encode(['ok' => false, 'error' => 'db']);
    exit;
}
$db->set_charset('utf8mb4');

$uid = current_user_id($db);
if ($uid <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}

$in = $_POST;
if (empty($in)) {
    $raw = file_get_contents('php://input');
    $j = json_decode((string)$raw, true);
    if (is_array($j)) {
        $in = $j;
    }
}

$symbol = strtoupper(trim((string)($in['symbol'] ?? '')));
$action = strtolower(trim((string)($in['action'] ?? '')));
$fba = isset($in['fba']) ? (float)$in['fba'] : 7.0;

if (!preg_match('~^[A-Z0-9]{3,}USDT$~', $symbol)) {
    echo json_encode(['ok' => false, 'error' => 'bad_symbol']);
    exit;
}
$allowed = ['hold', 'ignore', 'adopt_spot', 'hybrid', 'exit_plan'];
if (!in_array($action, $allowed, true)) {
    echo json_encode(['ok' => false, 'error' => 'bad_action', 'allowed' => $allowed]);
    exit;
}

[$apiKey, $secret] = user_keys($db, $uid);
if (!$apiKey || !$secret) {
    echo json_encode(['ok' => false, 'error' => 'no_api_keys']);
    exit;
}

$scan = legacy_portfolio_scan_user($db, $uid, $apiKey, $secret);
if (empty($scan['ok'])) {
    echo json_encode(['ok' => false, 'error' => 'scan_failed', 'detail' => $scan]);
    exit;
}

$item = null;
foreach ($scan['items'] as $it) {
    if (($it['symbol'] ?? '') === $symbol) {
        $item = $it;
        break;
    }
}
if (!$item) {
    echo json_encode(['ok' => false, 'error' => 'symbol_not_in_portfolio']);
    exit;
}

if (in_array($action, ['adopt_spot', 'hybrid', 'exit_plan'], true)) {
    billing_emit_trading_denied(billing_require_trading_access($db, $uid));
    if (in_array($action, (array)($item['blocked'] ?? []), true)) {
        echo json_encode(['ok' => false, 'error' => 'action_blocked']);
        exit;
    }
    if (($item['klass'] ?? '') === 'ours') {
        echo json_encode(['ok' => false, 'error' => 'already_in_strim']);
        exit;
    }
    $res = legacy_resolve_adopt_mode(
        $db,
        $uid,
        $symbol,
        $action,
        (float)$item['qty'],
        (float)$item['spot'],
        $fba,
        $item
    );
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'hold' || $action === 'ignore') {
    $res = legacy_resolve_hold($db, $uid, $symbol, $item);
    if ($action === 'ignore') {
        legacy_review_upsert($db, $uid, $symbol, 'ignore', $item, null);
        $res['action'] = 'ignore';
    }
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'unhandled']);
