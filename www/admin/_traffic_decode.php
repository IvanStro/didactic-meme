<?php
declare(strict_types=1);

function traffic_channel_label(string $code): string
{
    return match ($code) {
        'paid_search' => 'Google Ads (реклама)',
        'organic_search' => 'Пошук (органіка)',
        'direct' => 'Прямий захід',
        'referral' => 'Перехід із іншого сайту',
        'social' => 'Соцмережі',
        'utm' => 'UTM-мітки',
        default => $code !== '' ? $code : 'Невідомо',
    };
}

/** Кабінет / адмінка / API — не маркетинг. */
function traffic_app_path_sql(string $alias = ''): string
{
    $p = $alias !== '' ? rtrim($alias, '.') . '.' : '';
    return '('
        . "{$p}path LIKE '/dashboard%'"
        . " OR {$p}path LIKE '/admin%'"
        . " OR {$p}path LIKE '/api/%'"
        . " OR {$p}path LIKE '/auth/login%'"
        . ')';
}

function traffic_public_path_sql(string $alias = ''): string
{
    return 'NOT ' . traffic_app_path_sql($alias);
}

/** Канал з урахуванням self-referral (історичні рядки). */
function traffic_channel_effective_sql(string $alias = ''): string
{
    $p = $alias !== '' ? rtrim($alias, '.') . '.' : '';
    return "CASE"
        . " WHEN {$p}source_channel='referral'"
        . "  AND ({$p}referrer_host='' OR {$p}referrer_host LIKE '%strim.in.ua%' OR {$p}referrer_host LIKE '%luckydrop.uno%')"
        . " THEN 'direct'"
        . " ELSE {$p}source_channel END";
}

/**
 * Instagram bio launch:
 * https://strim.in.ua/?utm_source=instagram&utm_medium=bio&utm_campaign=launch
 */
function traffic_instagram_launch_sql(string $alias = ''): string
{
    $p = $alias !== '' ? rtrim($alias, '.') . '.' : '';
    return '('
        . "(LOWER(COALESCE({$p}utm_source,''))='instagram'"
        . " AND LOWER(COALESCE({$p}utm_medium,''))='bio'"
        . " AND LOWER(COALESCE({$p}utm_campaign,''))='launch')"
        . " OR ({$p}path LIKE '%utm_source=instagram%'"
        . " AND {$p}path LIKE '%utm_medium=bio%'"
        . " AND {$p}path LIKE '%utm_campaign=launch%')"
        . " OR {$p}path LIKE '/ig%'"
        . ')';
}

/** Будь-який Instagram UTM (source=instagram). */
function traffic_instagram_any_sql(string $alias = ''): string
{
    $p = $alias !== '' ? rtrim($alias, '.') . '.' : '';
    return '('
        . "LOWER(COALESCE({$p}utm_source,''))='instagram'"
        . " OR {$p}path LIKE '%utm_source=instagram%'"
        . " OR {$p}path LIKE '/ig%'"
        . ')';
}

/**
 * TikTok bio launch:
 * короткий: https://strim.in.ua/tt
 * повний:  https://strim.in.ua/?utm_source=tiktok&utm_medium=bio&utm_campaign=launch
 */
function traffic_tiktok_launch_sql(string $alias = ''): string
{
    $p = $alias !== '' ? rtrim($alias, '.') . '.' : '';
    return '('
        . "(LOWER(COALESCE({$p}utm_source,''))='tiktok'"
        . " AND LOWER(COALESCE({$p}utm_medium,''))='bio'"
        . " AND LOWER(COALESCE({$p}utm_campaign,''))='launch')"
        . " OR ({$p}path LIKE '%utm_source=tiktok%'"
        . " AND {$p}path LIKE '%utm_medium=bio%'"
        . " AND {$p}path LIKE '%utm_campaign=launch%')"
        . " OR {$p}path LIKE '/tt%'"
        . ')';
}

/** Будь-який TikTok UTM (source=tiktok) або короткий /tt. */
function traffic_tiktok_any_sql(string $alias = ''): string
{
    $p = $alias !== '' ? rtrim($alias, '.') . '.' : '';
    return '('
        . "LOWER(COALESCE({$p}utm_source,''))='tiktok'"
        . " OR {$p}path LIKE '%utm_source=tiktok%'"
        . " OR {$p}path LIKE '/tt%'"
        . ')';
}

function traffic_path_clean(string $path): string
{
    $path = trim($path);
    if ($path === '') {
        return '/';
    }
    $qPos = strpos($path, '?');
    if ($qPos === false) {
        return $path;
    }
    return substr($path, 0, $qPos) ?: '/';
}

