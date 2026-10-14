<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../auth/_session_user.php';

Dotenv\Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();
header('Content-Type: application/json; charset=utf-8');

$db = @new mysqli(
    (string)$_ENV['DB_HOST'],
    (string)$_ENV['DB_USER'],
    (string)$_ENV['DB_PASS'],
    (string)$_ENV['DB_NAME']
);
if ($db->connect_errno) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'db'], JSON_UNESCAPED_UNICODE);
    exit;
}
$db->set_charset('utf8mb4');

$uid = current_user_id($db);
if ($uid <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'auth'], JSON_UNESCAPED_UNICODE);
    exit;
}
$admRes = $db->query("SELECT is_admin FROM users WHERE id=" . (int)$uid . " LIMIT 1");
$admRow = $admRes ? $admRes->fetch_assoc() : null;
if (!$admRow || (int)($admRow['is_admin'] ?? 0) !== 1) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

$days = max(1, min(90, (int)($_GET['days'] ?? 14)));
$rangeSql = "event_at >= (NOW() - INTERVAL {$days} DAY)";

function fetch_assoc_all($res): array {
    $out = [];
    while ($res && ($r = $res->fetch_assoc())) {
        $out[] = $r;
    }
    return $out;
}

$summaryQ = $db->query(
    "SELECT
        COUNT(*) AS events_total,
        SUM(CASE WHEN event_name='page_view' THEN 1 ELSE 0 END) AS page_views,
        COUNT(DISTINCT visitor_id) AS unique_visitors,
        COUNT(DISTINCT session_id) AS sessions,
        SUM(CASE WHEN event_name='register_view' THEN 1 ELSE 0 END) AS register_views,
        SUM(CASE WHEN event_name='register_submit' THEN 1 ELSE 0 END) AS register_submits,
        SUM(CASE WHEN event_name='register_success' THEN 1 ELSE 0 END) AS register_success
     FROM web_analytics_events
     WHERE {$rangeSql}"
);
$summary = $summaryQ ? ($summaryQ->fetch_assoc() ?: []) : [];

$timeline = fetch_assoc_all($db->query(
    "SELECT DATE(event_at) d,
            COUNT(*) events_total,
            SUM(CASE WHEN event_name='page_view' THEN 1 ELSE 0 END) page_views,
            COUNT(DISTINCT visitor_id) unique_visitors,
            SUM(CASE WHEN event_name='register_view' THEN 1 ELSE 0 END) register_views,
            SUM(CASE WHEN event_name='register_submit' THEN 1 ELSE 0 END) register_submits,
            SUM(CASE WHEN event_name='register_success' THEN 1 ELSE 0 END) register_success
     FROM web_analytics_events
     WHERE {$rangeSql}
     GROUP BY DATE(event_at)
     ORDER BY d ASC"
));

$sourceSplit = fetch_assoc_all($db->query(
    "SELECT source_channel, COUNT(*) c, COUNT(DISTINCT visitor_id) u
     FROM web_analytics_events
     WHERE {$rangeSql} AND event_name='page_view'
     GROUP BY source_channel
     ORDER BY c DESC"
));

$deviceSplit = fetch_assoc_all($db->query(
    "SELECT device_type, COUNT(*) c, COUNT(DISTINCT visitor_id) u
     FROM web_analytics_events
     WHERE {$rangeSql} AND event_name='page_view'
     GROUP BY device_type
     ORDER BY c DESC"
));

$countrySplit = fetch_assoc_all($db->query(
    "SELECT COALESCE(NULLIF(country_code,''),'??') country_code, COUNT(*) c
     FROM web_analytics_events
     WHERE {$rangeSql} AND event_name='page_view'
     GROUP BY country_code
     ORDER BY c DESC
     LIMIT 20"
));

$topPages = fetch_assoc_all($db->query(
    "SELECT path, COUNT(*) c, COUNT(DISTINCT visitor_id) u
     FROM web_analytics_events
     WHERE {$rangeSql} AND event_name='page_view'
     GROUP BY path
     ORDER BY c DESC
     LIMIT 25"
));

$registerPath = '/auth/register.php';
$registerBySource = fetch_assoc_all($db->query(
    "SELECT source_channel, COUNT(*) c, COUNT(DISTINCT visitor_id) u
     FROM web_analytics_events
     WHERE {$rangeSql} AND event_name='page_view' AND path='{$registerPath}'
     GROUP BY source_channel
     ORDER BY c DESC"
));

$funnel = [
    'register_views' => (int)($summary['register_views'] ?? 0),
    'register_submits' => (int)($summary['register_submits'] ?? 0),
    'register_success' => (int)($summary['register_success'] ?? 0),
];
$funnel['view_to_submit_pct'] = $funnel['register_views'] > 0
    ? round(($funnel['register_submits'] * 100.0) / $funnel['register_views'], 2) : 0.0;
$funnel['submit_to_success_pct'] = $funnel['register_submits'] > 0
    ? round(($funnel['register_success'] * 100.0) / $funnel['register_submits'], 2) : 0.0;

echo json_encode([
    'ok' => true,
    'days' => $days,
    'summary' => $summary,
    'funnel' => $funnel,
    'timeline' => $timeline,
    'source_split' => $sourceSplit,
    'device_split' => $deviceSplit,
    'country_split' => $countrySplit,
    'top_pages' => $topPages,
    'register_page_by_source' => $registerBySource,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
