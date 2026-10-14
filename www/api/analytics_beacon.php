<?php
declare(strict_types=1);
if ((($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' || isset($_GET['v']) || basename(__FILE__) !== 'session_public.php') && (($_COOKIE['strim_consent_v2'] ?? '') !== 'all')) { http_response_code(204); exit; }

/**
 * Легкий GET-beacon (1×1 gif) — спрацьовує до defer-скриптів, краще ловить bounce з реклами.
 */
header('Content-Type: image/gif');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

require_once __DIR__ . '/../vendor/autoload.php';
Dotenv\Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();

$visitorId = substr((string)($_GET['v'] ?? ''), 0, 64);
$sessionId = substr((string)($_GET['s'] ?? ''), 0, 64);
$eventName = substr((string)($_GET['e'] ?? 'page_view'), 0, 64);
$path = substr((string)($_GET['p'] ?? '/'), 0, 255);
if ($visitorId === '' || $sessionId === '' || $path === '') {
    echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
    exit;
}

$allowedEvents = ['page_view', 'register_view', 'register_submit', 'register_success'];
if (!in_array($eventName, $allowedEvents, true)) {
    $eventName = 'page_view';
}

$utmSource = substr((string)($_GET['us'] ?? ''), 0, 80);
$utmMedium = substr((string)($_GET['um'] ?? ''), 0, 80);
$utmCampaign = substr((string)($_GET['uc'] ?? ''), 0, 120);
$gclid = substr((string)($_GET['g'] ?? ''), 0, 180);
$refHost = substr((string)($_GET['rh'] ?? ''), 0, 191);
$device = substr((string)($_GET['d'] ?? 'desktop'), 0, 16);
if (!in_array($device, ['desktop', 'mobile', 'tablet'], true)) {
    $device = 'desktop';
}

$sourceChannel = substr((string)($_GET['sc'] ?? 'direct'), 0, 32);
if ($gclid !== '' || in_array(strtolower($utmMedium), ['cpc', 'ppc', 'paid'], true)) {
    $sourceChannel = 'paid_search';
}

$country = strtoupper(substr((string)($_GET['cc'] ?? ''), 0, 2));
if ($country === '' && !empty($_SERVER['HTTP_CF_IPCOUNTRY'])) {
    $country = strtoupper(substr((string)$_SERVER['HTTP_CF_IPCOUNTRY'], 0, 2));
}
$country = substr($country, 0, 2);

$lang = substr((string)($_GET['lg'] ?? ''), 0, 20);
$tz = substr((string)($_GET['tz'] ?? ''), 0, 64);
$ipHash = hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? ''));
$ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

$db = @new mysqli(
    (string)($_ENV['DB_HOST'] ?? ''),
    (string)($_ENV['DB_USER'] ?? ''),
    (string)($_ENV['DB_PASS'] ?? ''),
    (string)($_ENV['DB_NAME'] ?? '')
);
if (!$db->connect_errno) {
    $db->set_charset('utf8mb4');
    $uid = 0;
    $empty = '';
    $stmt = $db->prepare(
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
            $empty
        );
        $stmt->execute();
        $stmt->close();
    }
    $db->close();
}

echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
