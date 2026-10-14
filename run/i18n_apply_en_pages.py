#!/usr/bin/env python3
"""Apply EN strings to copied public pages under www/en/."""
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1] / "www/en"

COMMON = [
    ('<html lang="uk">', '<html lang="en">'),
    ('content="uk_UA"', 'content="en_US"'),
    ('strim-lang-footer.js', 'strim-lang-switch.js?v=20260602'),
    ('strim-public.css?v=20260512lang3', 'strim-public.css?v=20260512lang3'),
    ('<span class="ua-pill">Український продукт</span>', '<span class="ua-pill">Made in Ukraine</span>'),
    ('ТОВ «СтрІМ Алго» · спот на Binance', 'STRIM Algo LLC · Binance spot'),
    ('href="/">Головна</a>', 'href="/en/">Home</a>'),
    ('href="/blog/">Блог</a>', 'href="/en/blog/">Blog</a>'),
    ('href="/instruction.html">Інструкція</a>', 'href="/en/instruction.html">Guide</a>'),
    ('href="/faq.html"', 'href="/en/faq.html"'),
    ('href="/about.html"', 'href="/en/about.html"'),
    ('href="/tariffs.html', 'href="/en/tariffs.html'),
    ('href="/auth/login.php">Увійти</a>', 'href="/auth/login.php">Log in</a>'),
    ('href="/auth/register.php', 'href="/auth/register.php'),
    ('Реєстрація</a>', 'Sign up</a>'),
    ('<a href="/">StrIM</a>', '<a href="/en/">StrIM</a>'),
    ('<a href="/">Головна</a>', '<a href="/en/">Home</a>'),
    (' · <span class="muted">', ' · <span class="muted">'),
]

FAQ = COMMON + [
    ('https://strim.in.ua/faq.html', 'https://strim.in.ua/en/faq.html'),
    ('<title>Часті запитання про StrIM та Binance spot</title>',
     '<title>FAQ — StrIM and Binance spot</title>'),
    ('content="FAQ StrIM: USDT на балансі, помилки API, підписка, рефералка, безпека ключів Binance spot. Відповіді support."',
     'content="StrIM FAQ: USDT balance, API errors, subscription, referral program, Binance spot API security."'),
    ('content="Часті запитання про StrIM та Binance spot"', 'content="FAQ — StrIM and Binance spot"'),
    ('content="Відповіді на типові питання про StrIM і спот-торгівлю на Binance."',
     'content="Answers to common questions about StrIM and Binance spot trading."'),
    ('aria-current="page">FAQ</a>', 'aria-current="page">FAQ</a>'),
    ('<h1>Часті запитання (FAQ)</h1>', '<h1>Frequently asked questions (FAQ)</h1>'),
    ('<p class="muted">Короткі відповіді про роботу бота, ключі Binance, баланс і підписку. Детальні кроки — у <a href="/instruction.html">інструкції</a>.</p>',
     '<p class="muted">Short answers about the bot, Binance keys, balance and subscription. Detailed steps — in the <a href="/en/instruction.html">guide</a>.</p>'),
    ('FAQ</span>', 'FAQ</span>'),
    ('<h2>Чому баланс USDT показує нуль?</h2>', '<h2>Why does USDT balance show zero?</h2>'),
    ('<h2>Помилка доступу до біржі (401 / 403)</h2>', '<h2>Exchange access error (401 / 403)</h2>'),
    ('<h2>Чому бот «мовчить»?</h2>', '<h2>Why is the bot idle?</h2>'),
    ('<h2>Як нараховуються реферальні виплати?</h2>', '<h2>How are referral rewards paid?</h2>'),
    ('<h2>Реалізований і нереалізований прибуток</h2>', '<h2>Realized and unrealized P/L</h2>'),
    ('<h2>Статус EXPIRED (підписка закінчилась)</h2>', '<h2>EXPIRED status (subscription ended)</h2>'),
    ('<h2>Ризики</h2>', '<h2>Risks</h2>'),
    ('<h2>Не знайшли відповідь?</h2>', '<h2>Did not find an answer?</h2>'),
    ('href="/tariffs.html?v=20260512">тарифи</a>', 'href="/en/tariffs.html">pricing</a>'),
    ('href="/risks.html">Ризики</a>', 'href="/en/risks.html">Risks</a>'),
    ('href="/instruction.html#binance-api">інструкцію</a>', 'href="/en/instruction.html#binance-api">guide</a>'),
    ('href="/faq.html#access-error">FAQ</a>', 'href="/en/faq.html#access-error">FAQ</a>'),
    ('Інформація на сайті не є фінансовою порадою.', 'Information on this site is not financial advice.'),
]

