<?php
declare(strict_types=1);
/**
 * Генерація www/sitemap.xml з єдиного списку індексованих URL.
 *   php run/generate_sitemap.php
 *   php run/generate_sitemap.php --check
 */
$root = dirname(__DIR__);
$outFile = $root . '/www/sitemap.xml';
$base = 'https://strim.in.ua';
$lastmod = date('Y-m-d');

/** @var list<array{path:string,changefreq:string,priority:float,label:string}> */
$urls = [
    ['path' => '/', 'changefreq' => 'weekly', 'priority' => 1.0, 'label' => 'Головна'],
    ['path' => '/tariffs.html', 'changefreq' => 'weekly', 'priority' => 0.9, 'label' => 'Тарифи'],
    ['path' => '/instruction.html', 'changefreq' => 'monthly', 'priority' => 0.85, 'label' => 'Інструкція'],
    ['path' => '/faq.html', 'changefreq' => 'monthly', 'priority' => 0.85, 'label' => 'FAQ'],
    ['path' => '/about.html', 'changefreq' => 'monthly', 'priority' => 0.8, 'label' => 'Про StrIM'],
    ['path' => '/security.html', 'changefreq' => 'monthly', 'priority' => 0.86, 'label' => 'Безпека'],
    ['path' => '/auth/register.php', 'changefreq' => 'monthly', 'priority' => 0.75, 'label' => 'Реєстрація'],
    ['path' => '/auth/login.php', 'changefreq' => 'monthly', 'priority' => 0.7, 'label' => 'Вхід'],
    ['path' => '/risks.html', 'changefreq' => 'yearly', 'priority' => 0.5, 'label' => 'Ризики'],
    ['path' => '/offer.html', 'changefreq' => 'yearly', 'priority' => 0.45, 'label' => 'Оферта'],
    ['path' => '/privacy.html', 'changefreq' => 'yearly', 'priority' => 0.4, 'label' => 'Конфіденційність'],
    ['path' => '/returns.html', 'changefreq' => 'yearly', 'priority' => 0.4, 'label' => 'Повернення'],
    ['path' => '/aml.html', 'changefreq' => 'yearly', 'priority' => 0.4, 'label' => 'AML'],
    ['path' => '/blog/', 'changefreq' => 'weekly', 'priority' => 0.82, 'label' => 'Блог'],
    ['path' => '/en/', 'changefreq' => 'weekly', 'priority' => 0.95, 'label' => 'Home EN'],
    ['path' => '/en/tariffs.html', 'changefreq' => 'weekly', 'priority' => 0.88, 'label' => 'Pricing EN'],
    ['path' => '/en/faq.html', 'changefreq' => 'monthly', 'priority' => 0.85, 'label' => 'FAQ EN'],
    ['path' => '/en/about.html', 'changefreq' => 'monthly', 'priority' => 0.82, 'label' => 'About EN'],
    ['path' => '/en/security.html', 'changefreq' => 'monthly', 'priority' => 0.84, 'label' => 'Security EN'],
    ['path' => '/en/instruction.html', 'changefreq' => 'monthly', 'priority' => 0.8, 'label' => 'Guide EN'],
    ['path' => '/en/blog/', 'changefreq' => 'weekly', 'priority' => 0.8, 'label' => 'Blog EN'],
];

$articlesFile = $root . '/www/blog/articles.json';
if (is_readable($articlesFile)) {
    $articles = json_decode((string)file_get_contents($articlesFile), true);
    if (is_array($articles)) {
        foreach ($articles as $a) {
            $slug = trim((string)($a['slug'] ?? ''));
            if ($slug === '') {
                continue;
            }
            $urls[] = [
                'path' => '/blog/' . $slug . '.html',
                'changefreq' => 'monthly',
                'priority' => 0.78,
                'label' => (string)($a['title'] ?? $slug),
            ];
            if ($slug === 'grid-trading-binance') {
                $urls[] = [
                    'path' => '/en/blog/grid-trading-binance.html',
                    'changefreq' => 'monthly',
                    'priority' => 0.78,
                    'label' => 'Grid trading EN',
                ];
            }
        }
    }
}

$urls = array_values(array_filter($urls, static fn($u) => !preg_match('~^/(en|de|fr|es|pl)/~', $u['path'])));
$urls[] = ['path'=>'/consumer.html','changefreq'=>'monthly','priority'=>0.8,'label'=>'Споживачу'];
$urls[] = ['path'=>'/fees.html','changefreq'=>'monthly','priority'=>0.6,'label'=>'Розрахунок плати'];
$xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
$xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($urls as $u) {
    $loc = $base . ($u['path'] === '/' ? '/' : $u['path']);
    $xml .= "  <url>\n";
    $xml .= '    <loc>' . htmlspecialchars($loc, ENT_XML1) . "</loc>\n";
    $xml .= '    <lastmod>' . $lastmod . "</lastmod>\n";
    $xml .= '    <changefreq>' . $u['changefreq'] . "</changefreq>\n";
    $xml .= '    <priority>' . number_format($u['priority'], 1, '.', '') . "</priority>\n";
    $xml .= "  </url>\n";
}
$xml .= "</urlset>\n";

$checkOnly = in_array('--check', $argv ?? [], true);
if ($checkOnly) {
    echo $xml;
    exit(0);
}

file_put_contents($outFile, $xml);
echo "Wrote {$outFile} (" . count($urls) . " URLs, lastmod={$lastmod})\n";