/** @return array<string, string> */
function traffic_parse_query(string $path): array
{
    $qPos = strpos($path, '?');
    if ($qPos === false) {
        return [];
    }
    $out = [];
    parse_str(substr($path, $qPos + 1), $out);
    return is_array($out) ? $out : [];
}

function traffic_campaign_label(string $utmCampaign, string $path, string $gclid): string
{
    if ($utmCampaign !== '') {
        if (str_starts_with($utmCampaign, 'gads:')) {
            return 'Google Ads · кампанія #' . substr($utmCampaign, 5);
        }
        return $utmCampaign;
    }
    $q = traffic_parse_query($path);
    $gadId = trim((string)($q['gad_campaignid'] ?? ''));
    if ($gadId !== '' && ctype_digit($gadId)) {
        return 'Google Ads · кампанія #' . $gadId;
    }
    if ($gclid !== '' || ($q['gclid'] ?? '') !== '') {
        return 'Google Ads · клік (gclid)';
    }
    return '';
}

function traffic_landing_label(string $path): string
{
    $clean = traffic_path_clean($path);
    $labels = [
        '/' => 'Головна',
        '/index.html' => 'Головна',
        '/tariffs.html' => 'Тарифи',
        '/auth/register.php' => 'Реєстрація',
        '/auth/login.php' => 'Вхід',
        '/about.html' => 'Про платформу',
        '/instruction.html' => 'Інструкція',
        '/faq.html' => 'FAQ',
    ];
    return ($labels[$clean] ?? $clean);
}

function traffic_event_summary(
    string $eventName,
    string $sourceChannel,
    string $path,
    string $utmCampaign,
    string $gclid
): string {
    $parts = [];
    $eventLabels = [
        'page_view' => 'Перегляд сторінки',
        'register_view' => 'Перегляд реєстрації',
        'register_start' => 'Старт реєстрації',
        'register_submit' => 'Надсилання реєстрації',
        'register_success' => 'Успішна реєстрація',
        'cta_click' => 'Клік CTA',
    ];
    $parts[] = $eventLabels[$eventName] ?? $eventName;
    $parts[] = traffic_channel_label($sourceChannel);
    $parts[] = traffic_landing_label($path);
    $camp = traffic_campaign_label($utmCampaign, $path, $gclid);
    if ($camp !== '') {
        $parts[] = $camp;
    }
    return implode(' · ', $parts);
}

/** Людський ярлик джерела: Instagram bio launch / Google Ads / канал. */
function traffic_origin_label(
    string $utmSource,
    string $utmMedium,
    string $utmCampaign,
    string $sourceChannel,
    string $path = '',
    string $gclid = ''
): string {
    $src = strtolower(trim($utmSource));
    $med = strtolower(trim($utmMedium));
    $camp = trim($utmCampaign);
    if ($src === 'instagram' && $med === 'bio' && strtolower($camp) === 'launch') {
        return 'Instagram · bio launch';
    }
    if ($src === 'instagram') {
        $bits = ['Instagram'];
        if ($med !== '') {
            $bits[] = $med;
        }
        if ($camp !== '') {
            $bits[] = $camp;
        }
        return implode(' · ', $bits);
    }
    if ($src === 'tiktok' && $med === 'bio' && strtolower($camp) === 'launch') {
        return 'TikTok · bio launch';
    }
    if ($src === 'tiktok' || preg_match('#^/tt(/|\\?|$)#', $path)) {
        $bits = ['TikTok'];
        if ($med !== '') {
            $bits[] = $med;
        }
        if ($camp !== '') {
            $bits[] = $camp;
        }
        return $bits === ['TikTok'] ? 'TikTok · bio' : implode(' · ', $bits);
    }
    if ($gclid !== '' || $sourceChannel === 'paid_search') {
        $ads = traffic_campaign_label($camp, $path, $gclid);
        return $ads !== '' ? $ads : 'Google Ads';
    }
    if ($src !== '') {
        $bits = [$src];
        if ($med !== '') {
            $bits[] = $med;
        }
        if ($camp !== '') {
            $bits[] = $camp;
        }
        return implode(' · ', $bits);
    }
    return traffic_channel_label($sourceChannel);
}

function traffic_gclid_short(string $gclid): string
{
    $gclid = trim($gclid);
    if ($gclid === '') {
        return '—';
    }
    if (strlen($gclid) <= 12) {
        return $gclid;
    }
    return substr($gclid, 0, 8) . '…';
}

