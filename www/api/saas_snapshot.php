<?php

require_once __DIR__ . '/../auth/_session_user.php';

// Ensure user is authorized (same contract as API)
$mysqli = null;
$uid = 0;
try {
  // Build mysqli via env the same way other endpoints do
  $dotenvPath = dirname(__DIR__, 2) . '/vendor/autoload.php';
  if (file_exists($dotenvPath)) {
    require_once $dotenvPath;
    if (class_exists('Dotenv\\Dotenv')) {
      $dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__, 2));
      $dotenv->safeLoad();
    }
  }

  $host = $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: '';
  $user = $_ENV['DB_USER'] ?? getenv('DB_USER') ?: '';
  $pass = $_ENV['DB_PASS'] ?? getenv('DB_PASS') ?: '';
  $name = $_ENV['DB_NAME'] ?? getenv('DB_NAME') ?: '';

  $mysqli = @new mysqli($host, $user, $pass, $name);
  if ($mysqli && !$mysqli->connect_errno) {
    $mysqli->set_charset('utf8mb4');
    $uid = current_user_id($mysqli);
  }
} catch (\Throwable $e) {
  // ignore; handled below
}

if ($uid <= 0) {
  http_response_code(401);
  echo json_encode(['ok'=>false,'error'=>'unauthorized'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  exit;


}

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
    return ['ok'=>false, 'error'=>'bad_json', 'raw'=>mb_substr($out, 0, 500)];
  }
  return $j;
}



// Collect snapshot parts
$strategies = run_include_json(__DIR__ . '/strategies_list_v2.php', ['status'=>'all', 'limit'=>200]);



$portfolio   = run_include_json(__DIR__ . '/portfolio.php', []);

$ok = (!empty($strategies['ok']) && !empty($portfolio['ok']));
echo json_encode([
  'ok' => $ok,
  'ts' => date('Y-m-d H:i:s'),
  'user_id' => $uid,
  'strategies' => $strategies,
  'portfolio' => $portfolio,
], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
