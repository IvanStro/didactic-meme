<?php
require_once __DIR__ . '/../api/_tg_notify.php';

require __DIR__.'/../vendor/autoload.php';
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(dirname(__DIR__, 2));
$dotenv->safeLoad();

require_once __DIR__ . '/_session_user.php';

$name = trim((string)($_POST['name'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));
$telegram = trim((string)($_POST['telegram'] ?? ''));
$pass = (string)($_POST['pass'] ?? '');
$pass2 = (string)($_POST['pass2'] ?? '');
$ref = strtoupper(trim((string)($_POST['ref'] ?? '')));
$next = (string)($_POST['next'] ?? '/dashboard.php');
$ip  = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '';
$ua  = isset($_SERVER['HTTP_USER_AGENT']) ? substr((string)$_SERVER['HTTP_USER_AGENT'], 0, 255) : '';

// Будь-який POST на do_register = спроба реєстрації (до валідації).
try {
  require_once __DIR__ . '/../api/_web_analytics.php';
  $analyticsDb = @new mysqli(
    (string)($_ENV['DB_HOST'] ?? ''),
    (string)($_ENV['DB_USER'] ?? ''),
    (string)($_ENV['DB_PASS'] ?? ''),
    (string)($_ENV['DB_NAME'] ?? '')
  );
  if ($analyticsDb && !$analyticsDb->connect_errno) {
    $analyticsDb->set_charset('utf8mb4');
    web_analytics_insert_event($analyticsDb, 'register_submit', 0, '/auth/register.php', [
      'via' => 'do_register',
      'has_email' => $email !== '',
      'has_name' => $name !== '',
    ], false);
    $analyticsDb->close();
  }
} catch (Throwable $e) {
  // ignore
}

if ($name === '' || $pass === '') {
  header(
    'Location: /auth/register.php?msg='.
    urlencode('Введіть логін (імʼя) і пароль').
    '&next='.urlencode($next)
  );
  exit;
}

require_once __DIR__.'/../api/_legal.php';
$postedLegalVersion=(string)($_POST['legal_version']??'');
if (!hash_equals(strim_legal_manifest()['version'],$postedLegalVersion) || ($_POST['adult']??'')!=='1') { header('Location: /auth/register.php?msg='.urlencode('Оновіть сторінку, перегляньте поточні умови та підтвердьте повноліття.')); exit; }
$acceptLegal = (string)($_POST['accept_legal'] ?? '');
if ($acceptLegal !== '1') {
  header(
    'Location: /auth/register.php?msg='.
    urlencode('Потрібно прийняти оферту, політику конфіденційності та розкриття ризиків').
    '&next='.urlencode($next)
  );
  exit;
}

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
  header(
    'Location: /auth/register.php?msg='.
    urlencode('Введіть коректний email').
    '&next='.urlencode($next)
  );
  exit;
}

require_once __DIR__ . '/../api/_email_verify.php';
$emailNorm = email_verify_normalize_email($email);
$email = $emailNorm;

$block = email_verify_blocked_domain($email);
if (!empty($block['blocked'])) {
  header(
    'Location: /auth/register.php?msg='.
    urlencode(email_verify_error_message('blocked_domain')).
    '&next='.urlencode($next)
  );
  exit;
}

if ($pass !== $pass2) {
  header(
    'Location: /auth/register.php?msg='.
    urlencode('Паролі не співпадають').
    '&next='.urlencode($next)
  );
  exit;
}

if ($ref === '') $ref = 'STRIM';

$telegram = trim($telegram);
if ($telegram !== '') {
  $telegram = ltrim($telegram, '@');
}

$mysqli = new mysqli(
  $_ENV['DB_HOST'],
  $_ENV['DB_USER'],
  $_ENV['DB_PASS'],
  $_ENV['DB_NAME']
);
$mysqli->set_charset('utf8mb4');

if (email_verify_required()) {
  $verifyCode = trim((string)($_POST['email_code'] ?? ''));
  $verifyRes = email_verify_consume_code($mysqli, $email, $verifyCode);
  if (empty($verifyRes['ok'])) {
    $vErr = (string)($verifyRes['error'] ?? 'code_required');
    header(
      'Location: /auth/register.php?msg='.
      urlencode(email_verify_error_message($vErr)).
      '&next='.urlencode($next).
      '&email='.urlencode($email)
    );
    exit;
  }
}

$checkStmt = $mysqli->prepare("SELECT id,email,nickname FROM users WHERE nickname=? OR email=? LIMIT 1");
if (!$checkStmt) {
  header('Location: /auth/register.php?msg=' . urlencode('Помилка реєстрації, спробуйте пізніше') . '&next=' . urlencode($next));
  exit;
}
$checkStmt->bind_param('ss', $name, $email);
$checkStmt->execute();
$checkRes = $checkStmt->get_result();
if ($checkRes && ($row = $checkRes->fetch_assoc())) {
  $msg = 'Користувач вже існує';
  if (isset($row['email']) && $row['email'] === $email) {
    $msg = 'Email вже використовується';
  } elseif (isset($row['nickname']) && $row['nickname'] === $name) {
    $msg = 'Логін (нікнейм) вже зайнятий';
  }
  header(
    'Location: /auth/register.php?msg='.
    urlencode($msg).
    '&next='.urlencode($next)
  );
  exit;
}
$checkStmt->close();

