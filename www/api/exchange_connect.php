<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=UTF-8');

require __DIR__ . '/../vendor/autoload.php';

// .env лежить у корені проєкту на сервері (шлях залежить від хостингу).
$envBase = dirname(__DIR__, 2);
$dotenv = Dotenv\Dotenv::createImmutable($envBase);
$dotenv->safeLoad();

function envget(string $k, string $def=''): string {
    $v = getenv($k);
    if ($v !== false && $v !== '') return trim($v);
    if (isset($_ENV[$k]) && $_ENV[$k] !== '') return trim($_ENV[$k]);
    if (isset($_SERVER[$k]) && $_SERVER[$k] !== '') return trim($_SERVER[$k]);
    return $def;
}

function json_fail(int $code, $msg) {
    http_response_code($code);
    echo json_encode(['ok'=>false,'error'=>$msg], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_fail(405, 'Use POST with JSON body');
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) $data = [];

$exchange = strtolower($data['exchange'] ?? 'binance');
// Never fall back to platform BINANCE_* — that would expose the operator account.
$apiKey   = trim((string)($data['api_key'] ?? ''));
$secret   = trim((string)($data['api_secret'] ?? ''));
$testnet  = array_key_exists('testnet', $data)
    ? (bool)$data['testnet']
    : false;

if ($exchange !== 'binance') json_fail(400, 'Only binance supported for now');
if ($apiKey === '' || $secret === '') json_fail(400, 'Missing API credentials');

$base = $testnet ? 'https://testnet.binance.vision' : 'https://api.binance.com';
$endpoint = '/api/v3/account';
$params = ['timestamp' => (int)(microtime(true)*1000), 'recvWindow' => 5000];
$query = http_build_query($params, '', '&');
$signature = hash_hmac('sha256', $query, $secret);
$url = $base . $endpoint . '?' . $query . '&signature=' . $signature;

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['X-MBX-APIKEY: ' . $apiKey],
    CURLOPT_TIMEOUT        => 15,
]);
$resp = curl_exec($ch);
$errno = curl_errno($ch);
$err   = curl_error($ch);
$http  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($errno) json_fail(502, 'Curl error: ' . $err);
if ($http >= 400) {
    $j = json_decode((string)$resp, true);
    $msg = $j['msg'] ?? ("HTTP ".$http);
    json_fail($http, 'Binance: ' . $msg);
}

$j = json_decode((string)$resp, true);
if (!is_array($j)) json_fail(500, 'Unexpected response');

$balances = [];
foreach ($j['balances'] ?? [] as $b) {
    $free = (float)($b['free'] ?? 0);
    $locked = (float)($b['locked'] ?? 0);
    if ($free > 0 || $locked > 0) {
        $balances[] = ['asset'=>$b['asset'], 'free'=>$free, 'locked'=>$locked];
    }
}

echo json_encode([
    'ok' => true,
    'accountType' => $j['accountType'] ?? null,
    'canTrade' => $j['canTrade'] ?? null,
    'balances' => $balances,
], JSON_UNESCAPED_UNICODE);
