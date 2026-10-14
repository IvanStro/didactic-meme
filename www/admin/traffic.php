<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../auth/_session_user.php';
require_once __DIR__ . '/_traffic_decode.php';

Dotenv\Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();

$db = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
$db->set_charset('utf8mb4');

$uid = current_user_id($db);
if ($uid <= 0) {
    header('Location: /auth/login.php?next=' . urlencode('/admin/traffic.php'), true, 302);
    exit;
}
$admRes = $db->query("SELECT is_admin FROM users WHERE id=" . (int)$uid . " LIMIT 1");
$adm = $admRes ? $admRes->fetch_assoc() : null;
if (!$adm || (int)($adm['is_admin'] ?? 0) !== 1) {
    http_response_code(403);
    echo '403 Forbidden';
    exit;
}

$days = max(1, min(90, (int)($_GET['days'] ?? 14)));
$igScope = (string)($_GET['ig'] ?? 'launch');
if (!in_array($igScope, ['launch', 'any'], true)) {
    $igScope = 'launch';
}
$ttScope = (string)($_GET['tt'] ?? 'launch');
if (!in_array($ttScope, ['launch', 'any'], true)) {
    $ttScope = 'launch';
}
$rangeSql = "event_at >= (NOW() - INTERVAL {$days} DAY)";
$igFilterSql = $igScope === 'any'
    ? traffic_instagram_any_sql()
    : traffic_instagram_launch_sql();
$igFilterSqlE = $igScope === 'any'
    ? traffic_instagram_any_sql('e')
    : traffic_instagram_launch_sql('e');
$igFilterLabel = $igScope === 'any'
    ? 'усі Instagram (utm_source=instagram або /ig)'
    : 'Instagram bio launch (utm або /ig)';
$ttFilterSql = $ttScope === 'any'
    ? traffic_tiktok_any_sql()
    : traffic_tiktok_launch_sql();
$ttFilterSqlE = $ttScope === 'any'
    ? traffic_tiktok_any_sql('e')
    : traffic_tiktok_launch_sql('e');
$ttFilterLabel = $ttScope === 'any'
    ? 'усі TikTok (utm_source=tiktok або /tt)'
    : 'TikTok bio launch (utm або /tt)';

$publicSql = traffic_public_path_sql();
$appSql = traffic_app_path_sql();
$chanSql = traffic_channel_effective_sql();

$summaryQ = $db->query(
    "SELECT
        COUNT(*) AS events_total,
        SUM(CASE WHEN event_name='page_view' THEN 1 ELSE 0 END) AS page_views,
        COUNT(DISTINCT visitor_id) AS unique_visitors,
        COUNT(DISTINCT session_id) AS sessions,
        SUM(CASE WHEN event_name='register_view'
                  OR (event_name='page_view' AND path LIKE '/auth/register.php%')
             THEN 1 ELSE 0 END) AS register_views,
        SUM(CASE WHEN event_name='register_submit' THEN 1 ELSE 0 END) AS register_submits,
        SUM(CASE WHEN event_name='register_success' THEN 1 ELSE 0 END) AS register_success,
        SUM(CASE WHEN event_name='cta_click' THEN 1 ELSE 0 END) AS cta_clicks,
        SUM(CASE WHEN event_name='page_view' AND gclid <> '' THEN 1 ELSE 0 END) AS ads_clicks,
        SUM(CASE WHEN event_name='page_view' AND source_channel='paid_search' THEN 1 ELSE 0 END) AS paid_page_views
     FROM web_analytics_events
     WHERE {$rangeSql}"
);
$summary = $summaryQ ? ($summaryQ->fetch_assoc() ?: []) : [];

// Маркетинг (публічні сторінки) vs кабінет — щоб KPI не роздували існуючі юзери.
$acq = ['page_views' => 0, 'unique_visitors' => 0, 'sessions' => 0];
$acqQ = $db->query(
    "SELECT
        SUM(CASE WHEN event_name='page_view' THEN 1 ELSE 0 END) AS page_views,
        COUNT(DISTINCT CASE WHEN event_name='page_view' THEN visitor_id END) AS unique_visitors,
        COUNT(DISTINCT CASE WHEN event_name='page_view' THEN session_id END) AS sessions
     FROM web_analytics_events
     WHERE {$rangeSql} AND {$publicSql}"
);
if ($acqQ) {
    $acq = $acqQ->fetch_assoc() ?: $acq;
}
$appHit = ['page_views' => 0, 'unique_visitors' => 0, 'sessions' => 0];
$appQ = $db->query(
    "SELECT
        SUM(CASE WHEN event_name='page_view' THEN 1 ELSE 0 END) AS page_views,
        COUNT(DISTINCT CASE WHEN event_name='page_view' THEN visitor_id END) AS unique_visitors,
        COUNT(DISTINCT CASE WHEN event_name='page_view' THEN session_id END) AS sessions
     FROM web_analytics_events
     WHERE {$rangeSql} AND {$appSql}"
);
if ($appQ) {
    $appHit = $appQ->fetch_assoc() ?: $appHit;
}

$usersCreated = 0;
$ucQ = $db->query(
    "SELECT COUNT(*) c FROM users WHERE created_at >= (NOW() - INTERVAL {$days} DAY)"
);
if ($ucQ) {
    $usersCreated = (int)(($ucQ->fetch_assoc() ?: [])['c'] ?? 0);
}

