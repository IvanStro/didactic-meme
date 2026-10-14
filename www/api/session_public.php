<?php
declare(strict_types=1);

/**
 * Публічна перевірка сесії (без api_gate) + first-party analytics beacon.
 * GET без ?v= — logged_in (без consent).
 * GET ?v=… / POST — події аналітики (cookie all|essential).
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../auth/_session_user.php';
require_once __DIR__ . '/_web_analytics.php';

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(dirname(__DIR__, 2));
$dotenv->safeLoad();

$host = $_ENV['DB_HOST'] ?? '';
$name = $_ENV['DB_NAME'] ?? '';
$user = $_ENV['DB_USER'] ?? '';
$pass = $_ENV['DB_PASS'] ?? '';

$mysqli = @new mysqli($host, $user, $pass, $name);
if ($mysqli->connect_errno) {
  http_response_code(200);
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store, no-cache, must-revalidate');
  echo json_encode(['ok' => true, 'logged_in' => false], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}
$mysqli->set_charset('utf8mb4');

$gif1x1 = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$isAnalyticsGet = ($method === 'GET' && isset($_GET['v']));
$isAnalyticsPost = ($method === 'POST');

if (($isAnalyticsGet || $isAnalyticsPost) && !web_analytics_first_party_ok()) {
  if ($isAnalyticsGet) {
    header('Content-Type: image/gif');
    echo $gif1x1;
  } else {
    http_response_code(204);
  }
  exit;
}

if ($isAnalyticsGet) {
  header('Content-Type: image/gif');
  header('Cache-Control: no-store, no-cache, must-revalidate');
  $visitorId = substr((string)($_GET['v'] ?? ''), 0, 64);
  $sessionId = substr((string)($_GET['s'] ?? ''), 0, 64);
  $path = substr((string)($_GET['p'] ?? '/'), 0, 255);
  if ($visitorId === '' || $sessionId === '' || $path === '') {
    echo $gif1x1;
    exit;
  }
  $eventName = substr((string)($_GET['e'] ?? 'page_view'), 0, 64);
  $allowedEvents = ['page_view', 'register_view', 'register_start', 'register_submit', 'register_success', 'cta_click'];
  if (!in_array($eventName, $allowedEvents, true)) {
    $eventName = 'page_view';
  }
  $refHost = substr((string)($_GET['rh'] ?? ''), 0, 191);
  if (web_analytics_is_own_host($refHost)) {
    $refHost = '';
  }
  $utmSource = substr((string)($_GET['us'] ?? ''), 0, 80);
  $utmMedium = substr((string)($_GET['um'] ?? ''), 0, 80);
  $utmCampaign = substr((string)($_GET['uc'] ?? ''), 0, 120);
  $gclid = substr((string)($_GET['g'] ?? ''), 0, 180);
  $att = [
    'utm_source' => $utmSource,
    'utm_medium' => $utmMedium,
    'utm_campaign' => $utmCampaign,
    'gclid' => $gclid,
  ];
  $sourceChannel = web_analytics_classify_channel($att, $refHost);
  $scClient = substr((string)($_GET['sc'] ?? ''), 0, 32);
  if ($scClient !== '' && $sourceChannel === 'direct' && !in_array($scClient, ['direct', 'referral'], true)) {
    // клієнт уже класифікував paid/social/organic — не затираємо, якщо сервер не бачить UTM
    if (in_array($scClient, ['paid_search', 'social', 'organic_search'], true)) {
      $sourceChannel = $scClient;
    }
  }
  $device = substr((string)($_GET['d'] ?? 'desktop'), 0, 16);
  $country = strtoupper(substr((string)($_GET['cc'] ?? ''), 0, 2));
  if ($country === '' && !empty($_SERVER['HTTP_CF_IPCOUNTRY'])) {
    $country = strtoupper(substr((string)$_SERVER['HTTP_CF_IPCOUNTRY'], 0, 2));
  }
  $country = substr($country, 0, 2);
  $lang = substr((string)($_GET['lg'] ?? ''), 0, 20);
  $tz = substr((string)($_GET['tz'] ?? ''), 0, 64);
  $ipHash = hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? ''));
  $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
  $uid = 0;
  $empty = '';
  $propsJson = $empty;
  if (isset($_GET['pr']) && is_string($_GET['pr']) && $_GET['pr'] !== '') {
    $rawPr = rawurldecode((string)$_GET['pr']);
    $pj = json_decode($rawPr, true);
    if (is_array($pj)) {
      $propsJson = (string)json_encode($pj, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      if (strlen($propsJson) > 1000) {
        $propsJson = substr($propsJson, 0, 1000);
      }
    }
  }
  $stmt = $mysqli->prepare(
    "INSERT INTO web_analytics_events
    (visitor_id, session_id, user_id, event_name, path, source_channel, referrer_host,
     utm_source, utm_medium, utm_campaign, utm_term, utm_content, gclid,
     device_type, country_code, lang, tz, ip_hash, user_agent, props_json)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
  );
  if ($stmt) {
    $stmt->bind_param(
      'ssisssssssssssssssss',
      $visitorId,
      $sessionId,
      $uid,
      $eventName,
      $path,
      $sourceChannel,
      $refHost,
      $utmSource,
      $utmMedium,
      $utmCampaign,
      $empty,
      $empty,
      $gclid,
      $device,
      $country,
      $lang,
      $tz,
      $ipHash,
      $ua,
      $propsJson
    );
    $stmt->execute();
    $stmt->close();
  }
  $mysqli->close();
  echo $gif1x1;
  exit;
}

if ($isAnalyticsPost) {
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store, no-cache, must-revalidate');
  $raw = (string)file_get_contents('php://input');
  $in = json_decode($raw, true);
  if (!is_array($in)) {
    echo json_encode(['ok' => false, 'error' => 'invalid_json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
  }

  $visitorId = substr((string)($in['visitor_id'] ?? ''), 0, 64);
  $sessionId = substr((string)($in['session_id'] ?? ''), 0, 64);
  $eventName = substr((string)($in['event_name'] ?? ''), 0, 64);
  $path = substr((string)($in['path'] ?? ''), 0, 255);
  if ($visitorId === '' || $sessionId === '' || $eventName === '' || $path === '') {
    echo json_encode(['ok' => false, 'error' => 'missing_fields'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
  }
  $allowedEvents = ['page_view', 'register_view', 'register_start', 'register_submit', 'register_success', 'cta_click'];
  if (!in_array($eventName, $allowedEvents, true)) {
    echo json_encode(['ok' => false, 'error' => 'bad_event'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
  }

  $refHost = substr((string)($in['referrer_host'] ?? ''), 0, 191);
  if (web_analytics_is_own_host($refHost)) {
    $refHost = '';
  }
  $utmSource = substr((string)($in['utm_source'] ?? ''), 0, 80);
  $utmMedium = substr((string)($in['utm_medium'] ?? ''), 0, 80);
  $utmCampaign = substr((string)($in['utm_campaign'] ?? ''), 0, 120);
  $utmTerm = substr((string)($in['utm_term'] ?? ''), 0, 120);
  $utmContent = substr((string)($in['utm_content'] ?? ''), 0, 120);
  $gclid = substr((string)($in['gclid'] ?? ''), 0, 180);
  $att = [
    'utm_source' => $utmSource,
    'utm_medium' => $utmMedium,
    'utm_campaign' => $utmCampaign,
    'gclid' => $gclid,
  ];
  $sourceChannel = web_analytics_classify_channel($att, $refHost);
  $device = substr((string)($in['device_type'] ?? 'desktop'), 0, 16);
  $country = strtoupper(substr((string)($in['country_code'] ?? ''), 0, 2));
  if ($country === '' && !empty($_SERVER['HTTP_CF_IPCOUNTRY'])) {
    $country = strtoupper(substr((string)$_SERVER['HTTP_CF_IPCOUNTRY'], 0, 2));
  }
  $country = substr($country, 0, 2);
  $lang = substr((string)($in['lang'] ?? ''), 0, 20);
  $tz = substr((string)($in['tz'] ?? ''), 0, 64);
  $ipHash = hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? ''));
  $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
  $propsJson = '';
  if (isset($in['props']) && is_array($in['props'])) {
    $propsJson = (string)json_encode($in['props'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  }
  $uid = 0;

  $stmt = $mysqli->prepare(
    "INSERT INTO web_analytics_events
    (visitor_id, session_id, user_id, event_name, path, source_channel, referrer_host,
     utm_source, utm_medium, utm_campaign, utm_term, utm_content, gclid,
     device_type, country_code, lang, tz, ip_hash, user_agent, props_json)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
  );
  if (!$stmt) {
    echo json_encode(['ok' => false, 'error' => 'db_prepare'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
  }
  $stmt->bind_param(
    'ssisssssssssssssssss',
    $visitorId,
    $sessionId,
    $uid,
    $eventName,
    $path,
    $sourceChannel,
    $refHost,
    $utmSource,
    $utmMedium,
    $utmCampaign,
    $utmTerm,
    $utmContent,
    $gclid,
    $device,
    $country,
    $lang,
    $tz,
    $ipHash,
    $ua,
    $propsJson
  );
  $ok = $stmt->execute();
  $stmt->close();
  echo json_encode(['ok' => (bool)$ok], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
$uid = current_user_id($mysqli, 86400);
$mysqli->close();

echo json_encode([
  'ok' => true,
  'logged_in' => $uid > 0,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
