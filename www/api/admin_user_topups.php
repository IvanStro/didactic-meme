<?php
declare(strict_types=1);

/**
 * Admin: Binance inflows for a user.
 * - P2P (C2C) BUY
 * - Deposit history (on-chain + internal Binance User→User)
 * - Transfers onto Spot (Funding/Margin/Futures/PM → MAIN)
 *
 * GET/POST: user_id, optional months=8 (30d windows)
 */
require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../auth/_session_user.php';
require_once __DIR__ . '/_user_keys.php';
require_once __DIR__ . '/_admin_audit.php';

Dotenv\Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();
header('Content-Type: application/json; charset=utf-8');

$mysqli = new mysqli(
    (string) ($_ENV['DB_HOST'] ?? ''),
    (string) ($_ENV['DB_USER'] ?? ''),
    (string) ($_ENV['DB_PASS'] ?? ''),
    (string) ($_ENV['DB_NAME'] ?? '')
);
if ($mysqli->connect_errno) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'db_connect'], JSON_UNESCAPED_UNICODE);
    exit;
}
$mysqli->set_charset('utf8mb4');

$adminUid = current_user_id($mysqli);
if ($adminUid <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}
$admRes = $mysqli->query('SELECT is_admin FROM users WHERE id=' . (int) $adminUid . ' LIMIT 1');
$admRow = $admRes ? $admRes->fetch_assoc() : null;
if (!$admRow || (int) ($admRow['is_admin'] ?? 0) !== 1) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

$targetUserId = (int) (
    $_REQUEST['user_id'] ??
    $_REQUEST['id'] ??
    $_GET['user_id'] ??
    $_GET['id'] ??
    0
);
$months = (int) ($_REQUEST['months'] ?? $_GET['months'] ?? 8);
$months = max(1, min(24, $months));

if ($targetUserId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'invalid_user_id'], JSON_UNESCAPED_UNICODE);
    exit;
}

$infoRes = $mysqli->query(
    'SELECT id, email, nickname FROM users WHERE id=' . (int) $targetUserId . ' LIMIT 1'
);
$info = $infoRes ? $infoRes->fetch_assoc() : null;
if (!$info) {
    echo json_encode(['ok' => false, 'error' => 'user_not_found'], JSON_UNESCAPED_UNICODE);
    exit;
}

