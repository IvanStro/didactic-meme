<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../auth/_session_user.php';
require_once __DIR__ . '/_ws_price.php';
require_once __DIR__ . '/_admin_realized_pnl.php';
require_once __DIR__ . '/_filters_utils.php';
require_once dirname(__DIR__, 2) . '/engine/engine_helpers.php';

Dotenv\Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();
header('Content-Type: application/json; charset=utf-8');

$mysqli = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
if ($mysqli->connect_errno) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'db connect error']);
  exit;
}

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

$rawUserId =
  ($_REQUEST['user_id'] ?? null) ??
  ($_REQUEST['id'] ?? null) ??
  ($_GET['user_id'] ?? null) ??
  ($_GET['id'] ?? null) ??
  ($_POST['user_id'] ?? null) ??
  ($_POST['id'] ?? null);
$targetUserId = (int)$rawUserId;
if ($targetUserId <= 0) {
  echo json_encode([
    'ok' => false,
    'error' => 'invalid user_id',
    'debug' => [
      'method' => (string)($_SERVER['REQUEST_METHOD'] ?? ''),
      'query' => (string)($_SERVER['QUERY_STRING'] ?? ''),
      'user_id_get' => $_GET['user_id'] ?? null,
      'id_get' => $_GET['id'] ?? null,
      'user_id_post' => $_POST['user_id'] ?? null,
      'id_post' => $_POST['id'] ?? null,
    ],
  ]);
  exit;
}

$infoRes = $mysqli->query("SELECT id,email,nickname FROM users WHERE id=$targetUserId LIMIT 1");
$info = $infoRes ? $infoRes->fetch_assoc() : null;
if (!$info) {
  echo json_encode(['ok' => false, 'error' => 'user not found']);
  exit;
}

function admin_live_price(string $symbol): float {
  if ($symbol === '') return 0.0;
  $ws = ws_ticker_price($symbol);
  if (is_finite($ws) && $ws > 0) return (float)$ws;
  try {
    $rest = (float)exi_get_price($symbol);
    if (is_finite($rest) && $rest > 0) return $rest;
  } catch (Throwable $e) {}
  return 0.0;
}

$strRows = [];
$running = 0;
$unrealized = 0.0;

$sRes = $mysqli->query("
  SELECT
    s.id AS strategy_id,
    s.symbol,
    s.status,
    s.user_id,
    s.owner_id
  FROM strategies s
  WHERE s.user_id = $targetUserId
    AND UPPER(COALESCE(s.symbol,'')) <> 'TEMPLATE'
  ORDER BY s.id DESC
");

if ($sRes) {
  while ($r = $sRes->fetch_assoc()) {
    $sid = (int)($r['strategy_id'] ?? 0);
    $symbol = (string)($r['symbol'] ?? '');
    $uidPos = (int)($r['owner_id'] ?? 0);
    if ($uidPos <= 0) {
      $uidPos = (int)($r['user_id'] ?? 0);
    }
    $pos = ($sid > 0) ? position_pick_row($mysqli, $sid, $uidPos) : null;
    if ($pos) {
      position_resync_qty_from_open_subs($mysqli, (int)$pos['id']);
      $pos = position_pick_row($mysqli, $sid, $uidPos) ?: $pos;
    }
    $qty = $pos ? (float)($pos['qty'] ?? 0) : 0.0;
    $avg = $pos ? (float)($pos['avg_price'] ?? 0) : 0.0;
    $invested = $pos ? (float)($pos['invested_usdt'] ?? 0) : 0.0;
    $positionState = $pos ? (string)($pos['state'] ?? '') : '';
    $pid = $pos ? (int)($pos['id'] ?? 0) : 0;
    if ($qty <= 1e-12 && $pid > 0) {
      $subQty = position_open_sub_qty_sum($mysqli, $pid);
      if ($subQty > 1e-12) {
        $subCost = position_open_sub_cost_sum($mysqli, $pid);
        $qty = $subQty;
        $invested = $subCost;
        $avg = $subQty > 0 ? ($subCost / $subQty) : 0.0;
        if ($positionState === '' || $positionState === 'closed') {
          $positionState = 'open';
        }
      }
    }
    $mark = admin_live_price($symbol);
    $u = 0.0;
    if (($positionState === 'open' || $qty > 1e-12) && $qty > 0 && $mark > 0) {
      $effectiveInvested = $invested > 0 ? $invested : ($avg > 0 ? ($avg * $qty) : 0.0);
      $u = ($qty * $mark) - $effectiveInvested;
      $unrealized += $u;
    }
    if (($r['status'] ?? '') === 'running') {
      $running++;
    }
    // Current ladder depth: highest open sub level (1..N). 0 = only main / no open ladder.
    $level = 0;
    $nextPtr = 1;
    if ($pid > 0) {
      $level = position_max_open_level_idx($mysqli, $pid);
      $nextPtr = isset($pos['next_ptr']) ? (int)$pos['next_ptr'] : ladder_next_tip_level($mysqli, $pid);
      if ($level <= 0 && ($positionState === 'open' || $qty > 1e-12)) {
        $level = 0; // open main (BUY0), no ladder buys yet
      }
    }
    $hasOpen = ($positionState === 'open' || $qty > 1e-12);
    $strRows[] = [
      'strategy_id' => $sid,
      'symbol' => $symbol,
      'status' => (string)($r['status'] ?? ''),
      'position_state' => $positionState !== '' ? $positionState : '-',
      'level' => $hasOpen ? $level : null,
      'next_ptr' => $hasOpen ? $nextPtr : null,
      'qty' => $qty,
      'avg_price' => $avg,
      'mark_price' => $mark,
      'unrealized_pnl' => $u,
    ];
  }
}

$realized = admin_fetch_realized_pnl($mysqli, $targetUserId);

echo json_encode([
  'ok' => true,
  'user' => [
    'id' => (int)$info['id'],
    'email' => (string)$info['email'],
    'nickname' => (string)($info['nickname'] ?? ''),
  ],
  'summary' => [
    'strategies_total' => count($strRows),
    'strategies_running' => $running,
    'unrealized_pnl' => $unrealized,
    'realized_pnl' => $realized,
  ],
  'strategies' => $strRows,
]);
