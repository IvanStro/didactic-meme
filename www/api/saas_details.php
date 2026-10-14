<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth/_session_user.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

header('Content-Type: application/json; charset=utf-8');

$dotenv = Dotenv::createImmutable(dirname(__DIR__, 2));
$dotenv->safeLoad();

$mysqli = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
if ($mysqli->connect_errno) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'db_connect'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  exit;
}
$mysqli->set_charset('utf8mb4');

$uid = current_user_id($mysqli);
if ($uid <= 0) {
  http_response_code(401);
  echo json_encode(['ok'=>false,'error'=>'unauthorized'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  exit;
}

// robust param read (some gateways rewrite params)
$strategy_id = 0;

// 1) request params (GET/POST)
if (isset($_REQUEST['strategy_id'])) $strategy_id = (int)$_REQUEST['strategy_id'];
if ($strategy_id <= 0 && isset($_REQUEST['sid'])) $strategy_id = (int)$_REQUEST['sid'];

// 2) raw body fallback (when gateway breaks $_POST)
if ($strategy_id <= 0) {
  $raw = file_get_contents('php://input');
  if (is_string($raw) && $raw !== '') {
    $arr = [];
    parse_str($raw, $arr);
    if (isset($arr['strategy_id'])) $strategy_id = (int)$arr['strategy_id'];
    if ($strategy_id <= 0 && isset($arr['sid'])) $strategy_id = (int)$arr['sid'];
  }
}

if ($strategy_id <= 0) {
  http_response_code(400);
  echo json_encode([
    'ok'=>false,
    'error'=>'strategy_id required',
    'debug'=>[
      'REQUEST'=>array_keys($_REQUEST ?? []),
      'QS'=>($_SERVER['QUERY_STRING'] ?? ''),
    ]
  ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  exit;
}
// ownership check
$chk = $mysqli->query("SELECT id,symbol,status FROM strategies WHERE id=$strategy_id AND (owner_id=$uid OR (owner_id IS NULL AND user_id=$uid)) LIMIT 1");
$st = $chk ? $chk->fetch_assoc() : null;
if (!$st) {
  http_response_code(403);
  echo json_encode(['ok'=>false,'error'=>'forbidden'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  exit;
}

/**
 * Run existing endpoint via include and capture JSON.
 * Also sets QUERY_STRING + REQUEST because some endpoints read those.
 */
function run_include_json(string $path, array $get = []): array {
  $oldGet = $_GET ?? [];
  $oldReq = $_REQUEST ?? [];
  $oldQS  = $_SERVER['QUERY_STRING'] ?? '';

  $_GET = $get;
  $_REQUEST = $get;
  $_SERVER['QUERY_STRING'] = http_build_query($get);

  ob_start();
  include $path;
  $out = (string)ob_get_clean();

  $_GET = $oldGet;
  $_REQUEST = $oldReq;
  $_SERVER['QUERY_STRING'] = $oldQS;

  $j = json_decode($out, true);
  if (!is_array($j)) {
    return ['ok'=>false,'error'=>'bad_json','raw'=>mb_substr($out, 0, 500)];
  }
  return $j;
}

// TTL cache per user+strategy (details are heavier than core)
$ttl = 8;
$cacheFile = sys_get_temp_dir() . "/saas_details_{$uid}_{$strategy_id}_v4.json";
if (is_file($cacheFile)) {
  $age = time() - filemtime($cacheFile);
  if ($age >= 0 && $age <= $ttl) {
    $raw = @file_get_contents($cacheFile);
    if ($raw !== false) { echo $raw; exit; }
  }
}

$levels  = run_include_json(__DIR__ . '/open_levels.php', ['strategy_id'=>$strategy_id, 'sid'=>$strategy_id]);

// normalize levels -> always have items[]
$levels_items = [];
if (is_array($levels)) {
  if (isset($levels['items']) && is_array($levels['items'])) $levels_items = $levels['items'];
  elseif (isset($levels['rows']) && is_array($levels['rows'])) $levels_items = $levels['rows'];
  elseif (isset($levels['data']) && is_array($levels['data'])) $levels_items = $levels['data'];
  elseif (isset($levels['levels']) && is_array($levels['levels'])) $levels_items = $levels['levels'];
}
$levels['items'] = $levels_items;
// lightweight debug: helps when UI says "no data"
$levels['_keys'] = is_array($levels) ? array_keys($levels) : [];

$journal = run_include_json(__DIR__ . '/journal.php', ['limit'=>30, 'sid'=>$strategy_id, 'strategy_id'=>$strategy_id]);

$out = json_encode([
  'ok' => true,
  'ts' => date('Y-m-d H:i:s'),
  'strategy' => [
    'id' => (int)$st['id'],
    'symbol' => (string)$st['symbol'],
    'status' => (string)$st['status'],
  ],
  'levels' => $levels,
  'journal' => $journal,
], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

@file_put_contents($cacheFile, $out);
echo $out;
