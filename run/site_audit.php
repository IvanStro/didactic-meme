<?php
declare(strict_types=1);

/**
 * Аудит StrIM: сайт, інфраструктура, користувачі → звіт українською для Telegram.
 *
 *   php run/site_audit.php
 *   php run/site_audit.php --dry-run
 *   php run/site_audit.php --json
 */

/** @return array{code:int,headers:string,body:string,error:?string,final_url:?string} */
function site_audit_http(string $url, array $opts = []): array
{
    $timeout = max(5, (int)($opts['timeout'] ?? 20));
    $headers = $opts['headers'] ?? [];
    $method = strtoupper((string)($opts['method'] ?? 'GET'));
    $follow = !array_key_exists('follow', $opts) || !empty($opts['follow']);
    $headOnly = !empty($opts['head_only']);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => $follow,
        CURLOPT_MAXREDIRS => $follow ? 5 : 0,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
        CURLOPT_HEADER => true,
        CURLOPT_NOBODY => $headOnly,
        CURLOPT_CUSTOMREQUEST => $headOnly ? 'GET' : $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_USERAGENT => 'StrIM-SiteAudit/1.0',
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $finalUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);

    if (!is_string($raw)) {
        return [
            'code' => $code > 0 ? $code : 0,
            'headers' => '',
            'body' => '',
            'error' => $err !== '' ? $err : 'empty_response',
            'final_url' => $finalUrl !== '' ? $finalUrl : null,
        ];
    }

    return [
        'code' => $code,
        'headers' => substr($raw, 0, max(0, $headerSize)),
        'body' => substr($raw, max(0, $headerSize)),
        'error' => $err !== '' ? $err : null,
        'final_url' => $finalUrl !== '' ? $finalUrl : null,
    ];
}

function site_audit_header_value(string $headers, string $name): ?string
{
    $pattern = '/(?i)^' . preg_quote($name, '/') . ':\s*(.+)$/m';
    if (preg_match($pattern, $headers, $m)) {
        return trim($m[1]);
    }

    return null;
}

function site_audit_http_code_ok(int $got, int $want): bool
{
    if ($got === $want) {
        return true;
    }
    if ($want === 302 && in_array($got, [301, 302, 303, 307, 308], true)) {
        return true;
    }
    if ($want === 401 && in_array($got, [401, 403, 500], true)) {
        return true;
    }

    return false;
}

function site_audit_ensure_env(): void
{
    if (!empty($_ENV['DB_HOST'])) {
        return;
    }
    $root = dirname(__DIR__);
    $autoload = $root . '/www/vendor/autoload.php';
    if (is_file($autoload)) {
        require_once $autoload;
        if (class_exists(Dotenv\Dotenv::class)) {
            Dotenv\Dotenv::createImmutable($root)->safeLoad();
        }
    }
}

function site_audit_db(): ?mysqli
{
    site_audit_ensure_env();
    $host = (string)($_ENV['DB_HOST'] ?? '');
    $user = (string)($_ENV['DB_USER'] ?? '');
    $pass = (string)($_ENV['DB_PASS'] ?? '');
    $name = (string)($_ENV['DB_NAME'] ?? '');
    if ($host === '' || $user === '' || $name === '') {
        return null;
    }
    $db = @new mysqli($host, $user, $pass, $name);
    if ($db->connect_errno) {
        return null;
    }
    $db->set_charset('utf8mb4');

    return $db;
}

function site_audit_page_label(string $path): string
{
    static $map = [
        '/' => 'Головна',
        '/about.html' => 'Про StrIM',
        '/tariffs.html' => 'Тарифи',
        '/instruction.html' => 'Інструкція',
        '/faq.html' => 'FAQ',
        '/offer.html' => 'Оферта',
        '/privacy.html' => 'Конфіденційність',
        '/returns.html' => 'Повернення',
        '/risks.html' => 'Ризики',
        '/aml.html' => 'AML',
        '/auth/login.php' => 'Вхід',
        '/auth/register.php' => 'Реєстрація',
    ];

    return $map[$path] ?? $path;
}

function site_audit_fmt_duration(int $sec): string
{
    if ($sec < 60) {
        return $sec . ' сек';
    }
    if ($sec < 3600) {
        return (int)round($sec / 60) . ' хв';
    }
    $h = (int)floor($sec / 3600);
    $m = (int)round(($sec % 3600) / 60);

    return $h . ' год' . ($m > 0 ? ' ' . $m . ' хв' : '');
}