$source = [];
$sourceRes = $db->query(
  "SELECT {$chanSql} AS source_channel, COUNT(*) c, COUNT(DISTINCT visitor_id) u
   FROM web_analytics_events
   WHERE {$rangeSql} AND event_name='page_view' AND {$publicSql}
   GROUP BY source_channel
   ORDER BY c DESC"
);
while ($sourceRes && ($r = $sourceRes->fetch_assoc())) {
    $r['label'] = traffic_channel_label((string)$r['source_channel']);
    $source[] = $r;
}

$device = [];
$deviceRes = $db->query(
  "SELECT device_type, COUNT(*) c, COUNT(DISTINCT visitor_id) u
   FROM web_analytics_events
   WHERE {$rangeSql} AND event_name='page_view'
   GROUP BY device_type
   ORDER BY c DESC"
);
while ($deviceRes && ($r = $deviceRes->fetch_assoc())) $device[] = $r;

$country = [];
$countryRes = $db->query(
  "SELECT COALESCE(NULLIF(country_code,''),'??') country_code, COUNT(*) c
   FROM web_analytics_events
   WHERE {$rangeSql} AND event_name='page_view'
   GROUP BY country_code
   ORDER BY c DESC
   LIMIT 20"
);
while ($countryRes && ($r = $countryRes->fetch_assoc())) $country[] = $r;

$pages = [];
$pagesRes = $db->query(
  "SELECT SUBSTRING_INDEX(path, '?', 1) AS clean_path, COUNT(*) c, COUNT(DISTINCT visitor_id) u
   FROM web_analytics_events
   WHERE {$rangeSql} AND event_name='page_view'
   GROUP BY clean_path
   ORDER BY c DESC
   LIMIT 20"
);
while ($pagesRes && ($r = $pagesRes->fetch_assoc())) {
    $r['label'] = traffic_landing_label((string)$r['clean_path']);
    $pages[] = $r;
}

$adsLandings = [];
$adsLandRes = $db->query(
  "SELECT SUBSTRING_INDEX(path, '?', 1) AS clean_path, COUNT(*) c, COUNT(DISTINCT visitor_id) u
   FROM web_analytics_events
   WHERE {$rangeSql} AND event_name='page_view' AND (gclid <> '' OR source_channel='paid_search')
   GROUP BY clean_path
   ORDER BY c DESC
   LIMIT 15"
);
while ($adsLandRes && ($r = $adsLandRes->fetch_assoc())) {
    $r['label'] = traffic_landing_label((string)$r['clean_path']);
    $adsLandings[] = $r;
}

$campaignBuckets = [];
$campRes = $db->query(
  "SELECT path, utm_campaign, gclid, visitor_id
   FROM web_analytics_events
   WHERE {$rangeSql} AND event_name='page_view' AND (gclid <> '' OR source_channel='paid_search')
   ORDER BY id DESC
   LIMIT 5000"
);
while ($campRes && ($row = $campRes->fetch_assoc())) {
    $label = traffic_campaign_label(
        (string)($row['utm_campaign'] ?? ''),
        (string)($row['path'] ?? ''),
        (string)($row['gclid'] ?? '')
    );
    if ($label === '') {
        $label = 'Google Ads · інше';
    }
    if (!isset($campaignBuckets[$label])) {
        $campaignBuckets[$label] = ['c' => 0, 'visitors' => []];
    }
    $campaignBuckets[$label]['c']++;
    $campaignBuckets[$label]['visitors'][(string)$row['visitor_id']] = true;
}
$adsCampaigns = [];
foreach ($campaignBuckets as $label => $data) {
    $adsCampaigns[] = [
        'label' => $label,
        'c' => $data['c'],
        'u' => count($data['visitors']),
    ];
}
usort($adsCampaigns, static fn($a, $b) => $b['c'] <=> $a['c']);

$regPageSql = "(event_name='register_view' OR (event_name='page_view' AND path LIKE '/auth/register.php%'))";

// Перший UTM/канал відвідувача (first-touch), потім агрегат по тих, хто був на реєстрації.
$regSourceBuckets = [];
$regVisQ = $db->query(
    "SELECT DISTINCT visitor_id
     FROM web_analytics_events
     WHERE {$rangeSql} AND {$regPageSql}"
);
$regVisitorIds = [];
while ($regVisQ && ($row = $regVisQ->fetch_assoc())) {
    $vid = (string)($row['visitor_id'] ?? '');
    if ($vid !== '') {
        $regVisitorIds[$vid] = true;
    }
}
if ($regVisitorIds !== []) {
    $idList = "'" . implode("','", array_map(
        static fn($id) => $db->real_escape_string($id),
        array_keys($regVisitorIds)
    )) . "'";
    $ftQ = $db->query(
        "SELECT visitor_id, utm_source, utm_medium, utm_campaign, source_channel, path, gclid
         FROM web_analytics_events
         WHERE visitor_id IN ({$idList}) AND {$rangeSql}
         ORDER BY id ASC"
    );
    $firstTouch = [];
    while ($ftQ && ($row = $ftQ->fetch_assoc())) {
        $vid = (string)$row['visitor_id'];
        if (isset($firstTouch[$vid])) {
            // якщо перший хіт без UTM — підхопити перший з UTM пізніше в сесії
            if (($firstTouch[$vid]['utm_source'] ?? '') === '' && trim((string)($row['utm_source'] ?? '')) !== '') {
                $firstTouch[$vid] = $row;
            }
            continue;
        }
        $firstTouch[$vid] = $row;
    }
    foreach ($firstTouch as $row) {
        $label = traffic_origin_label(
            (string)($row['utm_source'] ?? ''),
            (string)($row['utm_medium'] ?? ''),
            (string)($row['utm_campaign'] ?? ''),
            (string)($row['source_channel'] ?? ''),
            (string)($row['path'] ?? ''),
            (string)($row['gclid'] ?? '')
        );
        if (!isset($regSourceBuckets[$label])) {
            $regSourceBuckets[$label] = ['c' => 0, 'u' => 0];
        }
        $regSourceBuckets[$label]['c']++;
        $regSourceBuckets[$label]['u']++;
    }
}
$regSource = [];
foreach ($regSourceBuckets as $label => $data) {
    $regSource[] = ['label' => $label, 'c' => $data['c'], 'u' => $data['u']];
}
usort($regSource, static fn($a, $b) => $b['c'] <=> $a['c']);