/**
 * KPI + відвідувачі + останні події для UTM-кампанії (Instagram / TikTok).
 *
 * @return array{
 *   hit: array{page_views:int|string,unique_visitors:int|string,sessions:int|string},
 *   funnel: array{register_views:int|string,register_submits:int|string,register_success:int|string,cta_clicks:int|string},
 *   visitors: list<array<string,mixed>>,
 *   recent: list<array<string,mixed>>
 * }
 */
function traffic_campaign_block(mysqli $db, string $rangeSql, string $filterSql, string $filterSqlE): array
{
    $hit = [
        'page_views' => 0,
        'unique_visitors' => 0,
        'sessions' => 0,
    ];
    $hitQ = $db->query(
        "SELECT
            SUM(CASE WHEN event_name='page_view' THEN 1 ELSE 0 END) AS page_views,
            COUNT(DISTINCT visitor_id) AS unique_visitors,
            COUNT(DISTINCT session_id) AS sessions
         FROM web_analytics_events
         WHERE {$rangeSql} AND {$filterSql}"
    );
    if ($hitQ) {
        $hit = $hitQ->fetch_assoc() ?: $hit;
    }

    $funnel = [
        'register_views' => 0,
        'register_submits' => 0,
        'register_success' => 0,
        'cta_clicks' => 0,
    ];
    $funnelQ = $db->query(
        "SELECT
            SUM(CASE WHEN e.event_name IN ('register_view','register_start')
                       OR (e.event_name='page_view' AND e.path LIKE '/auth/register.php%')
                 THEN 1 ELSE 0 END) AS register_views,
            SUM(CASE WHEN e.event_name='register_submit' THEN 1 ELSE 0 END) AS register_submits,
            SUM(CASE WHEN e.event_name='register_success' THEN 1 ELSE 0 END) AS register_success,
            SUM(CASE WHEN e.event_name='cta_click' THEN 1 ELSE 0 END) AS cta_clicks
         FROM web_analytics_events e
         INNER JOIN (
           SELECT DISTINCT visitor_id
           FROM web_analytics_events
           WHERE {$rangeSql} AND {$filterSql}
         ) camp ON camp.visitor_id = e.visitor_id
         WHERE {$rangeSql}"
    );
    if ($funnelQ) {
        $funnel = $funnelQ->fetch_assoc() ?: $funnel;
    }

    $visitors = [];
    $visQ = $db->query(
        "SELECT
            e.visitor_id,
            MIN(e.event_at) AS first_at,
            MAX(e.event_at) AS last_at,
            COUNT(*) AS events,
            SUM(CASE WHEN e.event_name='page_view' THEN 1 ELSE 0 END) AS page_views,
            SUM(CASE WHEN e.event_name IN ('register_view','register_start') THEN 1 ELSE 0 END) AS reg_views,
            SUM(CASE WHEN e.event_name='register_submit' THEN 1 ELSE 0 END) AS reg_submits,
            SUM(CASE WHEN e.event_name='register_success' THEN 1 ELSE 0 END) AS reg_ok,
            MAX(NULLIF(e.device_type,'')) AS device_type,
            MAX(NULLIF(e.country_code,'')) AS country_code,
            MAX(CASE WHEN e.user_id IS NOT NULL AND e.user_id > 0 THEN e.user_id ELSE 0 END) AS user_id,
            MAX(CASE WHEN {$filterSqlE} THEN e.path ELSE NULL END) AS camp_path
         FROM web_analytics_events e
         INNER JOIN (
           SELECT DISTINCT visitor_id
           FROM web_analytics_events
           WHERE {$rangeSql} AND {$filterSql}
         ) camp ON camp.visitor_id = e.visitor_id
         WHERE {$rangeSql}
         GROUP BY e.visitor_id
         ORDER BY last_at DESC
         LIMIT 150"
    );
    while ($visQ && ($r = $visQ->fetch_assoc())) {
        $visitors[] = $r;
    }

    $recent = [];
    $recentQ = $db->query(
        "SELECT event_at, event_name, path, visitor_id, device_type, source_channel,
                utm_source, utm_medium, utm_campaign
         FROM web_analytics_events
         WHERE {$rangeSql} AND {$filterSql}
         ORDER BY id DESC
         LIMIT 40"
    );
    while ($recentQ && ($r = $recentQ->fetch_assoc())) {
        $recent[] = $r;
    }

    return [
        'hit' => $hit,
        'funnel' => $funnel,
        'visitors' => $visitors,
        'recent' => $recent,
    ];
}
