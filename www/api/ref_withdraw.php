<?php
http_response_code(410);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok'=>false,'error'=>'bonus_not_withdrawable','message'=>'Бонуси використовуються лише на оплату сервісу. Для повернення власного авансу зверніться через /consumer.html.'], JSON_UNESCAPED_UNICODE);
exit;

require_once __DIR__ . '/../auth/_session_user.php';
require_once __DIR__ . '/_tg_notify.php';
require __DIR__."/../vendor/autoload.php";
Dotenv\Dotenv::createImmutable(dirname(__DIR__,2))->load();

$mysqli = new 
mysqli($_ENV["DB_HOST"],$_ENV["DB_USER"],$_ENV["DB_PASS"],$_ENV["DB_NAME"]);

$uid = current_user_id($mysqli);

if(!$uid){
  http_response_code(401);
  echo json_encode(['ok'=>false,'error'=>'auth']);
  exit;
}

// resolve user wallet first (must be user-owned, no hardcoded payout address)
$walletRes = $mysqli->query("SELECT withdraw_wallet FROM users WHERE id=$uid LIMIT 1");
$walletRow = $walletRes ? $walletRes->fetch_assoc() : null;
$wallet = trim((string)($walletRow['withdraw_wallet'] ?? ''));
if ($wallet === '') {
  echo json_encode(['ok'=>false,'error'=>'wallet_not_set']);
  exit;
}

try {
  $mysqli->begin_transaction();

  // lock candidate rewards to avoid race/double-withdraw
  $lockSql = "SELECT id, reward_usdt FROM referral_rewards WHERE referrer_user_id=$uid AND status='pending' FOR UPDATE";
  $lockRes = $mysqli->query($lockSql);
  if ($lockRes === false) {
    throw new RuntimeException('lock_failed');
  }
  $rewardIds = [];
  $available = 0.0;
  while ($r = $lockRes->fetch_assoc()) {
    $rid = (int)($r['id'] ?? 0);
    if ($rid > 0) $rewardIds[] = $rid;
    $available += (float)($r['reward_usdt'] ?? 0);
  }

  if($available <= 0){
    $walletEsc = $mysqli->real_escape_string($wallet);
    $mysqli->query("
      INSERT INTO referral_withdrawals (user_id, amount_usdt, wallet_address, status, note)
      VALUES ($uid, 0, '".$walletEsc."', 'rejected', 'zero balance')
    ");
    $mysqli->commit();
    tg_notify("⚠️ Withdraw rejected\nuser_id: ".$uid."\namount: 0 USDT\nwallet: ".$wallet."\nreason: zero balance");
    echo json_encode(['ok'=>false,'error'=>'nothing to withdraw']);
    exit;
  }

  $walletEsc = $mysqli->real_escape_string($wallet);
  $amountSql = number_format($available, 8, '.', '');
  $ins = $mysqli->query("
    INSERT INTO referral_withdrawals (user_id, amount_usdt, wallet_address)
    VALUES ($uid, $amountSql, '".$walletEsc."')
  ");
  if ($ins === false) {
    throw new RuntimeException('insert_withdraw_failed');
  }

  // mark only locked rows as consumed
  if (!empty($rewardIds)) {
    $idsSql = implode(',', array_map('intval', $rewardIds));
    $upd = $mysqli->query("UPDATE referral_rewards SET status='approved' WHERE id IN ($idsSql) AND status='pending'");
    if ($upd === false) {
      throw new RuntimeException('update_rewards_failed');
    }
  }

  $mysqli->commit();
  echo json_encode(['ok'=>true,'amount'=>$available, 'wallet'=>$wallet]);
} catch (Throwable $e) {
  try { $mysqli->rollback(); } catch (Throwable $e2) {}
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'withdraw_failed']);
}

