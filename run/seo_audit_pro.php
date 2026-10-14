<?php
declare(strict_types=1);
/**
 * Професійний SEO-аудит strim.in.ua (on-page + технічна база).
 *   php run/seo_audit_pro.php
 *   php run/seo_audit_pro.php --json
 *   php run/seo_audit_pro.php --md > docs/SEO_AUDIT_latest.md
 */
$root = dirname(__DIR__);
require_once $root . '/run/site_audit.php';

$base = rtrim((string)(getenv('SITE_AUDIT_BASE_URL') ?: 'https://strim.in.ua'), '/');

$indexable = [
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

$noindexExpected = [
    '/dashboard.php' => '302→login',
    '/dashboard_saas.html' => 'noindex',
    '/pair_details.html' => 'noindex',
    '/strategy.html' => 'noindex',
    '/auth/forgot_password.php' => 'noindex',
    '/auth/reset_password.php' => 'noindex',
];

$pages = [];
$issues = [];
$warnings = [];
$scores = ['technical' => 0, 'onpage' => 0, 'content' => 0];
$titles = [];
$descs = [];

foreach ($indexable as $path => $label) {
    $url = $base . ($path === '/' ? '/' : $path);
    $resp = site_audit_http($url, ['follow' => true, 'timeout' => 25]);
    $html = $resp['body'];
    $row = [
        'path' => $path,
        'label' => $label,
        'http' => $resp['code'],
        'title' => null,
        'title_len' => 0,
        'desc' => null,
        'desc_len' => 0,
        'canonical' => null,
        'robots' => null,
        'h1' => 0,
        'og' => false,
        'schema' => false,
        'lang' => null,
        'issues' => [],
        'warnings' => [],
    ];
    if ($resp['error']) {
        $row['issues'][] = 'curl: ' . $resp['error'];
        $issues[] = "{$label}: помилка завантаження";
        $pages[] = $row;
        continue;
    }
    if ($resp['code'] !== 200) {
        $row['issues'][] = 'HTTP ' . $resp['code'];
        $issues[] = "{$label}: HTTP {$resp['code']}";
    }
    if (preg_match('/<html[^>]*\slang=["\']([^"\']+)["\']/i', $html, $m)) {
        $row['lang'] = $m[1];
        if (strtolower($m[1]) !== 'uk') {
            $row['warnings'][] = 'lang=' . $m[1] . ' (бажано uk)';
        }
    }
    if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
        $row['title'] = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        $row['title_len'] = strlen($row['title']);
    }
    if (preg_match('/<meta\s+name=["\']description["\']\s+content=["\']([^"\']*)["\']/i', $html, $m)) {
        $row['desc'] = $m[1];
        $row['desc_len'] = strlen($row['desc']);
    }
    if (preg_match('/<link\s+rel=["\']canonical["\']\s+href=["\']([^"\']*)["\']/i', $html, $m)) {
        $row['canonical'] = $m[1];
    }
    if (preg_match('/<meta\s+name=["\']robots["\']\s+content=["\']([^"\']*)["\']/i', $html, $m)) {
        $row['robots'] = $m[1];
    }
    if (preg_match('/property=["\']og:title["\']/i', $html)) {
        $row['og'] = true;
    }
    if (preg_match('/application\/ld\+json/i', $html)) {
        $row['schema'] = true;
    }
    if (preg_match_all('/<h1[^>]*>/i', $html, $m)) {
        $row['h1'] = count($m[0]);
    }

    $meta = site_audit_analyze_page($path, $html, $base);
    foreach ($meta['issues'] as $i) {
        $row['issues'][] = $i;
    }
    foreach ($meta['warnings'] as $w) {
        $row['warnings'][] = $w;
    }
    if ($row['title']) {
        if (isset($titles[$row['title']])) {
            $row['issues'][] = 'дублікат title';
            $issues[] = "Дублікат title: {$label} і " . $indexable[$titles[$row['title']]];
        }
        $titles[$row['title']] = $path;
    }
    if ($row['desc']) {
        if (isset($descs[$row['desc']])) {
            $row['issues'][] = 'дублікат description';
        }
        $descs[$row['desc']] = $path;
    }
    if (!$row['og'] && in_array($path, ['/', '/tariffs.html', '/about.html', '/faq.html', '/instruction.html'], true)) {
        $row['warnings'][] = 'немає Open Graph (og:title) — гірше в соцмережах';
    }
    if (!$row['schema'] && in_array($path, ['/', '/faq.html'], true)) {
        $row['warnings'][] = 'немає JSON-LD schema';
    }

    foreach ($row['issues'] as $i) {
        $issues[] = "{$label}: {$i}";
    }
    foreach ($row['warnings'] as $w) {
        $warnings[] = "{$label}: {$w}";
    }
    $pages[] = $row;
}