/** @return array<string, mixed> */
function site_audit_analyze_page(string $path, string $html, string $base): array
{
    $issues = [];
    $warnings = [];
    $title = null;
    $desc = null;
    $canon = null;
    $h1Count = 0;

    if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
        $title = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }
    if (preg_match('/<meta\s+name=["\']description["\']\s+content=["\']([^"\']*)["\']/i', $html, $m)) {
        $desc = trim($m[1]);
    }
    if (preg_match('/<link\s+rel=["\']canonical["\']\s+href=["\']([^"\']*)["\']/i', $html, $m)) {
        $canon = trim($m[1]);
    }
    if (preg_match_all('/<h1[^>]*>/i', $html, $m)) {
        $h1Count = count($m[0]);
    }

    if ($title === null || $title === '') {
        $issues[] = 'немає заголовка title';
    } elseif (strlen($title) < 20) {
        $warnings[] = 'заголовок занадто короткий (' . strlen($title) . ' символів)';
    } elseif (strlen($title) > 65) {
        $warnings[] = 'заголовок занадто довгий (' . strlen($title) . ' символів, бажано до 65)';
    }
    if ($desc === null || $desc === '') {
        $issues[] = 'немає meta description';
    } elseif (strlen($desc) < 70) {
        $issues[] = 'опис занадто короткий (' . strlen($desc) . ' символів)';
    } elseif (strlen($desc) > 165) {
        $warnings[] = 'опис занадто довгий (' . strlen($desc) . ' символів, бажано до 165)';
    }
    if ($h1Count === 0) {
        $issues[] = 'немає заголовка H1 на сторінці';
    } elseif ($h1Count > 1) {
        $issues[] = 'кілька H1 на сторінці (' . $h1Count . ')';
    }
    if ($canon === null || $canon === '') {
        $issues[] = 'немає canonical-посилання';
    } else {
        $exp = rtrim($base, '/') . ($path === '/' ? '/' : $path);
        if (rtrim($canon, '/') !== rtrim($exp, '/')) {
            $issues[] = 'canonical не збігається з URL сторінки';
        }
    }

    return [
        'path' => $path,
        'label' => site_audit_page_label($path),
        'title' => $title,
        'description_len' => $desc !== null ? strlen($desc) : 0,
        'h1_count' => $h1Count,
        'issues' => $issues,
        'warnings' => $warnings,
    ];
}

/**
 * @param array<string, mixed>|null $healthJson
 * @return array<string, mixed>
 */
