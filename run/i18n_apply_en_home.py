#!/usr/bin/env python3
"""One-off: apply EN strings to www/en/index.html (from UK index copy)."""
from pathlib import Path

path = Path(__file__).resolve().parents[1] / "www/en/index.html"
text = path.read_text(encoding="utf-8")

pairs = [
    ('<html lang="uk">', '<html lang="en">'),
    ('content="Автоматизуйте спот-торгівлю на Binance: український кабінет, кошти на біржі, API без виводу. Trial 3 дні, 500 грн/міс."',
     'content="Automate Binance spot trading: Ukrainian SaaS, funds stay on the exchange, API without withdrawal. 3-day trial, 500 UAH/month."'),
    ('<link rel="canonical" href="https://strim.in.ua/">', '<link rel="canonical" href="https://strim.in.ua/en/">'),
    ('content="https://strim.in.ua/"', 'content="https://strim.in.ua/en/"'),
    ('content="StrIM — автоматизація спот-торгівлі на Binance"', 'content="StrIM — Binance spot trading automation"'),
    ('content="Український кабінет для сіткової торгівлі. Кошти на біржі, API без виводу. Trial 3 дні."',
     'content="Cloud dashboard for grid trading. Funds on exchange, API without withdrawal. 3-day trial."'),
    ('content="uk_UA"', 'content="en_US"'),
    ('<title>StrIM — автоматизація спот-торгівлі на Binance | Україна</title>',
     '<title>StrIM — Binance spot grid trading automation | StrIM</title>'),
    ('"inLanguage": "uk-UA"', '"inLanguage": "en-US"'),
    ('"description": "Український хмарний кабінет для спот-торгівлі на Binance"',
     '"description": "Cloud dashboard for Binance spot grid trading"'),
    ('"description": "Підписка 30 днів"', '"description": "30-day subscription"'),
    ('aria-label="Меню"', 'aria-label="Menu"'),
    ('<span class="ua-pill">Український продукт</span>', '<span class="ua-pill">Made in Ukraine</span>'),
    ('ТОВ «СтрІМ Алго» · спот на Binance через хмарний кабінет',
     'STRIM Algo LLC · Binance spot via cloud dashboard'),
    ('aria-label="Увімкнути світлу тему" title="Тема оформлення"',
     'aria-label="Light theme" title="Theme"'),
    ('href="/" aria-current="page">Головна</a>', 'href="/en/" aria-current="page">Home</a>'),
    ('href="/blog/">Блог</a>', 'href="/en/blog/">Blog</a>'),
    ('href="/about.html">Про платформу</a>', 'href="/en/about.html">About</a>'),
    ('href="/tariffs.html?v=20260512">Тарифи та оплата</a>', 'href="/en/tariffs.html">Pricing</a>'),
    ('href="/faq.html">FAQ</a>', 'href="/en/faq.html">FAQ</a>'),
    ('href="/instruction.html">Інструкція</a>', 'href="/en/instruction.html">Guide</a>'),
    ('href="/offer.html">Оферта</a>', 'href="/offer.html">Terms</a>'),
    ('href="/auth/login.php">Увійти</a>', 'href="/auth/login.php">Log in</a>'),
    ('href="/auth/register.php?next=/dashboard.php">Реєстрація</a>',
     'href="/auth/register.php?next=/dashboard.php">Sign up</a>'),
    ('Binance spot · український SaaS · без % від прибутку',
     'Binance spot · Ukrainian SaaS · no profit share'),
    ('<h1>Автоматизуйте спот-торгівлю на Binance <span class="mark">без емоцій</span></h1>',
     '<h1>Automate Binance spot trading <span class="mark">without emotions</span></h1>'),
    ('Український хмарний кабінет для сіткової торгівлі. Ваші кошти залишаються на біржі —\n            ми лише виконуємо ваші налаштування 24/7. Ключі API без права виводу.',
     'Cloud dashboard for grid trading. Your funds stay on the exchange —\n            we only execute your rules 24/7. API keys without withdrawal permission.'),
    ('Спробувати безкоштовно (3 дні)', 'Try free for 3 days'),
    ('Увійти в кабінет', 'Log in to dashboard'),
    ('Торгівля криптовалютами несе ризики. StrIM — програмне забезпечення, не інвестиційна порада.\n            Підписка 500 грн / 30 днів після trial.',
     'Crypto trading involves risk. StrIM is software, not investment advice.\n            Subscription 500 UAH / 30 days after trial.'),
    ('Хмарний кабінет', 'Cloud dashboard'),
    ('Спот · Binance', 'Spot · Binance'),
    ('Рівні стратегії', 'Strategy levels'),
    ('Журнал подій', 'Event log'),
    ('<h2>Як це працює і чому це безпечно</h2>', '<h2>How it works and why it is safe</h2>'),
    ('Кошти не переходять на наш рахунок — лише автоматизація ваших правил через API Binance.',
     'Funds never move to our accounts — only automation of your rules via the Binance API.'),
    ('<h3>Створіть API-ключ</h3>', '<h3>Create an API key</h3>'),
    ('Увімкніть лише <strong>Read</strong> та <strong>Spot trade</strong>. Дозвіл <strong>Withdrawals</strong> (вивід) залишайте вимкненим — так StrIM не може вивести кошти з біржі.',
     'Enable only <strong>Read</strong> and <strong>Spot trade</strong>. Keep <strong>Withdrawals</strong> disabled — StrIM cannot withdraw funds from the exchange.'),
    ('<h3>Налаштууйте сітку (Grid)</h3>', '<h3>Set up your grid</h3>'),
    ('Вкажіть рівні докупок і цілі take-profit. Система працює за вашим планом, без імпульсивних рішень у моменті ринку.',
     'Set DCA levels and take-profit targets. The system follows your plan without impulsive decisions.'),
    ('<h3>Контролюйте процес</h3>', '<h3>Stay in control</h3>'),
    ('Сповіщення в Telegram про угоди, портфель і журнал подій — у зручному українському кабінеті.',
     'Telegram alerts for trades, portfolio and event log in a clear dashboard.'),
    ('href="/instruction.html">Покрокова інструкція</a>', 'href="/en/instruction.html">Step-by-step guide</a>'),
    ('href="/faq.html">Часті запитання</a>', 'href="/en/faq.html">FAQ</a>'),
    ('href="/risks.html">Розкриття ризиків</a>', 'href="/en/risks.html">Risk disclosure</a>'),
    ('Рекомендовано в блозі', 'Featured on the blog'),
    ('Як налаштувати сіткову торгівлю (Grid Trading) на Binance',
     'How to set up grid trading on Binance'),
    ('Покрокова інструкція: що таке спотова сітка, базові параметри (діапазон, рівні, крок) і безпечна автоматизація через API без права виводу.',
     'Step-by-step: spot grid basics (range, levels, step) and safe API automation without withdrawal rights.'),
    ('href="/blog/grid-trading-binance.html">Читати статтю</a>',
     'href="/en/blog/grid-trading-binance.html">Read article</a>'),
    ('href="/blog/">Усі матеріали блогу</a>', 'href="/en/blog/">All blog posts</a>'),
    ('<h2>Прозорі тарифи без % від прибутку</h2>', '<h2>Transparent pricing — no profit share</h2>'),
    ('Ми не забираємо частину вашого доходу. Ви оплачуєте лише доступ до інструмента — як підписка на сервіс.',
     'We do not take a cut of your profits. You only pay for access to the tool — like a SaaS subscription.'),
    ('500 грн <span', '500 UAH <span'),
    ('/ 30 днів', '/ 30 days'),
    ('Після реєстрації — trial 3 доби без оплати', 'After sign-up — 3-day trial at no charge'),
    ('<li>Необмежена кількість сіток (стратегій)</li>', '<li>Unlimited grids (strategies)</li>'),
    ('<li>Сповіщення в Telegram</li>', '<li>Telegram notifications</li>'),
    ('<li>Безпечне підключення API (без виводу)</li>', '<li>Safe API connection (no withdrawal)</li>'),
    ('<li>Підтримка українською</li>', '<li>Support in Ukrainian</li>'),
    ('Почати тестовий період', 'Start free trial'),
    ('href="/tariffs.html">Усі способи оплати</a>', 'href="/en/tariffs.html">Payment methods</a>'),
    ('href="/offer.html">Оферта</a>', 'href="/offer.html">Terms of service</a>'),
    ('<h2>Що в кабінеті</h2>', '<h2>What is in the dashboard</h2>'),
    ('Мінімум екранів — максимум ясності для спот-торгівлі на одній біржі.',
     'Few screens — maximum clarity for spot trading on one exchange.'),
    ('<h3>Сітка та рівні</h3>', '<h3>Grid and levels</h3>'),
    ('Запуск, пауза, рівні входу, take-profit — за вашими параметрами.',
     'Start, pause, entry levels, take-profit — your parameters.'),
    ('<h3>Портфель</h3>', '<h3>Portfolio</h3>'),
    ('Баланс USDT і позиції у зв’язці з даними Binance.', 'USDT balance and positions synced with Binance.'),
    ('<h3>Безпека</h3>', '<h3>Security</h3>'),
    ('API без виводу; пароль біржі не зберігаємо. Деталі в <a href="/offer.html">оферті</a>.',
     'API without withdrawal; we do not store your exchange password. See <a href="/offer.html">terms</a>.'),
    ('strim-lang-footer.js?v=20260527perf', 'strim-lang-switch.js?v=20260602'),
    ('<strong>Відмова від відповідальності:</strong> Торгівля криптовалютами несе високі ризики втрати капіталу.\n      StrIM є виключно програмним забезпеченням для автоматизації ваших торгових стратегій і не надає фінансових чи інвестиційних порад.\n      Усі торгові рішення ви приймаєте самостійно. Сервіс не пропонує фʼючерси, маржу та вивід коштів через API.',
     '<strong>Disclaimer:</strong> Cryptocurrency trading involves substantial risk of loss.\n      StrIM is software to automate your trading strategies only and does not provide financial or investment advice.\n      All trading decisions are yours. No futures, margin or withdrawal via API.'),
    ('<a href="/blog/">Блог</a>', '<a href="/en/blog/">Blog</a>'),
    ('<a href="/tariffs.html">Тарифи</a>', '<a href="/en/tariffs.html">Pricing</a>'),
    ('<a href="/privacy.html">Конфіденційність</a>', '<a href="/privacy.html">Privacy</a>'),
    ('<a href="/returns.html">Повернення</a>', '<a href="/returns.html">Refunds</a>'),
    ('<a href="/risks.html">Ризики</a>', '<a href="/en/risks.html">Risks</a>'),
    ('<a href="/">StrIM</a>', '<a href="/en/">StrIM</a>'),
]
for old, new in pairs:
    if old not in text:
        print("MISSING:", old[:60])
    text = text.replace(old, new)

# Remove Google Translate hide CSS block (not needed)
text = text.replace("""    body{top:0!important}
    .goog-te-banner-frame,.skiptranslate iframe.goog-te-banner-frame{display:none!important;visibility:hidden!important;height:0!important}
    #google_translate_element{
      position:absolute!important;left:-9999px!important;width:1px!important;height:1px!important;
      overflow:hidden!important;clip:rect(0,0,0,0)!important;white-space:nowrap!important;
    }
""", "")

path.write_text(text, encoding="utf-8")
print("OK", path)
