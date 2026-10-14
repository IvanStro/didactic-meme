<?php
declare(strict_types=1);
if ((($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' || isset($_GET['v']) || basename(__FILE__) !== 'session_public.php') && (($_COOKIE['strim_consent_v2'] ?? '') !== 'all')) { http_response_code(204); exit; }


header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

require_once __DIR__ . '/../vendor/autoload.php';

Dotenv\Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = (string)file_get_contents('php://input');
$in = json_decode($raw, true);
if (!is_array($in)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_json'], JSON_UNESCAPED_UNICODE);
    exit;
}

$visitorId = substr((string)($in['visitor_id'] ?? ''), 0, 64);
$sessionId = substr((string)($in['session_id'] ?? ''), 0, 64);
$eventName = substr((string)($in['event_name'] ?? ''), 0, 64);
$path = substr((string)($in['path'] ?? ''), 0, 255);
$referrerHost = substr((string)($in['referrer_host'] ?? ''), 0, 191);
$utmSource = substr((string)($in['utm_source'] ?? ''), 0, 80);
$utmMedium = substr((string)($in['utm_medium'] ?? ''), 0, 80);
$utmCampaign = substr((string)($in['utm_campaign'] ?? ''), 0, 120);
$utmTerm = substr((string)($in['utm_term'] ?? ''), 0, 120);
$utmContent = substr((string)($in['utm_content'] ?? ''), 0, 120);
$gclid = substr((string)($in['gclid'] ?? ''), 0, 180);
$deviceType = strtolower(substr((string)($in['device_type'] ?? 'desktop'), 0, 16));
$lang = substr((string)($in['lang'] ?? ''), 0, 20);
$tz = substr((string)($in['tz'] ?? ''), 0, 64);
$props = $in['props'] ?? null;

if ($visitorId === '' || $sessionId === '' || $eventName === '' || $path === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'missing_fields'], JSON_UNESCAPED_UNICODE);
    exit;
}

$allowedEvents = ['page_view', 'register_view', 'register_submit', 'register_success'];
if (!in_array($eventName, $allowedEvents, true)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'bad_event'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (!in_array($deviceType, ['desktop', 'mobile', 'tablet'], true)) {
    $deviceType = 'desktop';
}

$sourceChannel = 'direct';
if ($gclid !== '' || stripos($utmMedium, 'cpc') !== false || stripos($utmMedium, 'paid') !== false) {
    $sourceChannel = 'paid_search';
} elseif ($utmSource !== '') {
    $sourceChannel = 'utm';
} elseif ($referrerHost !== '') {
    if (preg_match('/google\\.|bing\\.|yahoo\\.|duckduckgo\\./i', $referrerHost)) {
        $sourceChannel = 'organic_search';
    } else {
        $sourceChannel = 'referral';
    }
}

$country = strtoupper(trim((string)($_SERVER['HTTP_CF_IPCOUNTRY'] ?? $_SERVER['GEOIP_COUNTRY_CODE'] ?? '')));
if (!preg_match('/^[A-Z]{2}$/', $country)) {
    $country = null;
}

$ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
$ipHash = $ip !== '' ? hash('sha256', $ip) : null;
$propsJson = null;
if (is_array($props) || is_object($props)) {
    $propsJson = json_encode($props, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

$db = @new mysqli(
    (string)($_ENV['DB_HOST'] ?? ''),
    (string)($_ENV['DB_USER'] ?? ''),
    (string)($_ENV['DB_PASS'] ?? ''),
    (string)($_ENV['DB_NAME'] ?? '')
);
if ($db->connect_errno) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'db_connect'], JSON_UNESCAPED_UNICODE);
    exit;
}
$db->set_charset('utf8mb4');

$uid = 0;

$stmt = $db->prepare(
    "INSERT INTO web_analytics_events
    (visitor_id, session_id, user_id, event_name, path, source_channel, referrer_host,
     utm_source, utm_medium, utm_campaign, utm_term, utm_content, gclid,
     device_type, country_code, lang, tz, ip_hash, user_agent, props_json)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'db_prepare'], JSON_UNESCAPED_UNICODE);
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
    $referrerHost,
    $utmSource,
    $utmMedium,
    $utmCampaign,
    $utmTerm,
    $utmContent,
    $gclid,
    $deviceType,
    $country,
    $lang,
    $tz,
    $ipHash,
    $ua,
    $propsJson
);
$ok = $stmt->execute();
$stmt->close();
$db->close();

if (!$ok) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'db_execute'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