function site_audit_collect_platform(?mysqli $db, ?array $healthJson): array
{
    $root = dirname(__DIR__);
    $out = [
        'db_ok' => $db !== null,
        'users_total' => null,
        'users_trading' => null,
        'strategies_running' => null,
        'users' => [],
        'spot_frozen' => [],
        'signal_queue' => [],
        'job_queue' => [],
        'anomalies' => [],
        'crit_anomalies' => [],
        'ops' => [
            'thin_exec_mode' => strtolower(trim((string)(getenv('THIN_EXEC_MODE') ?: ($_ENV['THIN_EXEC_MODE'] ?? 'off')))),
            'price_worst_age_sec' => null,
            'ws_daemon_alive' => null,
            'legacy_open' => [],
            'paused_open' => [],
            'anomalous_main_sells_24h' => [],
            'failed_24h' => 0,
            'failed_total' => 0,
        ],
    ];

    $pidPath = $root . '/services/ws/ws_price_cache.pid';
    $wsPid = is_readable($pidPath) ? (int)trim((string)@file_get_contents($pidPath)) : 0;
    $wsAlive = false;
    if ($wsPid > 0) {
        $wsAlive = function_exists('posix_kill') ? @posix_kill($wsPid, 0) : is_dir('/proc/' . $wsPid);
    }
    $out['ops']['ws_daemon_alive'] = $wsAlive;
    if (!$wsAlive) {
        $out['crit_anomalies'][] = 'WS price daemon не працює (немає живого pid).';
    }

    if (is_array($healthJson)) {
        $worst = $healthJson['prices']['worst_age_sec'] ?? null;
        $out['ops']['price_worst_age_sec'] = is_numeric($worst) ? (int)$worst : null;
        $execAlive = (int)($healthJson['signal_exec']['workers_alive'] ?? 0);
        $execTarget = (int)($healthJson['signal_exec']['workers_target'] ?? 0);
        if (!empty($healthJson['signal_exec']['enabled']) && $execTarget > 0 && $execAlive < $execTarget) {
            $out['crit_anomalies'][] = 'Працює лише ' . $execAlive . ' з ' . $execTarget . ' exec-воркерів сигналів.';
        }
        if (!empty($healthJson['signal_daemon']['enabled']) && empty($healthJson['signal_daemon']['alive'])) {
            $out['crit_anomalies'][] = 'Signal-daemon (обробка цін) не працює.';
        }
        if (($healthJson['prices']['all_fresh'] ?? null) === false) {
            $age = $out['ops']['price_worst_age_sec'] ?? '?';
            $out['crit_anomalies'][] = 'Ціни WebSocket застарілі (worst_age=' . $age . 's).';
        } elseif ($out['ops']['price_worst_age_sec'] !== null && $out['ops']['price_worst_age_sec'] > 45) {
            $out['crit_anomalies'][] = 'Ціни на межі stale (worst_age=' . $out['ops']['price_worst_age_sec'] . 's > 45).';
        }
    }

    $thin = (string)$out['ops']['thin_exec_mode'];
    if (!in_array($thin, ['off', 'shadow', 'canary', 'on', ''], true)) {
        $out['anomalies'][] = 'Невідомий THIN_EXEC_MODE=' . $thin;
    }

    if ($db === null) {
        $out['anomalies'][] = 'Не вдалося підключитися до бази даних для зведення по користувачах.';

        return $out;
    }

    $r = $db->query('SELECT COUNT(*) c FROM users');
    if ($r && ($row = $r->fetch_assoc())) {
        $out['users_total'] = (int)($row['c'] ?? 0);
    }

    $r = $db->query(
        "SELECT COUNT(*) c FROM strategies
         WHERE status='running' AND UPPER(symbol) NOT IN ('TEMPLATE') AND symbol NOT LIKE '%TEMPLATE%'"
    );
    if ($r && ($row = $r->fetch_assoc())) {
        $out['strategies_running'] = (int)($row['c'] ?? 0);
    }

    $users = [];
    $q = $db->query(
        "SELECT u.id, COALESCE(NULLIF(TRIM(u.nickname), ''), NULLIF(TRIM(u.full_name), ''), u.email, CONCAT('uid=', u.id)) AS label,
                u.billing_status,
                COUNT(s.id) AS run_cnt,
                GROUP_CONCAT(DISTINCT s.symbol ORDER BY s.symbol SEPARATOR ', ') AS symbols
         FROM users u
         LEFT JOIN strategies s ON s.user_id = u.id
            AND s.status='running'
            AND UPPER(s.symbol) NOT IN ('TEMPLATE')
            AND s.symbol NOT LIKE '%TEMPLATE%'
         GROUP BY u.id, label, u.billing_status
         HAVING run_cnt > 0
         ORDER BY run_cnt DESC, u.id"
    );
    while ($q && ($row = $q->fetch_assoc())) {
        $users[] = [
            'id' => (int)$row['id'],
            'label' => (string)$row['label'],
            'billing' => (string)($row['billing_status'] ?? ''),
            'running' => (int)($row['run_cnt'] ?? 0),
            'symbols' => (string)($row['symbols'] ?? ''),
        ];
    }
    $out['users'] = $users;
    $out['users_trading'] = count($users);

    $fr = $db->query("SELECT k, v FROM app_settings WHERE k LIKE 'spot_funds_frozen_u%'");
    while ($fr && ($row = $fr->fetch_assoc())) {
        if (!preg_match('/spot_funds_frozen_u(\d+)$/', (string)$row['k'], $m)) {
            continue;
        }
        $uid = (int)$m[1];
        $payload = json_decode((string)($row['v'] ?? ''), true);
        if (!is_array($payload) || empty($payload['frozen'])) {
            continue;
        }
        $label = 'uid=' . $uid;
        $lr = $db->query(
            "SELECT COALESCE(NULLIF(TRIM(nickname), ''), NULLIF(TRIM(full_name), ''), email) AS n FROM users WHERE id={$uid} LIMIT 1"
        );
        if ($lr && ($u = $lr->fetch_assoc()) && trim((string)($u['n'] ?? '')) !== '') {
            $label = (string)$u['n'];
        }
        $out['spot_frozen'][] = [
            'uid' => $uid,
            'label' => $label,
            'reason' => (string)($payload['reason'] ?? ''),
            'symbol' => strtoupper(trim((string)($payload['symbol'] ?? ''))),
            'strategy_id' => isset($payload['strategy_id']) ? (int)$payload['strategy_id'] : null,
        ];
    }

    // LEGACY / adopt bags still open
    $leg = $db->query(
        "SELECT s.id, s.symbol, s.status, u.nickname, p.qty, p.adopt_mode, p.origin, p.cost_avg, p.avg_price
         FROM positions p
         JOIN strategies s ON s.id = p.strategy_id
         JOIN users u ON u.id = s.user_id
         WHERE p.qty > 1e-12
           AND (
             (p.adopt_mode IS NOT NULL AND p.adopt_mode <> '')
             OR (p.origin IS NOT NULL AND p.origin <> '' AND p.origin <> 'strim')
             OR IFNULL(p.legacy_qty, 0) > 0
           )
         ORDER BY s.id
         LIMIT 20"
    );
    while ($leg && ($row = $leg->fetch_assoc())) {
        $out['ops']['legacy_open'][] = [
            'sid' => (int)$row['id'],
            'symbol' => (string)$row['symbol'],
            'status' => (string)$row['status'],
            'nick' => (string)($row['nickname'] ?? ''),
            'qty' => (float)$row['qty'],
            'adopt_mode' => (string)($row['adopt_mode'] ?? ''),
        ];
    }
    if ($out['ops']['legacy_open'] !== []) {
        $n = count($out['ops']['legacy_open']);
        $out['crit_anomalies'][] = 'Відкриті LEGACY/adopt позиції: ' . $n
            . ' (pause-exit може продати по grid basis ≠ cost_avg).';
    }

    // Paused with open qty (pause-exit wind-down)
    $po = $db->query(
        "SELECT s.id, s.symbol, u.nickname, p.qty, p.avg_price, p.adopt_mode, p.origin
         FROM strategies s
         JOIN users u ON u.id = s.user_id
         JOIN positions p ON p.strategy_id = s.id
         WHERE s.status = 'paused' AND p.qty > 1e-12
         ORDER BY s.id
         LIMIT 30"
    );
    while ($po && ($row = $po->fetch_assoc())) {
        $out['ops']['paused_open'][] = [
            'sid' => (int)$row['id'],
            'symbol' => (string)$row['symbol'],
            'nick' => (string)($row['nickname'] ?? ''),
            'qty' => (float)$row['qty'],
            'adopt_mode' => (string)($row['adopt_mode'] ?? ''),
        ];
    }

    // Anomalous MAIN SELL (system PnL>>normal grid TP) — catches legacy-basis dumps
    $ms = $db->query(
        "SELECT j.created_at, j.strategy_id, j.symbol, j.pnl_pct, j.pnl_usdt, j.qty, j.price,
                COALESCE(NULLIF(TRIM(u.nickname), ''), u.email, CONCAT('uid=', u.id)) AS nick
         FROM engine_journal j
         LEFT JOIN strategies s ON s.id = j.strategy_id
         LEFT JOIN users u ON u.id = s.user_id
         WHERE j.created_at >= (NOW() - INTERVAL 24 HOUR)
           AND j.message LIKE '%MAIN SELL%'
           AND j.pnl_pct IS NOT NULL
           AND (j.pnl_pct > 5 OR j.pnl_pct < -3)
         ORDER BY j.id DESC
         LIMIT 10"
    );
    while ($ms && ($row = $ms->fetch_assoc())) {
        $out['ops']['anomalous_main_sells_24h'][] = [
            'at' => (string)$row['created_at'],
            'sid' => (int)$row['strategy_id'],
            'symbol' => (string)$row['symbol'],
            'nick' => (string)($row['nick'] ?? ''),
            'pnl_pct' => (float)$row['pnl_pct'],
            'pnl_usdt' => isset($row['pnl_usdt']) ? (float)$row['pnl_usdt'] : null,
        ];
    }
    if ($out['ops']['anomalous_main_sells_24h'] !== []) {
        $first = $out['ops']['anomalous_main_sells_24h'][0];
        $out['crit_anomalies'][] = 'Аномальний MAIN SELL за 24h: '
            . $first['symbol'] . ' sid=' . $first['sid'] . ' @' . $first['nick']
            . ' pnl=' . round((float)$first['pnl_pct'], 2) . '% (перевірте basis/LEGACY).';
    }

    $sqTable = $db->query("SHOW TABLES LIKE 'signal_queue'");
    if ($sqTable && $sqTable->num_rows > 0) {
        $counts = ['queued' => 0, 'processing' => 0, 'done' => 0, 'failed' => 0];
        $rc = $db->query('SELECT status, COUNT(*) c FROM signal_queue GROUP BY status');
        while ($rc && ($c = $rc->fetch_assoc())) {
            $st = (string)($c['status'] ?? '');
            if (isset($counts[$st])) {
                $counts[$st] = (int)($c['c'] ?? 0);
            }
        }
        $out['signal_queue'] = $counts;
        $out['ops']['failed_total'] = $counts['failed'];
        $rf = $db->query(
            "SELECT COUNT(*) c FROM signal_queue
             WHERE status='failed' AND created_at >= (NOW() - INTERVAL 24 HOUR)"
        );
        $failed24 = ($rf && ($fr = $rf->fetch_assoc())) ? (int)($fr['c'] ?? 0) : 0;
        $out['ops']['failed_24h'] = $failed24;
        // Only alert on recent growth — ignore old deadlocked tails
        if ($failed24 >= 3) {
            $out['anomalies'][] = 'За 24h з’явилось ' . $failed24 . ' failed у signal_queue (порог ≥3).';
        }
        if ($counts['processing'] > 0) {
            $stuck = $db->query(
                "SELECT COUNT(*) c FROM signal_queue
                 WHERE status='processing' AND updated_at < (NOW() - INTERVAL 5 MINUTE)"
            );
            $stuckN = ($stuck && ($sr = $stuck->fetch_assoc())) ? (int)($sr['c'] ?? 0) : 0;
            if ($stuckN > 0) {
                $out['crit_anomalies'][] = $stuckN . ' сигнал(ів) зависли в processing >5 хв.';
            }
        }
    }

    $jqTable = $db->query("SHOW TABLES LIKE 'job_queue'");
    if ($jqTable && $jqTable->num_rows > 0) {
        $counts = ['queued' => 0, 'processing' => 0, 'done' => 0, 'failed' => 0];
        $rc = $db->query('SELECT status, COUNT(*) c FROM job_queue GROUP BY status');
        while ($rc && ($c = $rc->fetch_assoc())) {
            $st = (string)($c['status'] ?? '');
            if (isset($counts[$st])) {
                $counts[$st] = (int)($c['c'] ?? 0);
            }
        }
        $out['job_queue'] = $counts;
        $rf = $db->query(
            "SELECT COUNT(*) c FROM job_queue WHERE status='failed' AND updated_at >= (NOW() - INTERVAL 1 HOUR)"
        );
        $failed1h = ($rf && ($fr = $rf->fetch_assoc())) ? (int)($fr['c'] ?? 0) : 0;
        if ($failed1h > 0) {
            $out['anomalies'][] = 'За останню годину ' . $failed1h . ' failed у job_queue.';
        }
    }

    return $out;
}

