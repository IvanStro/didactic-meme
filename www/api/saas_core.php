<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth/_session_user.php';
require_once __DIR__ . '/_billing_access.php';
require_once __DIR__ . '/_billing_wallet.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_binance_utils.php';
require_once dirname(__DIR__, 2) . '/engine/profit_reinvest.php';

use Dotenv\Dotenv;

header('Content-Type: application/json; charset=utf-8');

// env
$dotenv = Dotenv::createImmutable(dirname(__DIR__, 2));
$dotenv->safeLoad();

// db
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

// ===== TTL cache (per user) =====
$ttl = 15; // seconds
// bump cache path when strategies/portfolio payload shape changes (avoids stale JSON after deploy)
$cacheFile = sys_get_temp_dir() . "/saas_core_{$uid}_spot_v19.json";
$lockFile  = sys_get_temp_dir() . "/saas_core_{$uid}_spot_v18.lock";

// fast path: fresh cache
if (is_file($cacheFile)) {
  $age = time() - filemtime($cacheFile);
  if ($age >= 0 && $age <= $ttl) {
    $raw = @file_get_contents($cacheFile);
    if ($raw !== false) {
      echo $raw;
      exit;
    }
  }
}

// lock to prevent thundering herd
$fp = @fopen($lockFile, 'c+');
if ($fp) { @flock($fp, LOCK_EX); }

// re-check cache after lock
if (is_file($cacheFile)) {
  $age = time() - filemtime($cacheFile);
  if ($age >= 0 && $age <= $ttl) {
    $raw = @file_get_contents($cacheFile);
    if ($raw !== false) {
      if ($fp) { @flock($fp, LOCK_UN); @fclose($fp); }
      echo $raw;
      exit;
    }
  }
}

// build fresh payload using existing endpoints (heavy part)
$strategies = run_include_json(__DIR__ . '/strategies_list_v2.php', ['status'=>'all','limit'=>200]);
$portfolio  = run_include_json(__DIR__ . '/portfolio.php', []);





// pending withdraw (awaiting admin payout)
$pendingWithdraw = 0.0;
$r = $mysqli->query("SELECT SUM(amount_usdt) as s FROM referral_withdrawals WHERE user_id=".(int)$uid." AND status='pending'");
if($r && ($row = $r->fetch_assoc())){
  $pendingWithdraw = (float)($row['s'] ?? 0);
}

// ===== REFERRAL EARNINGS =====
// pending  = can request a new withdrawal now
// lifetime = all accruals still tracked in referral_rewards (pending+approved+paid)
$refPending = 0.0;
$rp = $mysqli->query("SELECT COALESCE(SUM(reward_usdt),0) AS s FROM referral_rewards WHERE referrer_user_id=".(int)$uid." AND status='pending'");
if ($rp && ($row = $rp->fetch_assoc())) {
  $refPending = (float)($row['s'] ?? 0);
}

$refEarnings = 0.0;
$res = $mysqli->query("SELECT COALESCE(SUM(reward_usdt),0) AS total FROM referral_rewards WHERE referrer_user_id=".(int)$uid." AND status IN ('pending','approved','paid')");
if($res && ($row = $res->fetch_assoc())){
  $refEarnings = (float)($row['total'] ?? 0);
}

$refInvitedCount = 0;
$rc = $mysqli->query("SELECT COUNT(*) AS c FROM users WHERE referred_by_user_id=".(int)$uid);
if ($rc && ($row = $rc->fetch_assoc())) {
  $refInvitedCount = (int)($row['c'] ?? 0);
}

// Only pending rewards are withdrawable; queued payout is shown separately (pending_withdraw).
$availableWithdraw = max(0.0, $refPending);

$userBilling = ['billing_status' => null, 'trial_until' => null, 'paid_until' => null, 'ref_code' => null, 'full_name' => null, 'nickname' => null, 'email' => null];
$uCols = 'billing_status, trial_until, paid_until, ref_code, tg_enabled, tg_chat_id, full_name, nickname, email, telegram, is_admin, can_edit_strategy';
if (function_exists('billing_percent_mode_active') && billing_percent_mode_active($mysqli)) {
  $uCols .= ', balance_usdt, fee_shortfall_usdt, wallet_activated_at';
}
$uRes = $mysqli->query("SELECT {$uCols} FROM users WHERE id=".(int)$uid." LIMIT 1");
if ($uRes && ($uRow = $uRes->fetch_assoc())) {
  $eff = billing_user_row_effective_status($uRow);
  $userBilling = [
    'billing_status' => $uRow['billing_status'] ?? null,
    'trial_until'    => $uRow['trial_until'] ?? null,
    'paid_until'     => $uRow['paid_until'] ?? null,
    'ref_code'       => $uRow['ref_code'] ?? null,
    'full_name'      => $uRow['full_name'] ?? null,
    'nickname'       => $uRow['nickname'] ?? null,
    'email'          => $uRow['email'] ?? null,
    'tg_enabled'     => (int)($uRow['tg_enabled'] ?? 0),
    'tg_chat_id'     => $uRow['tg_chat_id'] ?? null,
    'telegram'       => $uRow['telegram'] ?? null,
    'is_admin'         => (int)($uRow['is_admin'] ?? 0),
    'can_edit_strategy' => (int)($uRow['can_edit_strategy'] ?? 0),
    'billing_effective' => $eff === 'exhausted' ? 'exhausted' : $eff,
    'billing_can_trade' => billing_user_row_has_access($uRow),
    'billing_model' => (function_exists('billing_percent_mode_active') && billing_percent_mode_active($mysqli)) ? 'percent' : 'subscription',
    'balance_usdt' => isset($uRow['balance_usdt']) ? (float)$uRow['balance_usdt'] : null,
    'fee_shortfall_usdt' => isset($uRow['fee_shortfall_usdt']) ? (float)$uRow['fee_shortfall_usdt'] : null,
    'wallet_activated' => !empty($uRow['wallet_activated_at']),
    'min_deposit_uah' => defined('BILLING_MIN_DEPOSIT_UAH') ? BILLING_MIN_DEPOSIT_UAH : 500,
    'fee_percent' => defined('BILLING_FEE_PERCENT') ? BILLING_FEE_PERCENT : 10,
    'trial_max_pairs' => 3,
    'trial_max_fba' => 10,
    'funded_max_pairs' => 50,
  ];
}

