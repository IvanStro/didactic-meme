<?php
declare(strict_types=1);

/**
 * Async Binance snapshots for admin users table (keeps SSR fast).
 */
header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../auth/_session_user.php';
require_once __DIR__ . '/_user_keys.php';
require_once dirname(__DIR__, 2) . '/engine/engine_helpers.php';

Dotenv\Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();

$mysqli = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
$mysqli->set_charset('utf8mb4');

$uid = current_user_id($mysqli);
if ($uid <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}
$adm = $mysqli->query('SELECT is_admin FROM users WHERE id=' . (int)$uid . ' LIMIT 1');
$row = $adm ? $adm->fetch_assoc() : null;
if (!$row || (int)($row['is_admin'] ?? 0) !== 1) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

function fmt8_live(float $v): string {
    return rtrim(rtrim(number_format($v, 8, '.', ''), '0'), '.');
}

function snapshot_for(mysqli $db, int $userId): array {
    [$apiKey, $apiSecret] = user_keys($db, $userId);
    if (!$apiKey || !$apiSecret) {
        return ['user_id' => $userId, 'binance_usdt' => null, 'assets_text' => 'не підключено'];
    }
    try {
        $acc = exi_get_account($apiKey, $apiSecret);
    } catch (Throwable $e) {
        return ['user_id' => $userId, 'binance_usdt' => null, 'assets_text' => 'тимчасово недоступно'];
    }
    $usdt = null;
    $assets = [];
    foreach (($acc['balances'] ?? []) as $b) {
        $asset = (string)($b['asset'] ?? '');
        $free = (float)($b['free'] ?? 0);
        $locked = (float)($b['locked'] ?? 0);
        $total = $free + $locked;
        if ($asset === 'USDT') {
            $usdt = $free;
            continue;
        }
        if ($total <= 0) {
            continue;
        }
        $assets[] = $asset . ' ' . fmt8_live($total);
    }
    return [
        'user_id' => $userId,
        'binance_usdt' => $usdt,
        'assets_text' => count($assets) ? implode('; ', $assets) : '—',
    ];
}

@set_time_limit(120);
$out = [];
$res = $mysqli->query('SELECT id FROM users ORDER BY id DESC');
while ($res && ($r = $res->fetch_assoc())) {
    $out[] = snapshot_for($mysqli, (int)$r['id']);
}

echo json_encode(['ok' => true, 'users' => $out], JSON_UNESCAPED_UNICODE);