/**
 * @param array<string, mixed> $opts base_url, health_token
 * @return array<string, mixed>
 */
function site_audit_run(array $opts = []): array
{
    site_audit_ensure_env();
    date_default_timezone_set('Europe/Kyiv');

    $base = rtrim((string)($opts['base_url'] ?? getenv('SITE_AUDIT_BASE_URL') ?: ($_ENV['SITE_AUDIT_BASE_URL'] ?? 'https://strim.in.ua')), '/');
    $healthToken = trim((string)($opts['health_token'] ?? getenv('HEALTH_TOKEN') ?: ($_ENV['HEALTH_TOKEN'] ?? '')));

    $httpChecks = [
        '/' => 200,
        '/about.html' => 200,
        '/tariffs.html' => 200,
        '/instruction.html' => 200,
        '/faq.html' => 200,
        '/offer.html' => 200,
        '/privacy.html' => 200,
        '/returns.html' => 200,
        '/risks.html' => 200,
        '/aml.html' => 200,
        '/auth/login.php' => 200,
        '/auth/register.php' => 200,
        '/dashboard.php' => 302,
        '/admin/users.php' => 302,
        '/api/health.php' => 403,
        '/auth/api_gate.php?path=me.php' => 401,
        '/auth/api_gate.php?path=saas_core.php' => 401,
        '/robots.txt' => 200,
        '/sitemap.xml' => 200,
    ];

    $seoPages = [
        '/',
        '/about.html',
        '/tariffs.html',
        '/instruction.html',
        '/faq.html',
        '/offer.html',
        '/privacy.html',
        '/returns.html',
        '/risks.html',
        '/aml.html',
        '/auth/login.php',
        '/auth/register.php',
    ];

    $issues = [];
    $warnings = [];
    $seoDetails = [];
    $httpOk = 0;
    $httpTotal = count($httpChecks);
    $httpFailed = [];
    $titles = [];
    $pageCache = [];

    foreach ($httpChecks as $path => $wantCode) {
        $url = $base . ($path === '/' ? '/' : $path);
        $follow = !in_array($wantCode, [302, 401, 403], true);
        $resp = site_audit_http($url, ['follow' => $follow]);
        $got = (int)$resp['code'];
        if ($resp['error'] !== null) {
            $msg = 'Сторінка ' . site_audit_page_label($path) . ' (' . $path . '): помилка з’єднання — ' . $resp['error'];
            $issues[] = $msg;
            $httpFailed[] = $msg;
            continue;
        }
        if (!site_audit_http_code_ok($got, $wantCode)) {
            $msg = 'Сторінка ' . site_audit_page_label($path) . ': очікувався код ' . $wantCode . ', отримано ' . $got;
            $issues[] = $msg;
            $httpFailed[] = $msg;
            continue;
        }
        $httpOk++;
        if (in_array($path, $seoPages, true) || $path === '/robots.txt' || $path === '/sitemap.xml') {
            $pageCache[$path] = $resp['body'];
        }
    }

    foreach ($seoPages as $path) {
        $html = (string)($pageCache[$path] ?? '');
        if ($html === '') {
            continue;
        }
        $meta = site_audit_analyze_page($path, $html, $base);
        if ($meta['title'] !== null && $meta['title'] !== '') {
            $t = (string)$meta['title'];
            if (isset($titles[$t])) {
                $issues[] = 'Однаковий title на «' . site_audit_page_label($titles[$t]) . '» і «' . site_audit_page_label($path) . '»';
            }
            $titles[$t] = $path;
        }
        if (!empty($meta['issues']) || !empty($meta['warnings'])) {
            $seoDetails[] = $meta;
        }
        foreach ($meta['issues'] as $iss) {
            $issues[] = 'SEO «' . $meta['label'] . '»: ' . $iss;
        }
        // Soft SEO length tips stay in seo_details only — не псують щогодинний TG.
    }

    $robots = (string)($pageCache['/robots.txt'] ?? '');
    if ($robots !== '' && stripos($robots, 'sitemap:') === false) {
        $issues[] = 'У robots.txt немає посилання на sitemap';
    }
    $sitemap = (string)($pageCache['/sitemap.xml'] ?? '');
    if ($sitemap !== '' && stripos($sitemap, '<loc>') === false) {
        $issues[] = 'sitemap.xml порожній або некоректний';
    }

    $hstsOk = false;
    $resp = site_audit_http($base . '/', ['head_only' => true, 'timeout' => 12, 'follow' => true]);
    $hsts = site_audit_header_value($resp['headers'], 'Strict-Transport-Security');
    if ($hsts !== null && stripos($hsts, 'max-age=') !== false) {
        $hstsOk = true;
    }
    if (!$hstsOk) {
        $issues[] = 'Не налаштовано HSTS (примусовий HTTPS) — браузери можуть не пам’ятати безпечне з’єднання';
    }

    $health = ['checked' => false, 'ok' => null, 'engine_alive' => null, 'lag_seconds' => null, 'prices_fresh' => null];
    $healthFull = null;
    if ($healthToken !== '') {
        $healthUrl = $base . '/api/health.php';
        $resp = site_audit_http($healthUrl, [
            'headers' => ['X-Strim-Health-Token: ' . $healthToken],
            'timeout' => 25,
        ]);
        $health['checked'] = true;
        if ($resp['code'] !== 200) {
            $issues[] = 'Служба health не відповіла (HTTP ' . $resp['code'] . ')';
        } else {
            $j = json_decode($resp['body'], true);
            if (!is_array($j)) {
                $issues[] = 'Служба health повернула некоректні дані';
            } else {
                $healthFull = $j;
                $health['ok'] = !empty($j['ok']);
                $health['engine_alive'] = !empty($j['engine_alive']);
                $health['lag_seconds'] = isset($j['lag_seconds']) ? (int)$j['lag_seconds'] : null;
                $health['prices_fresh'] = $j['prices']['all_fresh'] ?? null;
                $health['price_worst_age_sec'] = isset($j['prices']['worst_age_sec']) ? (int)$j['prices']['worst_age_sec'] : null;
                $health['engine_mode'] = (string)($j['engine_mode'] ?? '');
                $health['db_ok'] = ($j['db_ok'] ?? true) !== false;
                if (empty($j['ok'])) {
                    $issues[] = 'Загальний статус health — проблема';
                }
                if (empty($j['engine_alive'])) {
                    $issues[] = 'Торговий рушій не позначений як активний';
                }
                if (($j['db_ok'] ?? true) === false) {
                    $issues[] = 'База даних не відповідає в health-перевірці';
                }
                if (isset($j['prices']['all_fresh']) && $j['prices']['all_fresh'] === false) {
                    $worst = $health['price_worst_age_sec'] ?? '?';
                    $issues[] = 'Ціни для торгівлі застарілі (worst_age=' . $worst . 's)';
                }
            }
        }
    } else {
        $warnings[] = 'Health-перевірка пропущена (немає HEALTH_TOKEN у .env)';
    }

    $db = site_audit_db();
    $platform = site_audit_collect_platform($db, $healthFull);
    if ($db) {
        $db->close();
    }
    foreach ($platform['crit_anomalies'] ?? [] as $a) {
        $issues[] = (string)$a;
    }
    foreach ($platform['anomalies'] as $a) {
        $warnings[] = $a;
    }

    $status = 'ok';
    if ($issues !== []) {
        $critN = 0;
        foreach ($issues as $i) {
            if (preg_match('/HTTP 5|не відповіла|не працює|не активний|порожній|немає заголовка H1|Однаковий title|база даних не|застаріл|LEGACY|Аномальний MAIN|зависли|WS price/i', $i)) {
                $critN++;
            }
        }
        $status = ($critN > 0 || count($issues) >= 4) ? 'critical' : 'warn';
    } elseif ($warnings !== [] || !empty($platform['spot_frozen'])) {
        $status = 'warn';
    }

    return [
        'generated_at' => date('c'),
        'generated_at_human' => date('d.m.Y H:i') . ' (Київ)',
        'base_url' => $base,
        'hostname' => gethostname() ?: 'unknown',
        'status' => $status,
        'ok' => $issues === [],
        'issues' => $issues,
        'warnings' => $warnings,
        'http' => ['ok' => $httpOk, 'total' => $httpTotal, 'failed' => $httpFailed],
        'seo_details' => $seoDetails,
        'hsts_ok' => $hstsOk,
        'health' => $health,
        'health_full' => $healthFull,
        'platform' => $platform,
    ];
}