// robots.txt + sitemap
$robResp = site_audit_http($base . '/robots.txt');
$robTxt = $robResp['body'];
$smResp = site_audit_http($base . '/sitemap.xml');
$sitemapOk = $smResp['code'] === 200 && str_contains($smResp['body'], '<loc>');
$sitemapUrls = [];
if ($sitemapOk && preg_match_all('#<loc>([^<]+)</loc>#', $smResp['body'], $m)) {
    $sitemapUrls = $m[1];
}

$techChecks = [
    'www→apex 301' => false,
    'HSTS' => false,
    'robots.txt' => $robResp['code'] === 200,
    'sitemap.xml' => $sitemapOk,
    'sitemap у robots' => stripos($robTxt, 'Sitemap:') !== false,
];
$wwwResp = site_audit_http('https://www.strim.in.ua/', ['follow' => false]);
$techChecks['www→apex 301'] = in_array($wwwResp['code'], [301, 302, 307, 308], true)
    && stripos($wwwResp['headers'], 'strim.in.ua') !== false;
$hstsResp = site_audit_http($base . '/', ['head_only' => true]);
$techChecks['HSTS'] = site_audit_header_value($hstsResp['headers'], 'Strict-Transport-Security') !== null;

foreach ($techChecks as $name => $ok) {
    if (!$ok) {
        $issues[] = "Технічне: {$name} — FAIL";
    }
}

// sitemap vs indexable
foreach ($indexable as $path => $label) {
    $loc = $base . ($path === '/' ? '/' : $path);
    if (!in_array($loc, $sitemapUrls, true)) {
        $warnings[] = "Sitemap: немає {$label} ({$path})";
    }
}
foreach ($sitemapUrls as $loc) {
    $path = parse_url($loc, PHP_URL_PATH) ?: '/';
    if (!isset($indexable[$path])) {
        $warnings[] = "Sitemap: зайвий URL {$loc}";
    }
}

// noindex pages
foreach ($noindexExpected as $path => $expect) {
    $resp = site_audit_http($base . $path, ['follow' => false]);
    $body = $resp['body'];
    $hasNoindex = preg_match('/noindex/i', $body) || preg_match('/noindex/i', $resp['headers']);
    if (str_contains($expect, 'noindex') && !$hasNoindex && $resp['code'] === 200) {
        $warnings[] = "{$path} має бути noindex, але тег не знайдено";
    }
}

$techScore = (int)round(100 * count(array_filter($techChecks)) / max(1, count($techChecks)));
$pageIssues = array_sum(array_map(static fn($p) => count($p['issues']), $pages));
$pageWarn = array_sum(array_map(static fn($p) => count($p['warnings']), $pages));
$onpageScore = max(0, 100 - $pageIssues * 12 - $pageWarn * 3);
$contentScore = 75;
if (count($issues) === 0 && $pageWarn < 5) {
    $contentScore = 88;
}
$overall = (int)round(($techScore * 0.35) + ($onpageScore * 0.45) + ($contentScore * 0.20));

