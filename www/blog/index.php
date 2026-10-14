<?php
declare(strict_types=1);
require __DIR__ . '/_layout.php';

$articles = strim_blog_load_articles();

strim_blog_head([
    'title' => 'Блог про автоматизацію торгівлі на Binance | StrIM',
    'description' => 'Блог StrIM: спот-сітка на Binance, безпечне підключення API, автоматизація торгівлі та ризики. Український SaaS для трейдерів.',
    'canonical' => 'https://strim.in.ua/blog/',
]);
strim_blog_header('blog');
?>
    <main class="page-main pr-main"><section class="pr-hero"><p class="pr-eyebrow">БЛОГ StrIM</p><h1>Трохи розібратися.<br>Впевненіше користуватися.</h1><p class="pr-lead">Короткі матеріали про спотову торгівлю, підключення Binance та контроль бота. Від простих понять до конкретних дій.</p></section><div class="pr-blog-grid" role="list">
<?php foreach ($articles as $a):
    $slug = (string)($a['slug'] ?? '');
    if ($slug === '') {
        continue;
    }
    $href = '/blog/' . rawurlencode($slug) . '.html?v=20260923legal';
    $title = htmlspecialchars((string)($a['title'] ?? ''), ENT_QUOTES, 'UTF-8');
    $excerpt = htmlspecialchars((string)($a['excerpt'] ?? ''), ENT_QUOTES, 'UTF-8');
    $dateUk = strim_blog_format_date_uk((string)($a['date'] ?? ''));
?>
        <article class="pr-story" role="listitem">
          <div class="pr-story-art" aria-hidden="true"><?php if (($a['cover'] ?? '') === 'api'): ?><span>Read ✓</span><span>Spot ✓</span><?php elseif ($slug === 'shcho-take-spot-sitka-na-binance'): ?><span>Купівля</span> → <span>Продаж</span><?php else: ?><div class="pr-bars"><i style="width:100%"></i><i style="width:70%"></i><i style="width:45%"></i></div><?php endif; ?></div>
          <div class="pr-story-body">
            <h2><a href="<?= htmlspecialchars($href, ENT_QUOTES) ?>"><?= $title ?></a></h2>
            <p class="blog-card-excerpt"><?= $excerpt ?></p>
            <p class="blog-card-meta"><time datetime="<?= htmlspecialchars((string)($a['date'] ?? ''), ENT_QUOTES) ?>"><?= htmlspecialchars($dateUk, ENT_QUOTES) ?></time></p>
            <a class="pr-text-link" href="<?= htmlspecialchars($href, ENT_QUOTES) ?>">Читати далі</a>
          </div>
        </article>
<?php endforeach; ?>
      </div>
    </main>
<?php
strim_blog_footer();