/** @param array<string, mixed> $report @return list<string> */
function site_audit_telegram_messages(array $report): array
{
    $status = (string)($report['status'] ?? 'ok');
    $issues = is_array($report['issues'] ?? null) ? $report['issues'] : [];
    $warnings = is_array($report['warnings'] ?? null) ? $report['warnings'] : [];
    $platform = is_array($report['platform'] ?? null) ? $report['platform'] : [];
    $ops = is_array($platform['ops'] ?? null) ? $platform['ops'] : [];
    $health = is_array($report['health'] ?? null) ? $report['health'] : [];
    $healthFull = is_array($report['health_full'] ?? null) ? $report['health_full'] : null;
    $http = is_array($report['http'] ?? null) ? $report['http'] : [];

    if ($status === 'critical') {
        $icon = '🚨';
        $verdict = 'Є серйозні проблеми — потрібна увага.';
    } elseif ($status === 'warn') {
        $icon = '⚠️';
        $verdict = 'Система працює, але є зауваження.';
    } else {
        $icon = '✅';
        $verdict = 'Усе в нормі, критичних проблем не виявлено.';
    }

    $parts = [];

    // --- 1: header ---
    $p1 = [];
    $p1[] = $icon . ' <b>StrIM — щогодинний звіт</b>';
    $p1[] = '🕐 ' . htmlspecialchars((string)($report['generated_at_human'] ?? date('d.m.Y H:i')), ENT_QUOTES, 'UTF-8');
    $p1[] = '';
    $p1[] = '<b>Висновок:</b> ' . $verdict;
    $httpOk = (int)($http['ok'] ?? 0);
    $httpTotal = (int)($http['total'] ?? 0);
    if ($httpOk === $httpTotal && $httpTotal > 0) {
        $p1[] = 'Сайт: OK (' . $httpTotal . ' URL)' . (!empty($report['hsts_ok']) ? ', HSTS ✓' : '');
    } elseif ($httpTotal > 0) {
        $p1[] = 'Сайт: ' . $httpOk . '/' . $httpTotal . ' URL';
    }
    $parts[] = implode("\n", $p1);

    // --- 2: trading ---
    $p2 = [];
    $p2[] = '<b>🔧 Торгова система</b>';
    if (!empty($health['checked'])) {
        $mode = (string)($health['engine_mode'] ?? ($healthFull['engine_mode'] ?? ''));
        $thin = (string)($ops['thin_exec_mode'] ?? 'off');
        $p2[] = '• Рушій: ' . (!empty($health['engine_alive']) ? 'активний' : '❗ down')
            . ($mode !== '' ? ' / ' . htmlspecialchars($mode, ENT_QUOTES) : '')
            . ' · thin=' . htmlspecialchars($thin !== '' ? $thin : 'off', ENT_QUOTES);
        $lag = isset($health['lag_seconds']) ? (int)$health['lag_seconds'] : null;
        if ($lag !== null) {
            $p2[] = '• Остання активність: ' . site_audit_fmt_duration($lag) . ' тому';
        }
        $worst = $ops['price_worst_age_sec'] ?? ($health['price_worst_age_sec'] ?? null);
        $fresh = $health['prices_fresh'] ?? null;
        $wsOk = $ops['ws_daemon_alive'] ?? null;
        $priceLine = '• Ціни WS: ';
        if ($fresh === true && ($worst === null || (int)$worst <= 45)) {
            $priceLine .= 'OK' . ($worst !== null ? ' (worst=' . (int)$worst . 's)' : '');
        } elseif ($fresh === false || ($worst !== null && (int)$worst > 45)) {
            $priceLine .= '❗ stale worst=' . ($worst ?? '?') . 's';
        } else {
            $priceLine .= 'невідомо';
        }
        if ($wsOk === false) {
            $priceLine .= ' · daemon ❗';
        } elseif ($wsOk === true) {
            $priceLine .= ' · daemon ✓';
        }
        $p2[] = $priceLine;
        if (is_array($healthFull)) {
            $sig = $healthFull['signal_daemon'] ?? [];
            $ex = $healthFull['signal_exec'] ?? [];
            if (!empty($sig['enabled']) || !empty($ex['enabled'])) {
                $sd = !empty($sig['alive']) ? 'daemon✓' : 'daemon❗';
                $alive = (int)($ex['workers_alive'] ?? 0);
                $target = (int)($ex['workers_target'] ?? 0);
                $p2[] = '• Сигнали: ' . $sd . ' · exec ' . $alive . '/' . $target
                    . (($target > 0 && $alive < $target) ? ' ❗' : '');
            }
            $sq = is_array($platform['signal_queue'] ?? null) ? $platform['signal_queue'] : ($healthFull['signal_queue'] ?? []);
            if (is_array($sq)) {
                $failed24 = (int)($ops['failed_24h'] ?? 0);
                $p2[] = '• Черга: q=' . (int)($sq['queued'] ?? 0)
                    . ' proc=' . (int)($sq['processing'] ?? 0)
                    . ' fail24h=' . $failed24
                    . ' (total_fail=' . (int)($ops['failed_total'] ?? $sq['failed'] ?? 0) . ')';
            }
            $symN = (int)($healthFull['prices']['running_symbols'] ?? 0);
            if ($symN > 0) {
                $p2[] = '• Пар під наглядом: ' . $symN;
            }
        }
    } else {
        $p2[] = 'Health недоступний (немає HEALTH_TOKEN).';
    }
    $parts[] = implode("\n", $p2);

    // --- 3: users + risk bags ---
    $p3 = [];
    $p3[] = '<b>👥 Користувачі / ризик</b>';
    if (!empty($platform['db_ok'])) {
        $p3[] = '• Юзерів: ' . (int)($platform['users_total'] ?? 0)
            . ' · торгують: ' . (int)($platform['users_trading'] ?? 0)
            . ' · running: ' . (int)($platform['strategies_running'] ?? 0);
        $users = is_array($platform['users'] ?? null) ? $platform['users'] : [];
        foreach ($users as $u) {
            if (!is_array($u)) {
                continue;
            }
            $p3[] = '• ' . htmlspecialchars((string)($u['label'] ?? '?'), ENT_QUOTES)
                . ' — ' . (int)($u['running'] ?? 0) . ': '
                . htmlspecialchars((string)($u['symbols'] ?? ''), ENT_QUOTES);
        }
        $paused = is_array($ops['paused_open'] ?? null) ? $ops['paused_open'] : [];
        if ($paused !== []) {
            $p3[] = '';
            $p3[] = '<b>Пауза з відкритою qty (' . count($paused) . '):</b>';
            foreach (array_slice($paused, 0, 8) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $am = (string)($row['adopt_mode'] ?? '');
                $p3[] = '• sid=' . (int)($row['sid'] ?? 0) . ' '
                    . htmlspecialchars((string)($row['symbol'] ?? ''), ENT_QUOTES)
                    . ' @' . htmlspecialchars((string)($row['nick'] ?? ''), ENT_QUOTES)
                    . ' qty=' . rtrim(rtrim(number_format((float)($row['qty'] ?? 0), 8, '.', ''), '0'), '.')
                    . ($am !== '' ? ' [' . htmlspecialchars($am, ENT_QUOTES) . ']' : '');
            }
        }
        $frozen = is_array($platform['spot_frozen'] ?? null) ? $platform['spot_frozen'] : [];
        if ($frozen !== []) {
            $p3[] = '';
            $p3[] = '<b>❗ Заморозка докупок (USDT):</b>';
            foreach ($frozen as $fz) {
                if (!is_array($fz)) {
                    continue;
                }
                $who = htmlspecialchars((string)($fz['label'] ?? '?'), ENT_QUOTES);
                $sym = (string)($fz['symbol'] ?? '');
                $reason = (string)($fz['reason'] ?? '');
                $p3[] = '• ' . $who . ($sym !== '' ? ' / ' . htmlspecialchars($sym, ENT_QUOTES) : '');
                if ($reason !== '') {
                    $short = preg_replace('/^([A-Z0-9]+USDT)\s+sid=\d+:\s*/', '', $reason);
                    $p3[] = '  ' . htmlspecialchars(mb_substr($short ?: $reason, 0, 180), ENT_QUOTES);
                }
            }
        }
        $anomSells = is_array($ops['anomalous_main_sells_24h'] ?? null) ? $ops['anomalous_main_sells_24h'] : [];
        if ($anomSells !== []) {
            $p3[] = '';
            $p3[] = '<b>🚨 Аномальний MAIN SELL 24h:</b>';
            foreach (array_slice($anomSells, 0, 5) as $s) {
                if (!is_array($s)) {
                    continue;
                }
                $p3[] = '• ' . htmlspecialchars((string)($s['at'] ?? ''), ENT_QUOTES)
                    . ' ' . htmlspecialchars((string)($s['symbol'] ?? ''), ENT_QUOTES)
                    . ' sid=' . (int)($s['sid'] ?? 0)
                    . ' @' . htmlspecialchars((string)($s['nick'] ?? ''), ENT_QUOTES)
                    . ' pnl=' . round((float)($s['pnl_pct'] ?? 0), 2) . '%';
            }
        }
    } else {
        $p3[] = 'БД недоступна для зведення користувачів.';
    }
    $parts[] = implode("\n", $p3);

    // --- 4: SEO only if hard issues ---
    $seoDetails = is_array($report['seo_details'] ?? null) ? $report['seo_details'] : [];
    $seoHard = [];
    foreach ($seoDetails as $page) {
        if (!is_array($page) || empty($page['issues'])) {
            continue;
        }
        $seoHard[] = $page;
    }
    $p4 = [];
    $p4[] = '<b>🔍 SEO</b>';
    if ($seoHard === []) {
        $softN = 0;
        foreach ($seoDetails as $page) {
            if (is_array($page) && !empty($page['warnings'])) {
                $softN += count($page['warnings']);
            }
        }
        $p4[] = $softN > 0
            ? 'Критичних помилок немає (м’які length-поради: ' . $softN . ', у TG не дублюємо).'
            : 'Без зауважень.';
    } else {
        $p4[] = 'Критичні SEO-помилки:';
        foreach ($seoHard as $page) {
            $label = htmlspecialchars((string)($page['label'] ?? $page['path'] ?? '?'), ENT_QUOTES);
            $p4[] = '<b>' . $label . '</b>';
            foreach ($page['issues'] as $iss) {
                $p4[] = '  ❗ ' . htmlspecialchars((string)$iss, ENT_QUOTES);
            }
        }
    }
    $parts[] = implode("\n", $p4);

    // --- 5: attention ---
    if ($issues !== []) {
        $p5 = [];
        $p5[] = '<b>❗ Потрібна увага</b>';
        foreach ($issues as $i) {
            $p5[] = '• ' . htmlspecialchars((string)$i, ENT_QUOTES);
        }
        $parts[] = implode("\n", $p5);
    }

    // --- 6: other warnings (non-SEO) ---
    $otherWarn = array_values(array_filter($warnings, static function ($w) {
        return !str_starts_with((string)$w, 'SEO «');
    }));
    if ($otherWarn !== []) {
        $p6 = [];
        $p6[] = '<b>ℹ️ Інші зауваження</b>';
        foreach ($otherWarn as $w) {
            $p6[] = '• ' . htmlspecialchars((string)$w, ENT_QUOTES);
        }
        $parts[] = implode("\n", $p6);
    }

    return site_audit_split_telegram_messages($parts);
}

