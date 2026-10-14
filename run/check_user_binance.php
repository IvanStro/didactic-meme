<?php
declare(strict_types=1);

require __DIR__ . '/../www/vendor/autoload.php';
require __DIR__ . '/../www/api/_user_keys.php';

Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

$uid = (int) ($argv[1] ?? 18);
$m = new mysqli(
    (string) ($_ENV['DB_HOST'] ?? ''),
    (string) ($_ENV['DB_USER'] ?? ''),
    (string) ($_ENV['DB_PASS'] ?? ''),
    (string) ($_ENV['DB_NAME'] ?? '')
);
$m->set_charset('utf8mb4');

[$k, $s] = user_keys($m, $uid);
$out = ['uid' => $uid, 'trading_enabled' => (string) ($_ENV['TRADING_ENABLED'] ?? '')];

$u = $m->query("SELECT id, nickname, email, billing_status, trial_until, paid_until FROM users WHERE id={$uid} LIMIT 1");
$out['user'] = $u ? $u->fetch_assoc() : null;

if ($k && $s) {
    $ts = (int) (microtime(true) * 1000);
    $q = "timestamp={$ts}&recvWindow=5000";
    $sig = hash_hmac('sha256', $q, $s);
    $ch = curl_init('https://api.binance.com/sapi/v1/account/apiRestrictions?' . $q . '&signature=' . $sig);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ["X-MBX-APIKEY: {$k}"]]);
    $out['api_restrictions'] = json_decode((string) curl_exec($ch), true);
    curl_close($ch);

    $ts2 = (int) (microtime(true) * 1000);
    $p = ['symbol' => 'SUIUSDT', 'side' => 'BUY', 'type' => 'MARKET', 'quoteOrderQty' => '10', 'timestamp' => $ts2, 'recvWindow' => 5000];
    $q2 = http_build_query($p);
    $sig2 = hash_hmac('sha256', $q2, $s);
    $ch2 = curl_init('https://api.binance.com/api/v3/order/test?' . $q2 . '&signature=' . $sig2);
    curl_setopt_array($ch2, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_HTTPHEADER => ["X-MBX-APIKEY: {$k}"]]);
    $body = (string) curl_exec($ch2);
    $out['order_test'] = ['http' => curl_getinfo($ch2, CURLINFO_HTTP_CODE), 'body' => json_decode($body, true) ?: $body];
    curl_close($ch2);
} else {
    $out['keys_ok'] = false;
}

$strats = [];
$st = $m->query("
  SELECT s.id, s.symbol, s.status, s.fba, s.tp_whole, s.cb_whole, s.start_ts, s.last_tick_at,
         p.qty, p.avg_price, p.base_price, p.state, p.next_ptr, p.last_buy
  FROM strategies s
  LEFT JOIN positions p ON p.strategy_id = s.id
  WHERE s.user_id = {$uid}
  ORDER BY s.id DESC
");
while ($st && ($r = $st->fetch_assoc())) {
    $strats[] = $r;
}
$out['strategies'] = $strats;

$logs = [];
$j = $m->query("
  SELECT id, created_at, event_type, symbol, message
  FROM engine_journal
  WHERE strategy_id IN (SELECT id FROM strategies WHERE user_id = {$uid})
  ORDER BY id DESC LIMIT 20
");
while ($j && ($r = $j->fetch_assoc())) {
    $logs[] = $r;
}
$out['journal'] = $logs;

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