// referral check
$ref_user_id = 0;
$refStmt = $mysqli->prepare("SELECT id FROM users WHERE ref_code=? LIMIT 1");
if ($refStmt) {
  $refStmt->bind_param('s', $ref);
  $refStmt->execute();
  $refRes = $refStmt->get_result();
  $refRow = $refRes ? $refRes->fetch_assoc() : null;
  $ref_user_id = $refRow ? (int)$refRow['id'] : 0;
  $refStmt->close();
}
if ($ref_user_id <= 0 && $ref !== 'STRIM') {
  header(
    'Location: /auth/register.php?msg='.
    urlencode('Невірний реферальний код').
    '&next='.urlencode($next)
  );
  exit;
}

// password hash + new referral code
$hash = password_hash($pass, PASSWORD_BCRYPT);
$new_ref_code = 'U' . strtoupper(substr(bin2hex(random_bytes(6)), 0, 6));

$fullName = $name;
$nick     = $name;
$phone    = '';
$exchange = 'binance';
$empty = '';
$signupRefCodeUsed = $ref;
$mysqli->begin_transaction();
$insOk = false;
if ($ref_user_id > 0) {
  $insStmt = $mysqli->prepare("
    INSERT INTO users (
      full_name, email, phone, nickname, exchange,
      api_key_enc, api_secret_enc, pass_hash, telegram,
      ref_code, referred_by_user_id, signup_ref_code_used
    )
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
  ");
  if ($insStmt) {
    $insStmt->bind_param(
      'ssssssssssis',
      $fullName,
      $email,
      $phone,
      $nick,
      $exchange,
      $empty,
      $empty,
      $hash,
      $telegram,
      $new_ref_code,
      $ref_user_id,
      $signupRefCodeUsed
    );
    $insOk = $insStmt->execute();
    $insStmt->close();
  }
} else {
  $insStmt = $mysqli->prepare("
    INSERT INTO users (
      full_name, email, phone, nickname, exchange,
      api_key_enc, api_secret_enc, pass_hash, telegram,
      ref_code, referred_by_user_id, signup_ref_code_used
    )
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?)
  ");
  if ($insStmt) {
    $insStmt->bind_param(
      'sssssssssss',
      $fullName,
      $email,
      $phone,
      $nick,
      $exchange,
      $empty,
      $empty,
      $hash,
      $telegram,
      $new_ref_code,
      $signupRefCodeUsed
    );
    $insOk = $insStmt->execute();
    $insStmt->close();
  }
}
if (!$insOk) {
  $mysqli->rollback();
  header(
    'Location: /auth/register.php?msg='.
    urlencode('Помилка реєстрації, спробуйте пізніше').
    '&next='.urlencode($next)
  );
  exit;
}

$uid = (int)$mysqli->insert_id;
try { strim_legal_record($mysqli,$uid,$postedLegalVersion,'registration'); $mysqli->commit(); }
catch (Throwable $e) { $mysqli->rollback(); header('Location: /auth/register.php?msg='.urlencode('Не вдалося зберегти підтвердження умов. Повторіть реєстрацію.')); exit; }

// Trial: безстроково до першого депозиту (percent) або 3 доби (legacy subscription)
require_once __DIR__ . '/../api/_billing_access.php';
if (function_exists('billing_percent_mode_active') && billing_percent_mode_active($mysqli)) {
  $trialStmt = $mysqli->prepare("
    UPDATE users
    SET billing_status='trial',
        trial_until = NULL
    WHERE id = ?
    LIMIT 1
  ");
} else {
  $trialStmt = $mysqli->prepare("
    UPDATE users
    SET billing_status='trial',
        trial_until = DATE_ADD(NOW(), INTERVAL 3 DAY)
    WHERE id = ?
    LIMIT 1
  ");
}
if ($trialStmt) {
  $trialStmt->bind_param('i', $uid);
  $trialStmt->execute();
  $trialStmt->close();
}

$tgReg = tg_notify('Новий акаунт StrIM, ID: ' . $uid . '. Дані доступні в захищеній адмінпанелі.');
if (empty($tgReg[0])) {
    error_log('register tg_notify failed uid=' . $uid . ' detail=' . ($tgReg[1] ?? ''));
}

try {
  require_once __DIR__ . '/../api/_welcome_email.php';
  $welcomeRes = strim_welcome_send($email, $name);
  if (empty($welcomeRes['ok'])) {
    error_log('welcome_email failed uid=' . $uid . ' email=' . $email . ' err=' . ($welcomeRes['error'] ?? 'unknown'));
  }
} catch (Throwable $e) {
  error_log('welcome_email exception uid=' . $uid . ': ' . $e->getMessage());
}

// AUTO_CLONE_MASTER_STRATEGY_V1
// Create a per-user template strategy (instance) by cloning levels from master_strategy_id.
// This makes Strategy settings non-empty for new users, while keeping trading state per-user.
try {
  // master id
  $ms = $mysqli->query("SELECT v FROM app_settings WHERE k='master_strategy_id' LIMIT 1");
  $msRow = $ms ? $ms->fetch_assoc() : null;
  $masterId = $msRow ? (int)$msRow['v'] : 0;

  if ($masterId > 0) {
    // Create user's template strategy (paused, symbol TEMPLATE)
    $symQ = $mysqli->query("SELECT symbol, fba FROM strategies WHERE id=$masterId LIMIT 1");
    $symR = $symQ ? $symQ->fetch_assoc() : null;
    $symbolTpl = $symR && !empty($symR['symbol']) ? $symR['symbol'] : 'TEMPLATE';
    $fbaTpl = $symR && isset($symR['fba']) ? (float)$symR['fba'] : 10.0;

    $mysqli->begin_transaction();

    // insert strategy instance for the new user
    $stmtS = $mysqli->prepare("INSERT INTO strategies (owner_id, user_id, symbol, status, mode, fba, created_at) VALUES (?, ?, ?, 'paused', 'template', ?, NOW())");
    if (!$stmtS) throw new Exception("prepare strategies failed");
    $stmtS->bind_param("iisd", $uid, $uid, $symbolTpl, $fbaTpl);
    if (!$stmtS->execute()) throw new Exception("insert strategy failed: ".$stmtS->error);
    $newSid = (int)$mysqli->insert_id;
    $stmtS->close();

    // copy martingale_levels
    $q1 = $mysqli->query("INSERT INTO martingale_levels (strategy_id, idx, drop_pct, mult, tp_sub, cb_sub)
                          SELECT $newSid, idx, drop_pct, mult, tp_sub, cb_sub
                          FROM martingale_levels
                          WHERE strategy_id = $masterId");
    if (!$q1) throw new Exception("copy martingale_levels failed: ".$mysqli->error);

    // copy strategy_levels
    $q2 = $mysqli->query("INSERT INTO strategy_levels (strategy_id, lvl_idx, drop_pct, mult, tp_pct, cb_pct)
                          SELECT $newSid, lvl_idx, drop_pct, mult, tp_pct, cb_pct
                          FROM strategy_levels
                          WHERE strategy_id = $masterId");
    if (!$q2) throw new Exception("copy strategy_levels failed: ".$mysqli->error);

    $mysqli->commit();
  }
} catch (Throwable $e) {
  // best-effort: do not block registration if cloning fails
  try { $mysqli->rollback(); } catch (Throwable $e2) {}
}

if ($uid <= 0) {
  header(
    'Location: /auth/register.php?msg='.
    urlencode('Помилка реєстрації (немає ID користувача)').
    '&next='.urlencode($next)
  );
  exit;
}

// Прибираємо старі сесії з цього IP (якщо є)
if ($ip !== '') {
  $ipEsc = $mysqli->real_escape_string($ip);
  $mysqli->query("DELETE FROM sessions_auth WHERE ip='".$ipEsc."'");
}

// Створюємо сесію
$token = bin2hex(random_bytes(16));

$ipEsc  = $mysqli->real_escape_string($ip);
$uaEsc  = $mysqli->real_escape_string($ua);
$tokEsc = $mysqli->real_escape_string($token);

$mysqli->query(
  "INSERT INTO sessions_auth(token,ip,ua,user_id)
   VALUES('$tokEsc','$ipEsc','$uaEsc',$uid)"
);


  // set sid cookie for whole site (needed for /api)
// Кука сесії
setcookie(
  'sid',
  $token,
  array(
    'expires'  => time() + auth_session_cookie_sec(),
    'path'     => '/',
    'secure'   => true,
    'httponly' => true,
    'samesite' => 'Lax',
  )
);

setcookie('strim_reg_conv', '1', [
  'expires' => time() + 600,
  'path' => '/',
  'secure' => true,
  'httponly' => true,
  'samesite' => 'Lax',
]);
setcookie('strim_reg_event', '1', [
  'expires' => time() + 600,
  'path' => '/',
  'secure' => true,
  'httponly' => false,
  'samesite' => 'Lax',
]);
setcookie('strim_reg_welcome', '1', [
  'expires' => time() + 7 * 86400,
  'path' => '/',
  'secure' => true,
  'httponly' => false,
  'samesite' => 'Lax',
]);

// Серверний register_success завжди (без consent) — ground truth реєстрацій.
try {
  require_once __DIR__ . '/../api/_web_analytics.php';
  web_analytics_insert_event($mysqli, 'register_success', (int)$uid, '/auth/register.php', [
    'email_domain' => (($p = strrpos($email, '@')) !== false) ? substr($email, $p + 1) : '',
    'via' => 'do_register',
  ], false);
} catch (Throwable $e) {
  // аналітика не повинна ламати реєстрацію
}

header('Location: '.$next);
exit;
