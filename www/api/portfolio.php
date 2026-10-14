<?php
declare(strict_types=1);
require __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/_binance_utils.php';
require_once __DIR__.'/_user_keys.php';
require_once __DIR__.'/../auth/_session_user.php';

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(dirname(__DIR__, 2));
$dotenv->safeLoad();

header('Content-Type: application/json; charset=utf-8');

$mysqli = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
if ($mysqli->connect_errno) {
    echo json_encode(['ok' => false, 'error' => 'db']);
    exit;
}
$mysqli->set_charset('utf8mb4');

function f2($v){ return (float)number_format((float)$v,2,'.',''); }
function f8($v){ return (float)number_format((float)$v,8,'.',''); }

// Авторизований користувач
$uid = current_user_id($mysqli);
if ($uid <= 0) {
    echo json_encode([
        'ok'          => true,
        'usdt_free'   => f2(0),
        'summary_usdt'=> f2(0),
        'items'       => [],
        'balances'    => [],
        'map'         => new stdClass(),
        'ts'          => date('Y-m-d H:i:s')
    ]);
    exit;
}

// Ключі саме цього користувача
[$K, $S] = user_keys($mysqli, $uid);
if (!$K || !$S) {
    echo json_encode([
        'ok'          => true,
        'usdt_free'   => f2(0),
        'summary_usdt'=> f2(0),
        'items'       => [],
        'balances'    => [],
        'map'         => new stdClass(),
        'ts'          => date('Y-m-d H:i:s')
    ]);
    exit;
}

/** account під КЛЮЧАМИ саме цього користувача */
$ts = bn_server_time();
$q  = bn_sign_query(['timestamp'=>$ts,'recvWindow'=>5000], $S);
[$h,$r,$e,$er] = http_json(bn_base().'/api/v3/account?'.$q, ['X-MBX-APIKEY: '.$K], 'GET');
if ($e || $h>=400) {
    // NEW USER / NO API KEYS / INVALID KEYS → RETURN EMPTY PORTFOLIO INSTEAD OF ERROR
    echo json_encode([
        'ok'          => true,
        'status'      => 'NO_API',
        'usdt_free'   => f2(0),
        'summary_usdt'=> f2(0),
        'items'       => [],
        'balances'    => [],
        'map'         => new stdClass(),
        'ts'          => date('Y-m-d H:i:s')
    ]);
    exit;
}

$acc = json_decode((string)$r, true) ?: [];
$usdt_free = 0.0;
$rows = [];           // масив для таблиці
$map  = [];           // асоціативний вигляд
$sum_value = 0.0;

// Один ticker/price на актив, усі запити паралельно (раніше: exchangeInfo + price послідовно).
$pending = [];
foreach (($acc['balances'] ?? []) as $b) {
  $asset = (string)($b['asset'] ?? '');
  if ($asset === '') {
    continue;
  }

  $free   = (float)($b['free']   ?? 0.0);
  $locked = (float)($b['locked'] ?? 0.0);
  $qty    = $free + $locked;

  if ($asset === 'USDT') {
    $usdt_free = $qty;
  }

  if ($qty <= 0.0 || strtoupper($asset) === 'USDT') {
    continue;
  }

  $sym = strtoupper($asset) . 'USDT';
  $pending[] = [
    'asset'  => $asset,
    'symbol' => $sym,
    'free'   => $free,
    'locked' => $locked,
    'qty'    => $qty,
  ];
}

$urls = [];
foreach ($pending as $i => $p) {
  $urls[$i] = bn_base() . '/api/v3/ticker/price?symbol=' . rawurlencode($p['symbol']);
}
$batch = http_json_parallel_get($urls, 12);

foreach ($pending as $i => $p) {
  if (!isset($batch[$i])) {
    continue;
  }
  [$http, $body, $errno, $err] = $batch[$i];
  if ($errno !== 0 || $http !== 200) {
    continue;
  }
  $price = (float)(json_decode($body, true)['price'] ?? 0.0);
  if ($price <= 0.0) {
    continue;
  }
  $qty = (float)$p['qty'];
  $value = $qty * $price;
  $sum_value += $value;
  $asset = (string)$p['asset'];
  $row = [
    'asset'       => $asset,
    'symbol'      => (string)$p['symbol'],
    'free'        => f8((float)$p['free']),
    'locked'      => f8((float)$p['locked']),
    'qty'         => f8($qty),
    'price_usdt'  => f8($price),
    'value_usdt'  => f2($value),
  ];
  $rows[] = $row;
  $map[$asset] = $row;
}

// сортуємо за value desc
usort($rows, function($a,$b){ return ($b['value_usdt'] <=> $a['value_usdt']); });

echo json_encode([
  'ok'            => true,
  'status'        => 'OK',
  'usdt_free'     => f2($usdt_free),
  'summary_usdt'  => f2($sum_value),
  'items'         => $rows,     // основний масив для таблиці
  'balances'      => $rows,     // аліас (на випадок старого фронту)
  'map'           => $map,      // швидкий доступ за asset
  'ts'            => date('Y-m-d H:i:s')
], JSON_UNESCAPED_UNICODE);
