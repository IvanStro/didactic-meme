<?php
declare(strict_types=1);

/**
 * Запис події веб-аналітики з PHP (реєстрація тощо).
 * Attribution береться з cookies, які виставляє публічний JS.
 */

function web_analytics_read_cookie(string $name): string
{
    $v = (string)($_COOKIE[$name] ?? '');
    return trim($v);
}

function web_analytics_is_own_host(string $host): bool
{
    $host = strtolower(trim($host));
    if ($host === '') {
        return false;
    }
    static $own = [
        'strim.in.ua',
        'www.strim.in.ua',
        'luckydrop.uno',
        'www.luckydrop.uno',
        'bot.luckydrop.uno',
    ];
    if (in_array($host, $own, true)) {
        return true;
    }
    $ch = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($ch === '') {
        return false;
    }
    return $host === $ch
        || $host === 'www.' . $ch
        || $ch === 'www.' . $host;
}

/** First-party StrIM analytics (не Google): all або essential. */
function web_analytics_first_party_ok(): bool
{
    $c = (string)($_COOKIE['strim_consent_v2'] ?? '');
    return $c === 'all' || $c === 'essential';
}

/** @return array{utm_source:string,utm_medium:string,utm_campaign:string,utm_term:string,utm_content:string,gclid:string} */
function web_analytics_read_attr(): array
{
    $out = [
        'utm_source' => '',
        'utm_medium' => '',
        'utm_campaign' => '',
        'utm_term' => '',
        'utm_content' => '',
        'gclid' => '',
    ];
    $raw = web_analytics_read_cookie('strim_attr');
    if ($raw === '') {
        return $out;
    }
    $decoded = rawurldecode($raw);
    $j = json_decode($decoded, true);
    if (!is_array($j)) {
        $j = json_decode($raw, true);
    }
    if (!is_array($j)) {
        return $out;
    }
    foreach ($out as $k => $_) {
        if (isset($j[$k]) && is_scalar($j[$k])) {
            $out[$k] = substr(trim((string)$j[$k]), 0, 120);
        }
    }
    return $out;
}

function web_analytics_classify_channel(array $att, string $refHost = ''): string
{
    $gclid = trim((string)($att['gclid'] ?? ''));
    $medium = strtolower(trim((string)($att['utm_medium'] ?? '')));
    $source = strtolower(trim((string)($att['utm_source'] ?? '')));
    if ($gclid !== '' || in_array($medium, ['cpc', 'ppc', 'paid'], true)) {
        return 'paid_search';
    }
    if (in_array($source, ['instagram', 'facebook', 'fb', 'tiktok', 'telegram', 'ig'], true)) {
        return 'social';
    }
    if (web_analytics_is_own_host($refHost)) {
        $refHost = '';
    }
    if ($refHost !== '') {
        if (preg_match('/google\.|bing\./i', $refHost)) {
            return 'organic_search';
        }
        if (preg_match('/instagram\.|facebook\.|fb\.|t\.co|twitter\.|tiktok\./i', $refHost)) {
            return 'social';
        }
        return 'referral';
    }
    return 'direct';
}

/**
 * @param array<string,mixed>|null $props
 * @param bool $requireConsent false = конверсії (register_submit/success) завжди
 */
function web_analytics_insert_event(
    mysqli $db,
    string $eventName,
    int $userId = 0,
    string $path = '',
    ?array $props = null,
    bool $requireConsent = true
): bool {
    if ($requireConsent && !web_analytics_first_party_ok()) {
        return false;
    }
    $visitorId = substr(web_analytics_read_cookie('strim_vid'), 0, 64);
    $sessionId = substr(web_analytics_read_cookie('strim_sid'), 0, 64);
    if ($visitorId === '') {
        $visitorId = 'srv_' . bin2hex(random_bytes(8));
    }
    if ($sessionId === '') {
        $sessionId = 'srv_' . bin2hex(random_bytes(8));
    }

    $att = web_analytics_read_attr();
    $refHost = '';
    if (!empty($_SERVER['HTTP_REFERER'])) {
        $h = parse_url((string)$_SERVER['HTTP_REFERER'], PHP_URL_HOST);
        $refHost = is_string($h) ? substr($h, 0, 191) : '';
    }
    if (web_analytics_is_own_host($refHost)) {
        $refHost = '';
    }
    $channel = web_analytics_classify_channel($att, $refHost);
    if ($path === '') {
        $path = substr((string)($_SERVER['REQUEST_URI'] ?? '/auth/do_register.php'), 0, 255);
    }
    $device = 'desktop';
    $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    $ual = strtolower($ua);
    if (preg_match('/ipad|tablet/', $ual)) {
        $device = 'tablet';
    } elseif (preg_match('/mobi|iphone|android/', $ual)) {
        $device = 'mobile';
    }
    $country = '';
    if (!empty($_SERVER['HTTP_CF_IPCOUNTRY'])) {
        $country = strtoupper(substr((string)$_SERVER['HTTP_CF_IPCOUNTRY'], 0, 2));
    }
    $ipHash = hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? ''));
    $propsJson = $props !== null
        ? (string)json_encode($props, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        : '';
    $utmSource = substr($att['utm_source'], 0, 80);
    $utmMedium = substr($att['utm_medium'], 0, 80);
    $utmCampaign = substr($att['utm_campaign'], 0, 120);
    $utmTerm = substr($att['utm_term'], 0, 120);
    $utmContent = substr($att['utm_content'], 0, 120);
    $gclid = substr($att['gclid'], 0, 180);
    $lang = substr((string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''), 0, 20);
    $tz = '';
    $eventName = substr($eventName, 0, 64);

    $stmt = $db->prepare(
        "INSERT INTO web_analytics_events
        (visitor_id, session_id, user_id, event_name, path, source_channel, referrer_host,
         utm_source, utm_medium, utm_campaign, utm_term, utm_content, gclid,
         device_type, country_code, lang, tz, ip_hash, user_agent, props_json)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param(
        'ssisssssssssssssssss',
        $visitorId,
        $sessionId,
        $userId,
        $eventName,
        $path,
        $channel,
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
    return (bool)$ok;
}