[$apiKey, $apiSecret] = user_keys($mysqli, $targetUserId);
if (!$apiKey || !$apiSecret) {
    echo json_encode([
        'ok' => false,
        'error' => 'no_api_keys',
        'user' => [
            'id' => (int) $info['id'],
            'email' => (string) $info['email'],
            'nickname' => (string) ($info['nickname'] ?? ''),
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * @return array{0:int,1:mixed}
 */
function admin_topups_bn(string $method, string $path, string $apiKey, string $apiSecret, array $params = []): array
{
    $params['timestamp'] = (int) (microtime(true) * 1000);
    $params['recvWindow'] = 60000;
    $q = http_build_query($params);
    $sig = hash_hmac('sha256', $q, $apiSecret);
    $url = 'https://api.binance.com' . $path;
    $ch = curl_init();
    $headers = ['X-MBX-APIKEY: ' . $apiKey];
    if (strtoupper($method) === 'GET') {
        curl_setopt($ch, CURLOPT_URL, $url . '?' . $q . '&signature=' . $sig);
    } else {
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $q . '&signature=' . $sig);
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = json_decode($body, true);
    return [$code, $json ?? $body];
}

function admin_topups_fmt_ts($ts): string
{
    $ts = (int) $ts;
    if ($ts <= 0) {
        return '';
    }
    if ($ts < 20_000_000_000) {
        $ts *= 1000;
    }
    return date('Y-m-d H:i:s', (int) ($ts / 1000));
}

function admin_topups_deposit_status_label(int $status): string
{
    // Binance: 0=pending, 6=credited but cannot withdraw, 1=success
    if ($status === 1) {
        return 'SUCCESS';
    }
    if ($status === 6) {
        return 'CREDITED';
    }
    if ($status === 0) {
        return 'PENDING';
    }
    return (string) $status;
}

function admin_topups_wallet_label($walletType): string
{
    $w = (int) $walletType;
    if ($w === 0) {
        return 'SPOT';
    }
    if ($w === 1) {
        return 'FUNDING';
    }
    return 'W' . $w;
}

$nowMs = (int) (microtime(true) * 1000);
$p2pByOrder = [];
$p2pErrors = [];
$apiErrors = [];

for ($i = 0; $i < $months; $i++) {
    $end = $nowMs - $i * 30 * 86400000;
    $start = $end - 30 * 86400000;
    [$http, $data] = admin_topups_bn('GET', '/sapi/v1/c2c/orderMatch/listUserOrderHistory', $apiKey, $apiSecret, [
        'tradeType' => 'BUY',
        'startTimestamp' => $start,
        'endTimestamp' => $end,
        'page' => 1,
        'rows' => 100,
    ]);
    if ($http !== 200 || !is_array($data)) {
        $p2pErrors[] = ['window' => $i, 'http' => $http];
        continue;
    }
    if (isset($data['code']) && (string) $data['code'] !== '000000') {
        $p2pErrors[] = ['window' => $i, 'code' => $data['code'] ?? null, 'msg' => $data['message'] ?? null];
        continue;
    }
    foreach ($data['data'] ?? [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $orderNo = (string) ($row['orderNumber'] ?? '');
        if ($orderNo === '') {
            continue;
        }
        $p2pByOrder[$orderNo] = [
            'at' => admin_topups_fmt_ts($row['createTime'] ?? 0),
            'status' => (string) ($row['orderStatus'] ?? ''),
            'usdt' => (string) ($row['amount'] ?? ''),
            'usdt_net' => (string) ($row['takerAmount'] ?? ($row['amount'] ?? '')),
            'uah' => (string) ($row['totalPrice'] ?? ''),
            'rate' => (string) ($row['unitPrice'] ?? ''),
            'pay' => (string) ($row['payMethodName'] ?? ''),
            'order' => $orderNo,
            'asset' => (string) ($row['asset'] ?? 'USDT'),
            'fiat' => (string) ($row['fiat'] ?? 'UAH'),
        ];
    }
}

$p2p = array_values($p2pByOrder);
usort($p2p, static function (array $a, array $b): int {
    return strcmp((string) $a['at'], (string) $b['at']);
});

$completedCount = 0;
$completedUsdt = 0.0;
$completedUah = 0.0;
foreach ($p2p as $row) {
    if (($row['status'] ?? '') !== 'COMPLETED') {
        continue;
    }
    $completedCount++;
    $completedUsdt += (float) ($row['usdt_net'] !== '' ? $row['usdt_net'] : $row['usdt']);
    $completedUah += (float) ($row['uah'] ?? 0);
}

// --- Deposit history: on-chain + internal Binance User→User (90d windows) ---
$depositsByKey = [];
$depositWindows = max(1, (int) ceil(($months * 30) / 90));
for ($i = 0; $i < $depositWindows; $i++) {
    $end = $nowMs - $i * 90 * 86400000;
    $start = $end - 90 * 86400000;
    $offset = 0;
    for ($page = 0; $page < 5; $page++) {
        [$http, $data] = admin_topups_bn('GET', '/sapi/v1/capital/deposit/hisrec', $apiKey, $apiSecret, [
            'startTime' => $start,
            'endTime' => $end,
            'offset' => $offset,
            'limit' => 1000,
        ]);
        if ($http !== 200 || !is_array($data)) {
            $apiErrors[] = [
                'src' => 'deposit_hisrec',
                'window' => $i,
                'http' => $http,
                'msg' => is_array($data) ? ($data['msg'] ?? null) : null,
            ];
            break;
        }
        // Error object: {"code":...,"msg":...}; success: [{...}, ...]
        if (isset($data['code']) && !isset($data[0])) {
            $apiErrors[] = [
                'src' => 'deposit_hisrec',
                'window' => $i,
                'code' => $data['code'],
                'msg' => $data['msg'] ?? null,
            ];
            break;
        }
        $rows = [];
        foreach ($data as $k => $row) {
            if (!is_array($row) || !isset($row['amount'])) {
                continue;
            }
            $rows[] = $row;
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (string) ($row['id'] ?? '');
            $txId = (string) ($row['txId'] ?? '');
            $key = $id !== '' ? $id : ($txId . '|' . ($row['insertTime'] ?? '') . '|' . ($row['amount'] ?? ''));
            if ($key === '' || isset($depositsByKey[$key])) {
                continue;
            }
            $status = (int) ($row['status'] ?? -1);
            $transferType = (int) ($row['transferType'] ?? 0);
            $walletType = (int) ($row['walletType'] ?? 0);
            $depositsByKey[$key] = [
                'at' => admin_topups_fmt_ts($row['insertTime'] ?? 0),
                'asset' => (string) ($row['coin'] ?? ''),
                'amount' => (string) ($row['amount'] ?? ''),
                'status' => admin_topups_deposit_status_label($status),
                'status_code' => $status,
                'kind' => $transferType === 1 ? 'INTERNAL' : 'ONCHAIN',
                'wallet' => admin_topups_wallet_label($walletType),
                'wallet_type' => $walletType,
                'network' => (string) ($row['network'] ?? ''),
                'tx_id' => $txId,
                'address' => (string) ($row['address'] ?? ''),
                'id' => $id,
            ];
        }
        if (count($rows) < 1000) {
            break;
        }
        $offset += 1000;
    }
}

$deposits = array_values($depositsByKey);
usort($deposits, static function (array $a, array $b): int {
    return strcmp((string) $b['at'], (string) $a['at']); // newest first
});

$depositUsdtSpot = 0.0;
$depositUsdtAll = 0.0;
$depositOkCount = 0;
foreach ($deposits as $row) {
    $ok = in_array((int) ($row['status_code'] ?? -1), [1, 6], true);
    if (!$ok) {
        continue;
    }
    $depositOkCount++;
    if (strtoupper((string) ($row['asset'] ?? '')) !== 'USDT') {
        continue;
    }
    $amt = (float) ($row['amount'] ?? 0);
    $depositUsdtAll += $amt;
    if (($row['wallet'] ?? '') === 'SPOT') {
        $depositUsdtSpot += $amt;
    }
}

// --- Binance Pay / User→User (internal C2C). Missing from deposit/hisrec. ---
$payByKey = [];
$payWindows = max(1, (int) ceil(($months * 30) / 90));
for ($i = 0; $i < $payWindows; $i++) {
    $end = $nowMs - $i * 90 * 86400000;
    $start = $end - 90 * 86400000;
    [$http, $data] = admin_topups_bn('GET', '/sapi/v1/pay/transactions', $apiKey, $apiSecret, [
        'startTime' => $start,
        'endTime' => $end,
        'limit' => 100,
    ]);
    if ($http !== 200 || !is_array($data)) {
        $apiErrors[] = [
            'src' => 'pay_transactions',
            'window' => $i,
            'http' => $http,
            'msg' => is_array($data) ? ($data['msg'] ?? ($data['message'] ?? null)) : null,
        ];
        continue;
    }
    $code = (string) ($data['code'] ?? '000000');
    if ($code !== '000000' && $code !== '0') {
        $apiErrors[] = [
            'src' => 'pay_transactions',
            'window' => $i,
            'code' => $data['code'] ?? null,
            'msg' => $data['message'] ?? ($data['msg'] ?? null),
        ];
        continue;
    }
    foreach ($data['data'] ?? [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $orderId = (string) ($row['orderId'] ?? '');
        $txId = (string) ($row['transactionId'] ?? '');
        $key = $orderId !== '' ? $orderId : ($txId . '|' . ($row['transactionTime'] ?? ''));
        if ($key === '' || isset($payByKey[$key])) {
            continue;
        }
        $amt = (float) ($row['amount'] ?? 0);
        // Only inflows to this account (received). Outflows are negative or payer=self.
        if ($amt <= 0) {
            continue;
        }
        $walletTypes = $row['walletTypes'] ?? [];
        $walletType = (int) ($row['walletType'] ?? (is_array($walletTypes) && isset($walletTypes[0]) ? $walletTypes[0] : 1));
        $payer = $row['payerInfo'] ?? [];
        $payerName = is_array($payer) ? (string) ($payer['name'] ?? '') : '';
        $payByKey[$key] = [
            'at' => admin_topups_fmt_ts($row['transactionTime'] ?? 0),
            'asset' => (string) ($row['currency'] ?? 'USDT'),
            'amount' => (string) ($row['amount'] ?? ''),
            'order_type' => (string) ($row['orderType'] ?? ''),
            'wallet' => admin_topups_wallet_label($walletType),
            'from' => $payerName !== '' ? $payerName : '—',
            'order_id' => $orderId,
            'tx_id' => $txId,
            'note' => (string) ($row['note'] ?? ''),
        ];
    }
}
$payIn = array_values($payByKey);
usort($payIn, static function (array $a, array $b): int {
    return strcmp((string) $b['at'], (string) $a['at']);
});
$payUsdt = 0.0;
foreach ($payIn as $row) {
    if (strtoupper((string) ($row['asset'] ?? '')) === 'USDT') {
        $payUsdt += (float) ($row['amount'] ?? 0);
    }
}

// --- Transfers onto Spot (MAIN) ---
// Primary path for Spot trading funds. Extra wallet types are best-effort.
$toSpotTypes = [
    'FUNDING_MAIN',
    'MARGIN_MAIN',
    'UMFUTURE_MAIN',
    'CMFUTURE_MAIN',
    'PORTFOLIO_MARGIN_MAIN',
];
$toSpotByKey = [];
$toSpotSumUsdt = 0.0;
$transferWindows = max(2, (int) ceil($months / 3));
foreach ($toSpotTypes as $xferType) {
    for ($i = 0; $i < $transferWindows; $i++) {
        $end = $nowMs - $i * 90 * 86400000;
        $start = $end - 90 * 86400000;
        [$http, $data] = admin_topups_bn('GET', '/sapi/v1/asset/transfer', $apiKey, $apiSecret, [
            'type' => $xferType,
            'startTime' => $start,
            'endTime' => $end,
            'size' => 100,
        ]);
        if ($http !== 200 || !is_array($data)) {
            // Soft-fail: many accounts never used Margin/Futures — don't spam UI.
            if ($xferType === 'FUNDING_MAIN') {
                $apiErrors[] = [
                    'src' => 'asset_transfer',
                    'type' => $xferType,
                    'window' => $i,
                    'http' => $http,
                    'code' => is_array($data) ? ($data['code'] ?? null) : null,
                    'msg' => is_array($data) ? ($data['msg'] ?? null) : null,
                ];
            }
            continue;
        }
        if (isset($data['code'])) {
            if ($xferType === 'FUNDING_MAIN') {
                $apiErrors[] = [
                    'src' => 'asset_transfer',
                    'type' => $xferType,
                    'window' => $i,
                    'code' => $data['code'],
                    'msg' => $data['msg'] ?? null,
                ];
            }
            continue;
        }
        foreach ($data['rows'] ?? [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $tranId = (string) ($row['tranId'] ?? '');
            $key = $xferType . '|' . ($tranId !== '' ? $tranId : (($row['timestamp'] ?? '') . '|' . ($row['amount'] ?? '')));
            if (isset($toSpotByKey[$key])) {
                continue;
            }
            $asset = strtoupper((string) ($row['asset'] ?? ''));
            $amt = (float) ($row['amount'] ?? 0);
            if ($asset === 'USDT') {
                $toSpotSumUsdt += $amt;
            }
            $toSpotByKey[$key] = [
                'at' => admin_topups_fmt_ts($row['timestamp'] ?? 0),
                'asset' => $asset,
                'amount' => (string) ($row['amount'] ?? ''),
                'status' => (string) ($row['status'] ?? ''),
                'tran_id' => $row['tranId'] ?? null,
                'type' => $xferType,
            ];
        }
    }
}

$toSpot = array_values($toSpotByKey);
usort($toSpot, static function (array $a, array $b): int {
    return strcmp((string) $b['at'], (string) $a['at']);
});

// Legacy aliases for older UI
$fundingToSpot = array_values(array_filter($toSpot, static fn ($r) => ($r['type'] ?? '') === 'FUNDING_MAIN'));
$fundingSum = 0.0;
foreach ($fundingToSpot as $row) {
    if (strtoupper((string) ($row['asset'] ?? '')) === 'USDT') {
        $fundingSum += (float) ($row['amount'] ?? 0);
    }
}

admin_audit_log($mysqli, $adminUid, 'user_topups_view', $targetUserId, [
    'p2p_total' => count($p2p),
    'p2p_completed' => $completedCount,
    'pay_in' => count($payIn),
    'deposits' => count($deposits),
    'to_spot' => count($toSpot),
    'funding_to_spot' => count($fundingToSpot),
], 'ok');

echo json_encode([
    'ok' => true,
    'generated_at' => date('Y-m-d H:i:s'),
    'months' => $months,
    'user' => [
        'id' => (int) $info['id'],
        'email' => (string) $info['email'],
        'nickname' => (string) ($info['nickname'] ?? ''),
    ],
    'pay_in' => $payIn,
    'pay_in_summary' => [
        'rows' => count($payIn),
        'usdt' => round($payUsdt, 2),
    ],
    'p2p' => $p2p,
    'p2p_summary' => [
        'completed_count' => $completedCount,
        'completed_usdt_net' => round($completedUsdt, 2),
        'completed_uah' => round($completedUah, 2),
        'rows' => count($p2p),
    ],
    'deposits' => $deposits,
    'deposits_summary' => [
        'rows' => count($deposits),
        'ok_count' => $depositOkCount,
        'usdt_all_wallets' => round($depositUsdtAll, 2),
        'usdt_to_spot' => round($depositUsdtSpot, 2),
    ],
    'to_spot' => $toSpot,
    'to_spot_usdt' => round($toSpotSumUsdt, 2),
    // backward-compatible fields
    'funding_to_spot' => $fundingToSpot,
    'funding_to_spot_usdt' => round($fundingSum, 2),
    'note_uk' => 'Внутрішні User→User (як у Binance «Користувач Binance») — у блоці Pay/C2C. Ончейн — у «Депозити». На Spot для торгівлі — після Funding→Spot.',
    'errors' => array_merge($p2pErrors, $apiErrors),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
