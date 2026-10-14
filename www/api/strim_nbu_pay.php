<?php
declare(strict_types=1);
/**
 * JSON: deeplink https://bank.gov.ua/qr/... за структурою НБУ (версія 002, кодування 2, Win1251 для кирилиці).
 * Реквізити ТОВ «СтрІМ Алго» — лише на сервері; мін. депозит 500 UAH (змінна сума).
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../auth/_session_user.php';

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(dirname(__DIR__, 2));
$dotenv->safeLoad();

const STRIM_PAY_IBAN = 'UA343052990000026003026120038';
const STRIM_PAY_EDRPOU = '46241790';
const STRIM_PAY_NAME = 'ТОВ «СтрІМ Алго»';
const STRIM_PAY_AMOUNT_UAH = 500;
const STRIM_PAY_MIN_UAH = 500;

function strim_pay_json_error(string $code, int $http = 400): void {
  http_response_code($http);
  echo json_encode(['ok' => false, 'error' => $code], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function strim_to_cp1251(string $utf8): string {
  if (function_exists('iconv')) {
    $out = @iconv('UTF-8', 'Windows-1251//IGNORE', $utf8);
    if (is_string($out) && $out !== '') {
      return $out;
    }
  }
  return $utf8;
}

function strim_sanitize_tag(string $raw): string {
  $raw = trim($raw);
  if ($raw === '') {
    return '';
  }
  if (mb_strlen($raw, 'UTF-8') > 96) {
    $raw = mb_substr($raw, 0, 96, 'UTF-8');
  }
  if (!preg_match('/^[\p{L}\p{N}@._+\- ]+$/u', $raw)) {
    return '';
  }
  return $raw;
}

function strim_build_purpose(string $suffix): string {
  $base = 'Оплата доступу до програмного сервісу STRIM без ПДВ.';
  $suffix = trim($suffix);
  if ($suffix === '') {
    return $base;
  }
  return $base . ' ' . $suffix;
}

function strim_base64url_encode(string $binary): string {
  return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
}

/** @return array{0:string,1:int} [payload binary, amount_uah] */
function strim_build_nbu_payload(string $purposeUtf8, int $amountUah): array {
  if ($amountUah < 1 || $amountUah > 999999999) {
    $amountUah = STRIM_PAY_AMOUNT_UAH;
  }
  $amountLine = 'UAH' . (string) $amountUah;

  $name = strim_to_cp1251(mb_substr(STRIM_PAY_NAME, 0, 70, 'UTF-8'));
  $purpose = strim_to_cp1251(mb_substr($purposeUtf8, 0, 140, 'UTF-8'));

  $eol = "\r\n";
  $parts = [
    'BCD',
    '002',
    '2',
    'UCT',
    '',
    $name,
    STRIM_PAY_IBAN,
    $amountLine,
    STRIM_PAY_EDRPOU,
    '',
    '',
    $purpose,
    '',
  ];
  $raw = implode($eol, $parts) . $eol;
  return [$raw, $amountUah];
}

// --- input: POST JSON { "tag": "...", "amount_uah": 500 } optional
$tagIn = '';
$amountReq = STRIM_PAY_AMOUNT_UAH;

$ct = $_SERVER['CONTENT_TYPE'] ?? '';
if (stripos($ct, 'application/json') !== false) {
  $body = file_get_contents('php://input');
  $j = is_string($body) ? json_decode($body, true) : null;
  if (is_array($j) && isset($j['tag']) && is_string($j['tag'])) {
    $tagIn = strim_sanitize_tag($j['tag']);
  }
  if (is_array($j) && isset($j['amount_uah'])) {
    $reqAmt = (int) round((float) $j['amount_uah']);
    if ($reqAmt >= STRIM_PAY_MIN_UAH && $reqAmt <= 999999999) {
      $amountReq = $reqAmt;
    } elseif ($reqAmt > 0 && $reqAmt < STRIM_PAY_MIN_UAH) {
      strim_pay_json_error('min_deposit_uah', 400);
    }
  }
} elseif (isset($_GET['amount_uah']) || isset($_POST['amount_uah'])) {
  $reqAmt = (int) round((float) ($_GET['amount_uah'] ?? $_POST['amount_uah'] ?? 0));
  if ($reqAmt >= STRIM_PAY_MIN_UAH && $reqAmt <= 999999999) {
    $amountReq = $reqAmt;
  } elseif ($reqAmt > 0 && $reqAmt < STRIM_PAY_MIN_UAH) {
    strim_pay_json_error('min_deposit_uah', 400);
  }
}

$host = $_ENV['DB_HOST'] ?? '';
$name = $_ENV['DB_NAME'] ?? '';
$user = $_ENV['DB_USER'] ?? '';
$pass = $_ENV['DB_PASS'] ?? '';

$mysqli = @new mysqli($host, $user, $pass, $name);
if ($mysqli->connect_errno) {
  strim_pay_json_error('db_connect_failed', 500);
}
$mysqli->set_charset('utf8mb4');

$uid = current_user_id($mysqli, 86400);
$suffix = $tagIn;

if ($uid > 0) {
  $uid = (int) $uid;
  $q = $mysqli->query("SELECT ref_code, email, nickname FROM users WHERE id={$uid} LIMIT 1");
  if ($q && ($row = $q->fetch_assoc())) {
    $ref = strtoupper(trim((string) ($row['ref_code'] ?? '')));
    $ref = preg_match('/^[A-Z0-9]{4,32}$/', $ref) ? $ref : '';
    if ($ref !== '') {
      $suffix = $ref;
    } elseif ($suffix === '') {
      $suffix = 'id:' . (string) $uid;
    }
  }
}

$mysqli->close();

$purposeFull = strim_build_purpose($suffix);
[$payloadBin, $amountUsed] = strim_build_nbu_payload($purposeFull, $amountReq);
$deeplink = 'https://bank.gov.ua/qr/' . strim_base64url_encode($payloadBin);

echo json_encode([
  'ok' => true,
  'logged_in' => $uid > 0,
  'deeplink' => $deeplink,
  'purpose' => $purposeFull,
  'amount_uah' => $amountUsed,
  'min_uah' => STRIM_PAY_MIN_UAH,
  'iban' => STRIM_PAY_IBAN,
  'edrpou' => STRIM_PAY_EDRPOU,
  'recipient' => STRIM_PAY_NAME,
  'payer_hint' => $suffix !== '' ? $suffix : null,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