TARIFFS = COMMON + [
    ('https://strim.in.ua/tariffs.html', 'https://strim.in.ua/en/tariffs.html'),
    ('<title>Тарифи StrIM — 500 грн/міс, trial 3 доби</title>',
     '<title>StrIM pricing — 500 UAH/month, 3-day trial</title>'),
    ('<h1>Тарифи StrIM та способи оплати</h1>', '<h1>StrIM pricing and payment methods</h1>'),
    ('aria-current="page">Тарифи', 'aria-current="page">Pricing'),
    ('Тарифи та оплата</span>', 'Pricing</span>'),
    ('Про платформу</a>', 'About</a>'),
    ('href="/about.html">Про платформу</a>', 'href="/en/about.html">About</a>'),
]

ABOUT = COMMON + [
    ('https://strim.in.ua/about.html', 'https://strim.in.ua/en/about.html'),
    ('<title>Про StrIM — український SaaS для Binance spot</title>',
     '<title>About StrIM — Ukrainian SaaS for Binance spot</title>'),
    ('<h1>Про StrIM</h1>', '<h1>About StrIM</h1>'),
    ('Про платформу</span>', 'About</span>'),
]

BLOG_INDEX = COMMON + [
    ('https://strim.in.ua/blog/', 'https://strim.in.ua/en/blog/'),
    ('Блог про автоматизацію торгівлі на Binance | StrIM',
     'Blog — Binance trading automation | StrIM'),
    ('<h1>Блог StrIM: Трейдинг, автоматизація та безпека</h1>',
     '<h1>StrIM blog: Trading, automation and security</h1>'),
    (' · <span class="muted">Блог</span>', ' · <span class="muted">Blog</span>'),
    ('aria-current="page">Блог</a>', 'aria-current="page">Blog</a>'),
    ('href="/blog/grid-trading-binance.html', 'href="/en/blog/grid-trading-binance.html'),
    ('href="/blog/yak-pidklyuchyty', 'href="/en/blog/yak-pidklyuchyty'),
    ('href="/blog/shcho-take', 'href="/en/blog/shcho-take'),
    ('Читати далі', 'Read more'),
]

def apply(path: Path, pairs: list) -> None:
    text = path.read_text(encoding="utf-8")
    for old, new in pairs:
        text = text.replace(old, new)
    path.write_text(text, encoding="utf-8")
    print("wrote", path)

apply(ROOT / "faq.html", FAQ)
apply(ROOT / "tariffs.html", TARIFFS)
apply(ROOT / "about.html", ABOUT)
apply(ROOT / "blog/index.html", BLOG_INDEX)

# Grid article: copy already EN content from uk file - run EN meta replace
grid = ROOT / "blog/grid-trading-binance.html"
gt = grid.read_text(encoding="utf-8")
for a, b in COMMON + [
    ('https://strim.in.ua/blog/grid-trading-binance.html', 'https://strim.in.ua/en/blog/grid-trading-binance.html'),
    (' · <a href="/blog/">Блог</a>', ' · <a href="/en/blog/">Blog</a>'),
    ('href="/instruction.html"', 'href="/en/instruction.html"'),
    ('href="/tariffs.html', 'href="/en/tariffs.html'),
    ('href="/blog/yak-pidklyuchyty', 'href="/en/blog/yak-pidklyuchyty'),
    ('href="/risks.html"', 'href="/en/risks.html"'),
    ('href="/faq.html"', 'href="/en/faq.html"'),
    ('href="/blog/">Блог</a>', 'href="/en/blog/">Blog</a>'),
]:
    gt = gt.replace(a, b)
# Translate article body UK->EN (key blocks)
BODY = [
    ('2 червня 2026', '2 June 2026'),
    (' · 8 хв читання', ' · 8 min read'),
    ('Як налаштувати сіткову торгівлю (Grid Trading) на Binance: покрокова інструкція',
     'How to set up grid trading on Binance: step-by-step guide'),
    ('Grid Trading на Binance', 'Grid trading on Binance'),
]
for a, b in BODY:
    gt = gt.replace(a, b)
grid.write_text(gt, encoding="utf-8")
print("wrote", grid)