// Profit reinvest (bot buy budget reserve)
$freeForReserve = 0.0;
if (is_array($portfolio ?? null) && isset($portfolio['usdt_free'])) {
  $freeForReserve = (float)$portfolio['usdt_free'];
}
$prState = function_exists('profit_reinvest_refresh')
  ? profit_reinvest_refresh($mysqli, (int)$uid, $freeForReserve)
  : ['enabled' => true, 'reserve_usdt' => 0.0, 'reserve_since' => null, 'schema' => false];
$userBilling['profit_reinvest'] = !empty($prState['enabled']);
$userBilling['profit_reserve_usdt'] = round((float)($prState['reserve_usdt'] ?? 0), 8);
$userBilling['profit_reserve_since'] = $prState['reserve_since'] ?? null;
$userBilling['profit_buyable_usdt'] = function_exists('profit_reinvest_buyable_usdt')
  ? round(profit_reinvest_buyable_usdt($mysqli, (int)$uid, $freeForReserve), 8)
  : round($freeForReserve, 8);



// normalize empty / failed portfolio for brand new users
if (!is_array($portfolio) || empty($portfolio['ok'])) {
  $portfolio = [
    'ok' => true,
    'status' => 'EMPTY',
    'usdt_free' => 0,
    'summary_usdt' => 0,
    'items' => [],
    'balances' => [],
    'map' => [],
    'ts' => date('Y-m-d H:i:s'),
  ];
}

if (!is_array($strategies)) {
  $strategies = ['ok' => true, 'items' => [], 'ts' => date('Y-m-d H:i:s')];
} elseif (!isset($strategies['items']) || !is_array($strategies['items'])) {
  $strategies['items'] = [];
  $strategies['ok'] = true;
  if (empty($strategies['ts'])) $strategies['ts'] = date('Y-m-d H:i:s');
}

// add live prices for strategies (parallel GET — раніше послідовний file_get_contents)
$prices = [];
if (!empty($strategies) && isset($strategies['items']) && is_array($strategies['items'])) {
  $syms = [];
  foreach ($strategies['items'] as $it) {
    if (!empty($it['symbol'])) {
      $syms[] = strtoupper((string)$it['symbol']);
    }
  }
  $urls = [];
  foreach ($syms as $sym) {
    $urls[] = bn_base() . '/api/v3/ticker/24hr?symbol=' . rawurlencode($sym);
  }
  $batch = http_json_parallel_get($urls, 12);
  foreach ($syms as $k => $sym) {
    if (!isset($batch[$k])) {
      continue;
    }
    [$http, $body, $errno] = $batch[$k];
    if ($errno !== 0 || $http !== 200) {
      continue;
    }
    $j = json_decode($body, true);
    if (is_array($j) && isset($j['lastPrice'])) {
      $prices[$sym] = [
        'price' => (float)$j['lastPrice'],
        'change24h' => isset($j['priceChangePercent']) ? (float)$j['priceChangePercent'] : null
      ];
    }
  }
}


$payload = [
  'ok' => true,
  'ts' => date('Y-m-d H:i:s'),
  'user_id' => $uid,
  'strategies' => $strategies,
  'portfolio' => $portfolio,
  'user' => $userBilling,
  'ref_earnings' => $refEarnings,
  'ref_pending_rewards' => $refPending,
  'ref_invited_count' => $refInvitedCount,
  'pending_withdraw' => $pendingWithdraw,
  'available_withdraw' => $availableWithdraw,
  'prices' => $prices,

  // engine status (temporary simple data)
  'engine_status' => [
    'state' => 'running',
    'message' => 'Engine active'
  ],

  // market info placeholder
  'market' => [
    'status' => 'connected',
    'source' => 'binance'
  ],
];

$out = json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

// write cache best-effort
@file_put_contents($cacheFile, $out);

// unlock
if ($fp) { @flock($fp, LOCK_UN); @fclose($fp); }

echo $out;