// Успішні реєстрації з атрибуцією (+ join users).
$regSuccessRows = [];
$regOkQ = $db->query(
    "SELECT e.event_at, e.user_id, e.visitor_id, e.source_channel, e.utm_source, e.utm_medium,
            e.utm_campaign, e.path, e.gclid, u.email, u.nickname
     FROM web_analytics_events e
     LEFT JOIN users u ON u.id = e.user_id
     WHERE {$rangeSql} AND e.event_name='register_success'
     ORDER BY e.id DESC
     LIMIT 50"
);
while ($regOkQ && ($row = $regOkQ->fetch_assoc())) {
    $row['origin'] = traffic_origin_label(
        (string)($row['utm_source'] ?? ''),
        (string)($row['utm_medium'] ?? ''),
        (string)($row['utm_campaign'] ?? ''),
        (string)($row['source_channel'] ?? ''),
        (string)($row['path'] ?? ''),
        (string)($row['gclid'] ?? '')
    );
    // якщо success без UTM — first-touch того ж visitor
    if (($row['utm_source'] ?? '') === '' && ($row['visitor_id'] ?? '') !== '') {
        $vidEsc = $db->real_escape_string((string)$row['visitor_id']);
        $ftOne = $db->query(
            "SELECT utm_source, utm_medium, utm_campaign, source_channel, path, gclid
             FROM web_analytics_events
             WHERE visitor_id='{$vidEsc}' AND (utm_source <> '' OR gclid <> '')
             ORDER BY id ASC LIMIT 1"
        );
        if ($ftOne && ($ft = $ftOne->fetch_assoc())) {
            $row['origin'] = traffic_origin_label(
                (string)($ft['utm_source'] ?? ''),
                (string)($ft['utm_medium'] ?? ''),
                (string)($ft['utm_campaign'] ?? ''),
                (string)($ft['source_channel'] ?? ''),
                (string)($ft['path'] ?? ''),
                (string)($ft['gclid'] ?? '')
            );
        }
    }
    $regSuccessRows[] = $row;
}

// Нові акаунти за період без register_success (історичний пробіл) — показати хоча б факт реєстрації.
$newUsersFallback = [];
$nuQ = $db->query(
    "SELECT u.id, u.email, u.nickname, u.created_at
     FROM users u
     WHERE u.created_at >= (NOW() - INTERVAL {$days} DAY)
     ORDER BY u.id DESC
     LIMIT 30"
);
$successUserIds = [];
foreach ($regSuccessRows as $r) {
    $uidHit = (int)($r['user_id'] ?? 0);
    if ($uidHit > 0) {
        $successUserIds[$uidHit] = true;
    }
}
while ($nuQ && ($row = $nuQ->fetch_assoc())) {
    $uidHit = (int)$row['id'];
    if (isset($successUserIds[$uidHit])) {
        continue;
    }
    // евристика: Instagram/UTM visitor близько до created_at (±6 год) за тим самим днем
    $created = (string)$row['created_at'];
    $createdEsc = $db->real_escape_string($created);
    $hint = null;
    $hq = $db->query(
        "SELECT utm_source, utm_medium, utm_campaign, source_channel, path, gclid, visitor_id
         FROM web_analytics_events
         WHERE event_at BETWEEN ('{$createdEsc}' - INTERVAL 6 HOUR) AND ('{$createdEsc}' + INTERVAL 2 HOUR)
           AND (utm_source <> '' OR gclid <> '' OR source_channel IN ('paid_search','social','organic_search','referral'))
         ORDER BY
           CASE WHEN LOWER(utm_source)='instagram' THEN 0 ELSE 1 END,
           id ASC
         LIMIT 1"
    );
    if ($hq) {
        $hint = $hq->fetch_assoc() ?: null;
    }
    $row['origin'] = $hint
        ? traffic_origin_label(
            (string)($hint['utm_source'] ?? ''),
            (string)($hint['utm_medium'] ?? ''),
            (string)($hint['utm_campaign'] ?? ''),
            (string)($hint['source_channel'] ?? ''),
            (string)($hint['path'] ?? ''),
            (string)($hint['gclid'] ?? '')
        )
        : 'Невідомо (немає події реєстрації)';
    $row['hint_only'] = true;
    $newUsersFallback[] = $row;
}

