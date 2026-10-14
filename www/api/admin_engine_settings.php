<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../auth/_session_user.php';
require_once __DIR__ . '/_csrf.php';
require_once __DIR__ . '/_worker_manager.php';
require_once __DIR__ . '/../../engine/engine_logic_ladder_cascade.php';
require_once __DIR__ . '/_legacy_portfolio.php';

Dotenv\Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();
header('Content-Type: application/json; charset=utf-8');

$mysqli = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
if ($mysqli->connect_errno) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'db connect error']);
  exit;
}
$mysqli->set_charset('utf8mb4');

$uid = current_user_id($mysqli);
if ($uid <= 0) {
  http_response_code(401);
  echo json_encode(['ok' => false, 'error' => 'auth']);
  exit;
}
$admRes = $mysqli->query("SELECT is_admin FROM users WHERE id=" . (int)$uid . " LIMIT 1");
$admRow = $admRes ? $admRes->fetch_assoc() : null;
if (!$admRow || (int)($admRow['is_admin'] ?? 0) !== 1) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'error' => 'forbidden']);
  exit;
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'GET') {
  $r = ladder_cascade_resolve_mode($mysqli);
  $legacy = legacy_scan_load_settings($mysqli);
  echo json_encode([
    'ok' => true,
    'ladder_mode' => $r['mode'],
    'ladder_source' => $r['source'],
    'legacy' => $legacy,
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

if ($method !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'error' => 'method']);
  exit;
}

require_csrf_or_403();

$action = trim((string)($_POST['action'] ?? ''));

if ($action === 'toggle_ladder_mode') {
  $cur = ladder_cascade_effective_mode($mysqli);
  $next = $cur === 'cascade' ? 'parallel' : 'cascade';
  if (!worker_set_setting($mysqli, LADDER_APP_SETTING_KEY, $next)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'save_failed']);
    exit;
  }
  $r = ladder_cascade_resolve_mode($mysqli);
  echo json_encode([
    'ok' => true,
    'message' => $next === 'cascade' ? 'Режим: каскад (докупки)' : 'Режим: parallel (legacy)',
    'ladder_mode' => $r['mode'],
    'ladder_source' => $r['source'],
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

if ($action === 'set_ladder_mode') {
  $mode = strtolower(trim((string)($_POST['mode'] ?? '')));
  if ($mode !== 'cascade' && $mode !== 'parallel') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid mode']);
    exit;
  }
  if (!worker_set_setting($mysqli, LADDER_APP_SETTING_KEY, $mode)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'save_failed']);
    exit;
  }
  $r = ladder_cascade_resolve_mode($mysqli);
  echo json_encode([
    'ok' => true,
    'message' => 'Збережено',
    'ladder_mode' => $r['mode'],
    'ladder_source' => $r['source'],
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

if ($action === 'save_legacy_thresholds') {
  $map = [
    'dust_usdt' => 'legacy_dust_usdt',
    'dd_ok_pct' => 'legacy_dd_ok_pct',
    'dd_deep_pct' => 'legacy_dd_deep_pct',
    'stale_days' => 'legacy_stale_days',
  ];
  $saved = [];
  foreach ($map as $field => $key) {
    if (!isset($_POST[$field])) {
      continue;
    }
    $v = (float)$_POST[$field];
    if ($field === 'stale_days') {
      $v = max(1, (int)$v);
    } elseif ($field === 'dust_usdt') {
      $v = max(0.5, $v);
    } else {
      $v = max(0, min(99, $v));
    }
    $val = (string)$v;
    if (!worker_set_setting($mysqli, $key, $val)) {
      http_response_code(500);
      echo json_encode(['ok' => false, 'error' => 'save_failed', 'key' => $key]);
      exit;
    }
    $saved[$field] = $v;
  }
  echo json_encode([
    'ok' => true,
    'message' => 'Пороги спадку збережено',
    'legacy' => legacy_scan_load_settings($mysqli),
    'saved' => $saved,
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'unknown action']);
