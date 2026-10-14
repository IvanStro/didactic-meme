<?php
declare(strict_types=1);

/**
 * Спільний layout для PHP-сторінок блогу (/blog/).
 */
function strim_blog_head(array $meta): void
{
    $title = htmlspecialchars((string)($meta['title'] ?? 'Блог StrIM'), ENT_QUOTES, 'UTF-8');
    $description = htmlspecialchars((string)($meta['description'] ?? ''), ENT_QUOTES, 'UTF-8');
    $canonical = htmlspecialchars((string)($meta['canonical'] ?? 'https://strim.in.ua/blog/'), ENT_QUOTES, 'UTF-8');
    $ogType = htmlspecialchars((string)($meta['og_type'] ?? 'website'), ENT_QUOTES, 'UTF-8');
    ?>
<!doctype html>
<html lang="uk">
<head>
  <script>
    window.dataLayer = window.dataLayer || [];
    function gtag(){dataLayer.push(arguments);}
    gtag('consent', 'default', {
      ad_storage: 'denied', analytics_storage: 'denied', ad_user_data: 'denied',
      ad_personalization: 'denied', functionality_storage: 'granted', security_storage: 'granted', wait_for_update: 500
    });
    gtag('set', 'ads_data_redaction', true);
    gtag('js', new Date());
  </script>
  
  <script>
    gtag('config', 'G-5G2LNXY143');
    gtag('config', 'AW-18167666446');
    (function () {
      try {
        var c = localStorage.getItem('strim_consent_v2');
        if (c === 'all') {
          gtag('consent', 'update', { ad_storage: 'granted', analytics_storage: 'granted', ad_user_data: 'granted', ad_personalization: 'granted' });
          gtag('set', 'ads_data_redaction', false);
        }
      } catch (e) { /* ignore */ }
    })();
  </script>
  <link rel="stylesheet" href="/assets/css/strim-cookie-consent.css?v=20260923legal">
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <meta name="theme-color" content="#050810">
  <link rel="icon" href="/favicon.ico" sizes="48x48">
  <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32.png">
  <link rel="apple-touch-icon" href="/apple-touch-icon.png">
  <title><?= $title ?></title>
  <meta name="description" content="<?= $description ?>">
  <link rel="canonical" href="<?= $canonical ?>">
  <meta property="og:type" content="<?= $ogType ?>">
  <meta property="og:url" content="<?= $canonical ?>">
  <meta property="og:title" content="<?= $title ?>">
  <meta property="og:description" content="<?= $description ?>">
  <meta property="og:image" content="https://strim.in.ua/assets/branding/strim-og.png">
  <meta property="og:locale" content="uk_UA">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/css/strim-public.css?v=20260923legal">
  <link rel="stylesheet" href="/assets/css/strim-blog.css?v=20260602">
<?php
    if (!empty($meta['json_ld'])) {
        echo $meta['json_ld'];
    }
    ?>
<link rel="stylesheet" href="/assets/css/strim-premium.css?v=20260923legal">
</head>
<body class="premium-page">
  <div class="ua-stripe" aria-hidden="true"></div>
  <div class="page-shell">
    <div class="page-bg" aria-hidden="true"></div>
<?php
}

function strim_blog_header(string $active = ''): void
{
    $blogCurrent = $active === 'blog' ? ' aria-current="page"' : '';
    ?>
    <header class="pr-header"><a class="pr-brand" href="/?v=20260923legal">StrIM<span>Український продукт</span></a><nav class="pr-primary" aria-label="Швидкі переходи"><a href="/instruction.html?v=20260923legal">Інструкція</a><a href="/auth/login.php">Увійти</a></nav><button type="button" class="theme-toggle" id="theme-toggle" aria-label="Увімкнути світлу тему" title="Тема оформлення">
          <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 18v2M4.93 4.93l1.41 1.41m11.32 11.32 1.41 1.41M2 12h2m18 0h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/></svg>
          <svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"/></svg>
        </button><details class="pr-menu"><summary>Меню <span aria-hidden="true">☰</span></summary><nav class="strim-nav" aria-label="Навігація сайтом"><a class="chip" href="/consumer.html?v=20260923legal">Споживачу</a><a class="chip" href="/?v=20260923legal">Головна</a><a class="chip" href="/about.html?v=20260923legal">Про StrIM</a><a class="chip" href="/tariffs.html?v=20260923legal">Тарифи</a><a class="chip" href="/instruction.html?v=20260923legal">Інструкція</a><a class="chip" href="/security.html?v=20260923legal">Безпека</a><a class="chip" href="/faq.html?v=20260923legal">Запитання</a><a class="chip" href="/blog/?v=20260923legal" aria-current="page">Блог</a><a class="chip" href="/auth/login.php">Увійти</a><a class="chip primary" href="/auth/register.php?next=/dashboard.php">Створити акаунт</a></nav></details></header>
<?php
}

function strim_blog_footer(): void
{
    ?>
    <footer class="strim-footer">
      <div class="company">ТОВ «СтрІМ Алго» · StrIM</div>
      <div>Інформація на сайті не є фінансовою порадою. Торгівля криптоактивами несе ризик втрати капіталу.</div>
      <div style="margin-top:12px"><a href="/blog/?v=20260923legal">Блог</a> · <a href="/instruction.html?v=20260923legal">Інструкція</a> · <a href="/tariffs.html?v=20260923legal">Тарифи</a> · <a href="/offer.html?v=20260923legal">Оферта</a> · <a href="/risks.html?v=20260923legal">Ризики</a></div>
    <p><a href="/consumer.html?v=20260923legal">Споживачу</a> · <a href="/legal/">Архів документів</a> · <button type="button" class="strim-consent__btn" data-cookie-settings>Cookie</button></p></footer>
  </div>
  <script src="/assets/js/strim-theme.js?v=20260512" defer></script>
  <script src="/assets/js/strim-public-nav.js?v=20260927a" defer></script>
  <script src="/assets/js/strim-lang-switch.js?v=20260923legal" defer></script>
  <script src="/assets/js/strim-cookie-consent.js?v=20260927a" defer></script>
<script src="/assets/js/strim-premium.js?v=20260923legal" defer></script>
</body>
</html>
<?php
}

function strim_blog_cta(): void
{
    ?>
    <aside class="blog-cta" aria-label="Заклик до дії">
      <p>Спробуйте кабінет StrIM: до 3 стратегій до першого поповнення. Це реальна спотова торгівля з ризиком збитків.</p>
      <a class="btn-cta" href="/auth/register.php">Реєстрація</a>
    </aside>
<?php
}

function strim_blog_load_articles(): array
{
    $path = __DIR__ . '/articles.json';
    if (!is_readable($path)) {
        return [];
    }
    $raw = file_get_contents($path);
    $data = json_decode($raw ?: '[]', true);
    return is_array($data) ? $data : [];
}

function strim_blog_format_date_uk(string $iso): string
{
    $ts = strtotime($iso);
    if ($ts === false) {
        return $iso;
    }
    $months = [
        1 => 'січня', 2 => 'лютого', 3 => 'березня', 4 => 'квітня',
        5 => 'травня', 6 => 'червня', 7 => 'липня', 8 => 'серпня',
        9 => 'вересня', 10 => 'жовтня', 11 => 'листопада', 12 => 'грудня',
    ];
    $m = (int)date('n', $ts);
    return (int)date('j', $ts) . ' ' . ($months[$m] ?? '') . ' ' . date('Y', $ts);
}