$rv = (int)($summary['register_views'] ?? 0);
$rs = (int)($summary['register_submits'] ?? 0);
$rOk = (int)($summary['register_success'] ?? 0);
// У воронці success також рахуємо нові акаунти без події (історія).
$rOkDisplay = $rOk + count($newUsersFallback);
$viewToSubmit = $rv > 0 ? round(($rs * 100.0) / $rv, 2) : 0;
$submitToSuccess = $rs > 0 ? round(($rOkDisplay * 100.0) / $rs, 2) : 0;
$ctaClicks = (int)($summary['cta_clicks'] ?? 0);

$lastEvent = null;
$lastSummary = '';
$lastQ = $db->query(
    "SELECT event_at, event_name, source_channel, path, utm_campaign, gclid
     FROM web_analytics_events ORDER BY id DESC LIMIT 1"
);
if ($lastQ) {
    $lastEvent = $lastQ->fetch_assoc() ?: null;
    if ($lastEvent) {
        $lastSummary = traffic_event_summary(
            (string)$lastEvent['event_name'],
            (string)$lastEvent['source_channel'],
            (string)$lastEvent['path'],
            (string)($lastEvent['utm_campaign'] ?? ''),
            (string)($lastEvent['gclid'] ?? '')
        );
    }
}
$lastHour = 0;
$hourQ = $db->query("SELECT COUNT(*) c FROM web_analytics_events WHERE event_at >= NOW() - INTERVAL 1 HOUR");
if ($hourQ) {
    $lastHour = (int)(($hourQ->fetch_assoc() ?: [])['c'] ?? 0);
}

// Instagram / TikTok кампанії
$igBlock = traffic_campaign_block($db, $rangeSql, $igFilterSql, $igFilterSqlE);
$igHitSummary = $igBlock['hit'];
$igFunnel = $igBlock['funnel'];
$igVisitors = $igBlock['visitors'];
$igRecent = $igBlock['recent'];
$igRv = (int)($igFunnel['register_views'] ?? 0);
$igRs = (int)($igFunnel['register_submits'] ?? 0);
$igOk = (int)($igFunnel['register_success'] ?? 0);
$igViewToSubmit = $igRv > 0 ? round(($igRs * 100.0) / $igRv, 2) : 0.0;
$igSubmitToOk = $igRs > 0 ? round(($igOk * 100.0) / $igRs, 2) : 0.0;