$report = [
    'generated_at' => date('c'),
    'base_url' => $base,
    'overall_score' => $overall,
    'scores' => [
        'technical' => $techScore,
        'onpage' => $onpageScore,
        'content' => $contentScore,
    ],
    'technical' => $techChecks,
    'sitemap_url_count' => count($sitemapUrls),
    'pages' => $pages,
    'issues' => $issues,
    'warnings' => $warnings,
    'recommendations' => [
        'P1' => 'Надіслати оновлений sitemap у Google Search Console',
        'P2' => 'Скоротити title до 50–60 символів і description до 150–160 (вже виправлено в репо)',
        'P3' => 'Додати OG-теги на tariffs, about, instruction, faq',
        'P4' => '1–2 статті/місяць (blog) для long-tail запитів',
        'P5' => 'Запросити індексацію ключових URL після деплою',
    ],
];

$asJson = in_array('--json', $argv ?? [], true);
$asMd = in_array('--md', $argv ?? [], true);

if ($asJson) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    exit($issues === [] ? 0 : 1);
}

if ($asMd) {
    echo "# SEO-аудит strim.in.ua\n\n";
    echo '**Дата:** ' . date('d.m.Y H:i') . " (Київ)\n\n";
    echo '**Загальна оцінка:** ' . $overall . "/100\n\n";
    echo "| Блок | Оцінка |\n|------|--------|\n";
    echo "| Технічне SEO | {$techScore}/100 |\n";
    echo "| On-page | {$onpageScore}/100 |\n";
    echo "| Контент | {$contentScore}/100 |\n\n";
    echo "## Технічна база\n\n";
    foreach ($techChecks as $k => $v) {
        echo '- ' . ($v ? '✅' : '❌') . ' ' . $k . "\n";
    }
    echo "\n## Сторінки\n\n";
    foreach ($pages as $p) {
        echo '### ' . $p['label'] . ' (`' . $p['path'] . "`)\n\n";
        echo '- Title (' . $p['title_len'] . '): ' . ($p['title'] ?? '—') . "\n";
        echo '- Description (' . $p['desc_len'] . '): ' . mb_substr($p['desc'] ?? '—', 0, 120) . "\n";
        echo '- Canonical: ' . ($p['canonical'] ?? '—') . "\n";
        echo '- H1: ' . $p['h1'] . ' | OG: ' . ($p['og'] ? 'так' : 'ні') . ' | Schema: ' . ($p['schema'] ? 'так' : 'ні') . "\n";
        if ($p['issues']) {
            echo "- **Проблеми:** " . implode('; ', $p['issues']) . "\n";
        }
        if ($p['warnings']) {
            echo "- *Зауваження:* " . implode('; ', $p['warnings']) . "\n";
        }
        echo "\n";
    }
    if ($issues) {
        echo "## Критичні проблеми\n\n";
        foreach ($issues as $i) {
            echo "- {$i}\n";
        }
        echo "\n";
    }
    if ($warnings) {
        echo "## Рекомендації\n\n";
        foreach ($warnings as $w) {
            echo "- {$w}\n";
        }
        echo "\n";
    }
    echo "## План дій\n\n";
    foreach ($report['recommendations'] as $k => $v) {
        echo "- **{$k}:** {$v}\n";
    }
    exit($issues === [] ? 0 : 1);
}

echo "=== SEO AUDIT PRO strim.in.ua ===\n";
echo "Score: {$overall}/100 (tech={$techScore} onpage={$onpageScore} content={$contentScore})\n";
echo 'Issues: ' . count($issues) . ' | Warnings: ' . count($warnings) . "\n\n";
foreach ($pages as $p) {
    echo sprintf(
        "%-16s title=%3d desc=%3d h1=%d og=%s schema=%s\n",
        $p['label'],
        $p['title_len'],
        $p['desc_len'],
        $p['h1'],
        $p['og'] ? 'Y' : 'N',
        $p['schema'] ? 'Y' : 'N'
    );
}
if ($issues) {
    echo "\nISSUES:\n";
    foreach ($issues as $i) {
        echo " - {$i}\n";
    }
    exit(1);
}
echo "\nOK\n";
exit(0);
