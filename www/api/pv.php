<?php
declare(strict_types=1);
if ((($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' || isset($_GET['v']) || basename(__FILE__) !== 'session_public.php') && (($_COOKIE['strim_consent_v2'] ?? '') !== 'all')) { http_response_code(204); exit; }


header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

require_once __DIR__ . '/../vendor/autoload.php';
Dotenv\Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();

$raw = (string)file_get_contents('php://input');
$in = json_decode($raw, true);
if (!is_array($in)) {
    $in = [];
}

$visitorId = substr((string)($in['visitor_id'] ?? ''), 0, 64);
$sessionId = substr((string)($in['session_id'] ?? ''), 0, 64);
$eventName = substr((string)($in['event_name'] ?? ''), 0, 64);
$path = substr((string)($in['path'] ?? ''), 0, 255);
if ($visitorId === '' || $sessionId === '' || $eventName === '' || $path === '') {
    echo json_encode(['ok' => false, 'error' => 'missing'], JSON_UNESCAPED_UNICODE);
    exit;
}

$db = @new mysqli(
    (string)($_ENV['DB_HOST'] ?? ''),
    (string)($_ENV['DB_USER'] ?? ''),
    (string)($_ENV['DB_PASS'] ?? ''),
    (string)($_ENV['DB_NAME'] ?? '')
);
if ($db->connect_errno) {
    echo json_encode(['ok' => false, 'error' => 'db'], JSON_UNESCAPED_UNICODE);
    exit;
}
$db->set_charset('utf8mb4');

$src = substr((string)($in['source_channel'] ?? 'direct'), 0, 32);
$device = substr((string)($in['device_type'] ?? 'desktop'), 0, 16);
$country = substr((string)($in['country_code'] ?? ''), 0, 2);
$refHost = substr((string)($in['referrer_host'] ?? ''), 0, 191);
$utmSource = substr((string)($in['utm_source'] ?? ''), 0, 80);
$utmMedium = substr((string)($in['utm_medium'] ?? ''), 0, 80);
$utmCampaign = substr((string)($in['utm_campaign'] ?? ''), 0, 120);
$utmTerm = substr((string)($in['utm_term'] ?? ''), 0, 120);
$utmContent = substr((string)($in['utm_content'] ?? ''), 0, 120);
$gclid = substr((string)($in['gclid'] ?? ''), 0, 180);
$lang = substr((string)($in['lang'] ?? ''), 0, 20);
$tz = substr((string)($in['tz'] ?? ''), 0, 64);
$ipHash = hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? ''));
$ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
$propsJson = isset($in['props']) ? json_encode($in['props'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;

$stmt = $db->prepare(
    "INSERT INTO web_analytics_events
    (visitor_id, session_id, user_id, event_name, path, source_channel, referrer_host,
     utm_source, utm_medium, utm_campaign, utm_term, utm_content, gclid,
     device_type, country_code, lang, tz, ip_hash, user_agent, props_json)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
);
if (!$stmt) {
    echo json_encode(['ok' => false, 'error' => 'prepare'], JSON_UNESCAPED_UNICODE);
    exit;
}
$uid = 0;
$stmt->bind_param(
    'ssisssssssssssssssss',
    $visitorId,
    $sessionId,
    $uid,
    $eventName,
    $path,
    $src,
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
$db->close();

echo json_encode(['ok' => (bool)$ok], JSON_UNESCAPED_UNICODE);