$ttBlock = traffic_campaign_block($db, $rangeSql, $ttFilterSql, $ttFilterSqlE);
$ttHitSummary = $ttBlock['hit'];
$ttFunnel = $ttBlock['funnel'];
$ttVisitors = $ttBlock['visitors'];
$ttRecent = $ttBlock['recent'];
$ttRv = (int)($ttFunnel['register_views'] ?? 0);
$ttRs = (int)($ttFunnel['register_submits'] ?? 0);
$ttOk = (int)($ttFunnel['register_success'] ?? 0);
$ttViewToSubmit = $ttRv > 0 ? round(($ttRs * 100.0) / $ttRv, 2) : 0.0;
$ttSubmitToOk = $ttRs > 0 ? round(($ttOk * 100.0) / $ttRs, 2) : 0.0;

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function n($v): string { return number_format((float)$v, 0, '.', ' '); }
?>
<!doctype html>
<html lang="uk">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Адмін · Веб-аналітика</title>
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#0b1020;color:#e5e7eb;margin:0}
    .wrap{max-width:1200px;margin:24px auto;padding:0 16px}
    .row{display:flex;gap:12px;flex-wrap:wrap}
    .card{background:#11182f;border:1px solid rgba(255,255,255,.12);border-radius:12px;padding:14px}
    .kpi{min-width:160px;flex:1}
    .kpi b{display:block;font-size:24px;margin-top:4px}
    table{width:100%;border-collapse:collapse}
    th,td{padding:8px;border-bottom:1px solid rgba(255,255,255,.08);text-align:left;font-size:14px;vertical-align:top}
    .muted{opacity:.75}
    .sub{font-size:12px;opacity:.65;margin-top:2px}
    input,button,select{background:#0f162e;color:#e5e7eb;border:1px solid rgba(255,255,255,.2);border-radius:8px;padding:8px}
    .top{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap}
    a{color:#93c5fd}
  </style>
</head>
<body>
<div class="wrap">
  <div class="top">
    <div>
      <h1 style="margin:0">Веб-аналітика StrIM</h1>
      <p class="muted" style="margin:8px 0 0;max-width:720px;font-size:13px">
        Внутрішній трекер. KPI розділені: <b>публічний сайт</b> (нові / маркетинг) і <b>кабінет</b> (існуючі).
        Реєстрації звіряються з таблицею <code>users</code>. Self-referral з strim.in.ua не рахується як «інший сайт».
      </p>
    </div>
    <form method="get" action="/admin/traffic.php">
      <label for="days" class="muted">Період:</label>
      <select id="days" name="days">
        <option value="7" <?= $days===7?'selected':'' ?>>7 днів</option>
        <option value="14" <?= $days===14?'selected':'' ?>>14 днів</option>
        <option value="30" <?= $days===30?'selected':'' ?>>30 днів</option>
        <option value="60" <?= $days===60?'selected':'' ?>>60 днів</option>
      </select>
      <input type="hidden" name="ig" value="<?= h($igScope) ?>">
      <input type="hidden" name="tt" value="<?= h($ttScope) ?>">
      <button type="submit">Оновити</button>
      <a href="/admin/users.php">Користувачі</a>
      <a href="#instagram">Instagram</a>
      <a href="#tiktok">TikTok</a>
    </form>
  </div>

  <div class="row" id="kpi" style="margin-top:14px">
    <div class="card kpi"><span class="muted">Публічний сайт · відвідувачі</span><b><?= n($acq['unique_visitors'] ?? 0) ?></b><div class="sub">без кабінету / login</div></div>
    <div class="card kpi"><span class="muted">Публічний сайт · сесії</span><b><?= n($acq['sessions'] ?? 0) ?></b></div>
    <div class="card kpi"><span class="muted">Кабінет · відвідувачі</span><b><?= n($appHit['unique_visitors'] ?? 0) ?></b><div class="sub">існуючі юзери</div></div>
    <div class="card kpi"><span class="muted">Кліки CTA → реєстрація</span><b><?= n($ctaClicks) ?></b></div>
    <div class="card kpi"><span class="muted">Нові акаунти (users)</span><b><?= n($usersCreated) ?></b><div class="sub">ground truth</div></div>
  </div>
  <div class="row" style="margin-top:10px">
    <div class="card kpi"><span class="muted">Усі · відвідувачі</span><b><?= n($summary['unique_visitors'] ?? 0) ?></b></div>
    <div class="card kpi"><span class="muted">Усі · перегляди</span><b><?= n($summary['page_views'] ?? 0) ?></b></div>
    <div class="card kpi"><span class="muted">Кліки з Google Ads</span><b><?= n($summary['ads_clicks'] ?? 0) ?></b></div>
    <div class="card kpi"><span class="muted">За останню годину</span><b><?= n($lastHour) ?></b></div>
  </div>
  <?php if ($lastEvent): ?>
  <p class="muted" style="margin:10px 0 0;font-size:13px">
    Остання подія (<?= h((string)$lastEvent['event_at']) ?>): <b><?= h($lastSummary) ?></b>
  </p>
  <?php endif; ?>

  <div class="row" style="margin-top:14px">
    <div class="card" style="flex:1;min-width:360px">
      <h3 style="margin-top:0">Google Ads — кампанії</h3>
      <table>
        <tr><th>Кампанія</th><th>Переходи</th><th>Користувачі</th></tr>
        <?php if ($adsCampaigns === []): ?>
        <tr><td colspan="3" class="muted">Поки немає переходів з реклами (gclid)</td></tr>
        <?php else: foreach ($adsCampaigns as $r): ?>
        <tr>
          <td><?= h((string)$r['label']) ?></td>
          <td><?= n($r['c']) ?></td>
          <td><?= n($r['u']) ?></td>
        </tr>
        <?php endforeach; endif; ?>
      </table>
    </div>
    <div class="card" style="flex:1;min-width:360px">
      <h3 style="margin-top:0">Google Ads — landing-сторінки</h3>
      <table>
        <tr><th>Сторінка</th><th>Переходи</th><th>Користувачі</th></tr>
        <?php if ($adsLandings === []): ?>
        <tr><td colspan="3" class="muted">—</td></tr>
        <?php else: foreach ($adsLandings as $r): ?>
        <tr>
          <td><?= h((string)$r['label']) ?><div class="sub"><?= h((string)$r['clean_path']) ?></div></td>
          <td><?= n($r['c']) ?></td>
          <td><?= n($r['u']) ?></td>
        </tr>
        <?php endforeach; endif; ?>
      </table>
    </div>
  </div>

  <div class="card" id="instagram" style="margin-top:14px">
    <div class="top" style="align-items:center">
      <div>
        <h3 style="margin:0">Instagram — відвідувачі з біо</h3>
        <p class="muted" style="margin:6px 0 0;font-size:13px">
          Фільтр: <?= h($igFilterLabel) ?>.
          Коротке посилання: <code>https://strim.in.ua/ig</code>
          (редирект на UTM launch).
        </p>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <a href="?days=<?= (int)$days ?>&amp;ig=launch&amp;tt=<?= h($ttScope) ?>#instagram"<?= $igScope==='launch'?' style="font-weight:700"':'' ?>>Bio launch</a>
        <a href="?days=<?= (int)$days ?>&amp;ig=any&amp;tt=<?= h($ttScope) ?>#instagram"<?= $igScope==='any'?' style="font-weight:700"':'' ?>>Усі Instagram</a>
      </div>
    </div>

    <div class="row" style="margin-top:12px">
      <div class="card kpi" style="background:#0f162e"><span class="muted">Унікальні з Instagram</span><b><?= n($igHitSummary['unique_visitors'] ?? 0) ?></b></div>
      <div class="card kpi" style="background:#0f162e"><span class="muted">Сесії</span><b><?= n($igHitSummary['sessions'] ?? 0) ?></b></div>
      <div class="card kpi" style="background:#0f162e"><span class="muted">Перегляди (з UTM)</span><b><?= n($igHitSummary['page_views'] ?? 0) ?></b></div>
      <div class="card kpi" style="background:#0f162e"><span class="muted">CTA кліки</span><b><?= n($igFunnel['cta_clicks'] ?? 0) ?></b></div>
      <div class="card kpi" style="background:#0f162e"><span class="muted">Реєстрації (успіх)</span><b><?= n($igOk) ?></b></div>
    </div>

    <div class="row" style="margin-top:12px">
      <div class="card" style="flex:1;min-width:280px;background:#0f162e">
        <h4 style="margin-top:0">Воронка цих відвідувачів</h4>
        <p class="muted" style="font-size:12px;margin-top:0">Усі їхні події за період (не лише лендінг з UTM).</p>
        <div>Перегляд / старт реєстрації: <b><?= n($igRv) ?></b></div>
        <div>Надсилання форми: <b><?= n($igRs) ?></b> (<?= h((string)$igViewToSubmit) ?>%)</div>
        <div>Успішна реєстрація: <b><?= n($igOk) ?></b> (<?= h((string)$igSubmitToOk) ?>%)</div>
      </div>
      <div class="card" style="flex:2;min-width:360px;background:#0f162e">
        <h4 style="margin-top:0">Останні події з UTM Instagram</h4>
        <table>
          <tr><th>Час</th><th>Подія</th><th>Шлях</th><th>Visitor</th></tr>
          <?php if ($igRecent === []): ?>
          <tr><td colspan="4" class="muted">Поки немає заходів з цього посилання за обраний період.</td></tr>
          <?php else: foreach ($igRecent as $r): ?>
          <tr>
            <td><?= h((string)$r['event_at']) ?></td>
            <td><?= h(traffic_event_summary((string)$r['event_name'], (string)$r['source_channel'], (string)$r['path'], (string)($r['utm_campaign'] ?? ''), '')) ?></td>
            <td><div class="sub" style="max-width:280px;word-break:break-all"><?= h((string)$r['path']) ?></div></td>
            <td><code style="font-size:11px"><?= h(substr((string)$r['visitor_id'], 0, 12)) ?>…</code></td>
          </tr>
          <?php endforeach; endif; ?>
        </table>
      </div>
    </div>

    <h4 style="margin:16px 0 8px">Список відвідувачів (до 150)</h4>
    <table>
      <tr>
        <th>Перший / останній</th>
        <th>Visitor</th>
        <th>Пристрій</th>
        <th>Події</th>
        <th>Реєстрація</th>
        <th>User ID</th>
      </tr>
      <?php if ($igVisitors === []): ?>
      <tr><td colspan="6" class="muted">Немає відвідувачів за цим фільтром.</td></tr>
      <?php else: foreach ($igVisitors as $r):
        $uidHit = (int)($r['user_id'] ?? 0);
        $regOk = (int)($r['reg_ok'] ?? 0) > 0;
        $regLabel = $regOk
            ? 'успіх'
            : (((int)($r['reg_submits'] ?? 0) > 0)
                ? 'submit'
                : (((int)($r['reg_views'] ?? 0) > 0) ? 'перегляд' : '—'));
      ?>
      <tr>
        <td>
          <?= h((string)$r['first_at']) ?>
          <div class="sub"><?= h((string)$r['last_at']) ?></div>
        </td>
        <td>
          <code style="font-size:11px"><?= h((string)$r['visitor_id']) ?></code>
          <?php if (!empty($r['camp_path'])): ?>
          <div class="sub" style="max-width:320px;word-break:break-all"><?= h((string)$r['camp_path']) ?></div>
          <?php endif; ?>
        </td>
        <td>
          <?= h((string)($r['device_type'] ?? '')) ?>
          <?php if (!empty($r['country_code'])): ?>
          <div class="sub"><?= h((string)$r['country_code']) ?></div>
          <?php endif; ?>
        </td>
        <td><?= n($r['events']) ?> <span class="sub">(pv <?= n($r['page_views']) ?>)</span></td>
        <td><?= h($regLabel) ?></td>
        <td>
          <?php if ($uidHit > 0): ?>
            <a href="/admin/users.php">#<?= (int)$uidHit ?></a>
          <?php else: ?>
            <span class="muted">—</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; endif; ?>
    </table>
  </div>

  <div class="card" id="tiktok" style="margin-top:14px">
    <div class="top" style="align-items:center">
      <div>
        <h3 style="margin:0">TikTok — відвідувачі з біо</h3>
        <p class="muted" style="margin:6px 0 0;font-size:13px">
          Фільтр: <?= h($ttFilterLabel) ?>.
          Коротке посилання для профілю: <code>https://strim.in.ua/tt</code>
          (редирект на UTM launch — у TikTok виглядає коротко).
        </p>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <a href="?days=<?= (int)$days ?>&amp;tt=launch&amp;ig=<?= h($igScope) ?>#tiktok"<?= $ttScope==='launch'?' style="font-weight:700"':'' ?>>Bio launch</a>
        <a href="?days=<?= (int)$days ?>&amp;tt=any&amp;ig=<?= h($igScope) ?>#tiktok"<?= $ttScope==='any'?' style="font-weight:700"':'' ?>>Усі TikTok</a>
      </div>
    </div>

    <div class="row" style="margin-top:12px">
      <div class="card kpi" style="background:#0f162e"><span class="muted">Унікальні з TikTok</span><b><?= n($ttHitSummary['unique_visitors'] ?? 0) ?></b></div>
      <div class="card kpi" style="background:#0f162e"><span class="muted">Сесії</span><b><?= n($ttHitSummary['sessions'] ?? 0) ?></b></div>
      <div class="card kpi" style="background:#0f162e"><span class="muted">Перегляди (з UTM)</span><b><?= n($ttHitSummary['page_views'] ?? 0) ?></b></div>
      <div class="card kpi" style="background:#0f162e"><span class="muted">CTA кліки</span><b><?= n($ttFunnel['cta_clicks'] ?? 0) ?></b></div>
      <div class="card kpi" style="background:#0f162e"><span class="muted">Реєстрації (успіх)</span><b><?= n($ttOk) ?></b></div>
    </div>

    <div class="row" style="margin-top:12px">
      <div class="card" style="flex:1;min-width:280px;background:#0f162e">
        <h4 style="margin-top:0">Воронка цих відвідувачів</h4>
        <p class="muted" style="font-size:12px;margin-top:0">Усі їхні події за період (не лише лендінг з UTM).</p>
        <div>Перегляд / старт реєстрації: <b><?= n($ttRv) ?></b></div>
        <div>Надсилання форми: <b><?= n($ttRs) ?></b> (<?= h((string)$ttViewToSubmit) ?>%)</div>
        <div>Успішна реєстрація: <b><?= n($ttOk) ?></b> (<?= h((string)$ttSubmitToOk) ?>%)</div>
      </div>
      <div class="card" style="flex:2;min-width:360px;background:#0f162e">
        <h4 style="margin-top:0">Останні події з UTM TikTok</h4>
        <table>
          <tr><th>Час</th><th>Подія</th><th>Шлях</th><th>Visitor</th></tr>
          <?php if ($ttRecent === []): ?>
          <tr><td colspan="4" class="muted">Поки немає заходів з цього посилання за обраний період.</td></tr>
          <?php else: foreach ($ttRecent as $r): ?>
          <tr>
            <td><?= h((string)$r['event_at']) ?></td>
            <td><?= h(traffic_event_summary((string)$r['event_name'], (string)$r['source_channel'], (string)$r['path'], (string)($r['utm_campaign'] ?? ''), '')) ?></td>
            <td><div class="sub" style="max-width:280px;word-break:break-all"><?= h((string)$r['path']) ?></div></td>
            <td><code style="font-size:11px"><?= h(substr((string)$r['visitor_id'], 0, 12)) ?>…</code></td>
          </tr>
          <?php endforeach; endif; ?>
        </table>
      </div>
    </div>

    <h4 style="margin:16px 0 8px">Список відвідувачів (до 150)</h4>
    <table>
      <tr>
        <th>Перший / останній</th>
        <th>Visitor</th>
        <th>Пристрій</th>
        <th>Події</th>
        <th>Реєстрація</th>
        <th>User ID</th>
      </tr>
      <?php if ($ttVisitors === []): ?>
      <tr><td colspan="6" class="muted">Немає відвідувачів за цим фільтром.</td></tr>
      <?php else: foreach ($ttVisitors as $r):
        $uidHit = (int)($r['user_id'] ?? 0);
        $regOk = (int)($r['reg_ok'] ?? 0) > 0;
        $regLabel = $regOk
            ? 'успіх'
            : (((int)($r['reg_submits'] ?? 0) > 0)
                ? 'submit'
                : (((int)($r['reg_views'] ?? 0) > 0) ? 'перегляд' : '—'));
      ?>
      <tr>
        <td>
          <?= h((string)$r['first_at']) ?>
          <div class="sub"><?= h((string)$r['last_at']) ?></div>
        </td>
        <td>
          <code style="font-size:11px"><?= h((string)$r['visitor_id']) ?></code>
          <?php if (!empty($r['camp_path'])): ?>
          <div class="sub" style="max-width:320px;word-break:break-all"><?= h((string)$r['camp_path']) ?></div>
          <?php endif; ?>
        </td>
        <td>
          <?= h((string)($r['device_type'] ?? '')) ?>
          <?php if (!empty($r['country_code'])): ?>
          <div class="sub"><?= h((string)$r['country_code']) ?></div>
          <?php endif; ?>
        </td>
        <td><?= n($r['events']) ?> <span class="sub">(pv <?= n($r['page_views']) ?>)</span></td>
        <td><?= h($regLabel) ?></td>
        <td>
          <?php if ($uidHit > 0): ?>
            <a href="/admin/users.php">#<?= (int)$uidHit ?></a>
          <?php else: ?>
            <span class="muted">—</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; endif; ?>
    </table>
  </div>

  <div class="row" style="margin-top:14px">
    <div class="card" style="flex:1;min-width:360px">
      <h3 style="margin-top:0">Воронка реєстрації</h3>
      <div>CTA «Створити акаунт»: <b><?= n($ctaClicks) ?></b></div>
      <div>Перегляд реєстрації: <b><?= n($rv) ?></b> <span class="muted" style="font-size:12px">(page_view + register_view)</span></div>
      <div>Надсилання форми: <b><?= n($rs) ?></b> (<?= h((string)$viewToSubmit) ?>% від перегляду)</div>
      <div>Успіх (подія): <b><?= n($rOk) ?></b> · акаунти в DB: <b><?= n($usersCreated) ?></b></div>
      <div class="sub" style="margin-top:6px">Submit → success: <?= h((string)$submitToSuccess) ?>% · відображено в списку нижче: <?= n($rOkDisplay) ?></div>
    </div>
    <div class="card" style="flex:1;min-width:360px">
      <h3 style="margin-top:0">Джерела трафіку</h3>
      <table>
        <tr><th>Канал</th><th>Перегляди</th><th>Користувачі</th></tr>
        <?php foreach ($source as $r): ?>
        <tr>
          <td><?= h((string)$r['label']) ?><div class="sub"><?= h((string)$r['source_channel']) ?></div></td>
          <td><?= n($r['c']) ?></td>
          <td><?= n($r['u']) ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
    </div>
  </div>

  <div class="row" style="margin-top:14px">
    <div class="card" style="flex:1;min-width:360px">
      <h3 style="margin-top:0">Пристрої</h3>
      <table>
        <tr><th>Пристрій</th><th>Перегляди</th><th>Користувачі</th></tr>
        <?php foreach ($device as $r): ?><tr><td><?= h((string)$r['device_type']) ?></td><td><?= n($r['c']) ?></td><td><?= n($r['u']) ?></td></tr><?php endforeach; ?>
      </table>
    </div>
    <div class="card" style="flex:1;min-width:360px">
      <h3 style="margin-top:0">Країни</h3>
      <table>
        <tr><th>Країна</th><th>Перегляди</th></tr>
        <?php foreach ($country as $r): ?><tr><td><?= h((string)$r['country_code']) ?></td><td><?= n($r['c']) ?></td></tr><?php endforeach; ?>
      </table>
    </div>
  </div>

  <div class="card" style="margin-top:14px">
    <h3 style="margin-top:0">Топ сторінки (без технічних параметрів)</h3>
    <table>
      <tr><th>Сторінка</th><th>Перегляди</th><th>Користувачі</th></tr>
      <?php foreach ($pages as $r): ?>
      <tr>
        <td><?= h((string)$r['label']) ?><div class="sub"><?= h((string)$r['clean_path']) ?></div></td>
        <td><?= n($r['c']) ?></td>
        <td><?= n($r['u']) ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>

  <div class="card" style="margin-top:14px">
    <h3 style="margin-top:0">Сторінка реєстрації — звідки прийшли</h3>
    <p class="muted" style="font-size:13px;margin-top:0">First-touch джерело відвідувачів, які відкрили `/auth/register.php` (не лише технічна подія register_view).</p>
    <table>
      <tr><th>Джерело</th><th>Відвідувачі</th></tr>
      <?php if ($regSource === []): ?>
      <tr><td colspan="2" class="muted">Немає переглядів реєстрації за період.</td></tr>
      <?php else: foreach ($regSource as $r): ?>
      <tr>
        <td><?= h((string)$r['label']) ?></td>
        <td><?= n($r['u']) ?></td>
      </tr>
      <?php endforeach; endif; ?>
    </table>
  </div>

  <div class="card" style="margin-top:14px">
    <h3 style="margin-top:0">Хто зареєструвався — звідки</h3>
    <p class="muted" style="font-size:13px;margin-top:0">
      Події <code>register_success</code> пишуться на сервері при створенні акаунта.
      Для старіших акаунтів без події — евристика за часом і UTM.
    </p>
    <table>
      <tr><th>Коли</th><th>Користувач</th><th>Джерело</th></tr>
      <?php
      $regPeople = $regSuccessRows;
      foreach ($newUsersFallback as $nu) {
          $regPeople[] = [
              'event_at' => $nu['created_at'],
              'user_id' => $nu['id'],
              'email' => $nu['email'],
              'nickname' => $nu['nickname'],
              'origin' => $nu['origin'],
              'hint_only' => true,
          ];
      }
      usort($regPeople, static fn($a, $b) => strcmp((string)($b['event_at'] ?? ''), (string)($a['event_at'] ?? '')));
      if ($regPeople === []):
      ?>
      <tr><td colspan="3" class="muted">За період немає нових реєстрацій.</td></tr>
      <?php else: foreach ($regPeople as $r):
        $uidHit = (int)($r['user_id'] ?? 0);
        $who = trim((string)($r['nickname'] ?? ''));
        $email = trim((string)($r['email'] ?? ''));
        if ($who === '' && $email !== '') {
            $who = $email;
        } elseif ($who !== '' && $email !== '') {
            $who .= ' · ' . $email;
        }
        if ($who === '') {
            $who = $uidHit > 0 ? ('#' . $uidHit) : '—';
        }
      ?>
      <tr>
        <td><?= h((string)($r['event_at'] ?? '')) ?><?php if (!empty($r['hint_only'])): ?><div class="sub">евристика</div><?php endif; ?></td>
        <td><?php if ($uidHit > 0): ?><a href="/admin/users.php">#<?= $uidHit ?></a> <?php endif; ?><?= h($who) ?></td>
        <td><?= h((string)($r['origin'] ?? '')) ?></td>
      </tr>
      <?php endforeach; endif; ?>
    </table>
  </div>
</div>
</body>
</html>