/** @param list<string> $parts @return list<string> */
function site_audit_split_telegram_messages(array $parts, int $maxLen = 3900): array
{
    $messages = [];
    $current = '';
    $partNum = 0;
    $totalParts = count($parts);

    foreach ($parts as $idx => $part) {
        $part = trim($part);
        if ($part === '') {
            continue;
        }
        $header = ($totalParts > 1) ? ('<i>Частина ' . ($idx + 1) . '/' . $totalParts . "</i>\n\n") : '';
        $chunk = $header . $part;

        if ($current !== '' && (strlen($current) + strlen($chunk) + 2) > $maxLen) {
            $messages[] = $current;
            $current = $chunk;
        } elseif (strlen($chunk) > $maxLen) {
            if ($current !== '') {
                $messages[] = $current;
                $current = '';
            }
            // Розбиваємо довгу частину по рядках
            $lines = explode("\n", $chunk);
            $buf = '';
            foreach ($lines as $line) {
                if ($buf !== '' && strlen($buf) + strlen($line) + 1 > $maxLen) {
                    $messages[] = $buf;
                    $buf = $line;
                } else {
                    $buf = ($buf === '') ? $line : ($buf . "\n" . $line);
                }
            }
            if ($buf !== '') {
                $current = $buf;
            }
        } else {
            $current = ($current === '') ? $chunk : ($current . "\n\n" . $chunk);
        }
        $partNum++;
    }
    if ($current !== '') {
        $messages[] = $current;
    }

    return $messages !== [] ? $messages : ['✅ StrIM — звіт порожній (перевірте site_audit.php)'];
}

/** @deprecated використовуй site_audit_telegram_messages */
function site_audit_telegram_lines(array $report): array
{
    return site_audit_telegram_messages($report);
}

if (PHP_SAPI === 'cli' && realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    $asJson = in_array('--json', $argv ?? [], true);
    $dryRun = in_array('--dry-run', $argv ?? [], true);
    $report = site_audit_run();
    if ($asJson) {
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(($report['ok'] ?? false) ? 0 : 1);
    }
    $msgs = site_audit_telegram_messages($report);
    if ($dryRun) {
        foreach ($msgs as $i => $msg) {
            if ($i > 0) {
                echo "\n--- message " . ($i + 1) . " ---\n";
            }
            echo strip_tags(str_replace(['<b>', '</b>', '<i>', '</i>'], ['**', '**', '_', '_'], $msg)) . "\n";
        }
        exit(0);
    }
    echo "=== STRIM SITE AUDIT ===\n";
    echo 'Status: ' . strtoupper((string)$report['status']) . "\n";
    foreach ($msgs as $i => $msg) {
        echo "\n--- TG " . ($i + 1) . '/' . count($msgs) . " ---\n";
        echo strip_tags($msg) . "\n";
    }
    exit(($report['ok'] ?? false) ? 0 : 1);
}
