<?php
declare(strict_types=1);
require __DIR__ . "/../vendor/autoload.php";
require_once __DIR__ . '/../auth/_session_user.php';
require_once __DIR__ . '/_csrf.php';
require_once __DIR__ . '/_admin_audit.php';

Dotenv\Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();
header('Content-Type: application/json; charset=utf-8');

$mysqli = new mysqli($_ENV["DB_HOST"], $_ENV["DB_USER"], $_ENV["DB_PASS"], $_ENV["DB_NAME"]);
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
require_csrf_or_403();

$id = (int)($_POST['id'] ?? 0);
$action = trim((string)($_POST['action'] ?? ''));
if ($id <= 0 || ($action !== 'paid' && $action !== 'cancel')) {
  admin_audit_log($mysqli, $uid, 'withdraw_invalid', 0, ['withdraw_id' => $id, 'action' => $action], 'invalid_input');
  echo json_encode(['ok' => false, 'error' => 'invalid input']);
  exit;
}

if ($action === 'paid') {
  $targetUserId = 0;
  $tr = $mysqli->query("SELECT user_id FROM referral_withdrawals WHERE id=$id LIMIT 1");
  if ($tr && ($trow = $tr->fetch_assoc())) {
    $targetUserId = (int)($trow['user_id'] ?? 0);
  }
  $ok = $mysqli->query("
    UPDATE referral_withdrawals
    SET status='paid', processed_at=NOW()
    WHERE id=$id AND status='pending'
  ");
  if ($ok && $targetUserId > 0) {
    // Close accruals tied to this payout so dashboard balances stay consistent.
    $mysqli->query(
      "UPDATE referral_rewards SET status='paid', paid_at=NOW() WHERE referrer_user_id=" . (int)$targetUserId . " AND status='approved'"
    );
  }
  admin_audit_log($mysqli, $uid, 'withdraw_paid', $targetUserId, ['withdraw_id' => $id, 'action' => 'paid'], $ok ? 'ok' : 'db_error');
  echo json_encode(['ok' => (bool)$ok, 'action' => 'paid']);
  exit;
}

$targetUserId = 0;
$tr = $mysqli->query("SELECT user_id FROM referral_withdrawals WHERE id=$id LIMIT 1");
if ($tr && ($trow = $tr->fetch_assoc())) {
  $targetUserId = (int)($trow['user_id'] ?? 0);
}
$ok = $mysqli->query("
  UPDATE referral_withdrawals
  SET status='canceled', processed_at=NOW()
  WHERE id=$id AND status='pending'
");
if ($ok && $targetUserId > 0) {
  // Return accrued amounts to withdrawable pool if payout was cancelled.
  $mysqli->query(
    "UPDATE referral_rewards SET status='pending', paid_at=NULL WHERE referrer_user_id=" . (int)$targetUserId . " AND status='approved'"
  );
}
admin_audit_log($mysqli, $uid, 'withdraw_cancel', $targetUserId, ['withdraw_id' => $id, 'action' => 'cancel'], $ok ? 'ok' : 'db_error');
echo json_encode(['ok' => (bool)$ok, 'action' => 'cancel']);
