#!/usr/bin/env python3
from pathlib import Path
p = Path(__file__).resolve().parents[1] / "www/en/faq.html"
t = p.read_text(encoding="utf-8")
pairs = [
    ("<p>Кабінет показує <b>вільний USDT на спот-гаманці Binance</b>, прив’язаному до ваших API-ключів. Нуль означає, що на споті немає вільних USDT (кошти можуть бути в ордерах, інших активах або на іншому суб-акаунті).</p>",
     "<p>The dashboard shows <b>free USDT on your Binance spot wallet</b> linked to your API keys. Zero means no free USDT on spot (funds may be in orders, other assets or another sub-account).</p>"),
    ("<p class=\"muted\">Для докупок сітки потрібен вільний USDT на споті. Поповніть спот і перевірте, що ключ має доступ до спот-торгівлі.</p>",
     "<p class=\"muted\">Grid DCA requires free USDT on spot. Top up spot and ensure the key has spot trading access.</p>"),
    ("<p>Binance відхилив API-запит. Типові причини:</p>", "<p>Binance rejected the API request. Common causes:</p>"),
    ("<li><b>IP whitelist</b> — на Binance не додано IP сервера StrIM (див. <a href=\"/en/instruction.html#binance-api\">guide</a>).</li>",
     "<li><b>IP whitelist</b> — StrIM server IP not added on Binance (see <a href=\"/en/instruction.html#binance-api\">guide</a>).</li>"),
    ("<li>Ключ прострочений або видалений.</li>", "<li>Key expired or deleted.</li>"),
    ("<li>Немає прав <b>Reading</b> та <b>Spot Trading</b>.</li>", "<li>Missing <b>Reading</b> and <b>Spot Trading</b> permissions.</li>"),
    ("<p>StrIM може <b>автоматично поставити стратегію на паузу</b>. Після виправлення ключів — натисніть <b>Start</b> у кабінеті.</p>",
     "<p>StrIM may <b>pause the strategy automatically</b>. After fixing keys — press <b>Start</b> in the dashboard.</p>"),
    ("<li><b>Стратегія на паузі</b> — натисніть Start.</li>", "<li><b>Strategy paused</b> — press Start.</li>"),
    ("<li><b>Закінчилась підписка</b> — продовжіть доступ (<a href=\"/en/tariffs.html?v=20260512\">тарифи</a>).</li>",
     "<li><b>Subscription ended</b> — renew access (<a href=\"/en/tariffs.html\">pricing</a>).</li>"),
    ("<li><b>Ціна не дійшла</b> до рівня докупки або TP.</li>", "<li><b>Price did not reach</b> DCA or TP level.</li>"),
    ("<li><b>Недостатньо USDT</b> для мінімального ордера Binance.</li>", "<li><b>Not enough USDT</b> for Binance minimum order.</li>"),
    ("<li><b>Проблема з API</b> — див. розділ про помилку доступу.</li>", "<li><b>API issue</b> — see exchange access error.</li>"),
    ("<p>Реферер отримує винагороду після підтвердженої оплати підписки запрошеним користувачем (актуальна сума — у кабінеті та <a href=\"/offer.html\">оферті</a>).</p>",
     "<p>Referrer is rewarded after the invited user’s subscription payment is confirmed (current amount in dashboard and <a href=\"/offer.html\">terms</a>).</p>"),
    ("<li>Нарахування — після підтвердження адміністратором.</li>", "<li>Credited after admin confirmation.</li>"),
    ("<li>Виведення — BSC USDT (BEP-20).</li>", "<li>Withdrawal — BSC USDT (BEP-20).</li>"),
    ("<li>Один платіж — одне нарахування.</li>", "<li>One payment — one credit.</li>"),
    ("<p><b>Реалізований</b> — P/L від виконаних продажів у журналі.</p>", "<p><b>Realized</b> — P/L from completed sells in the journal.</p>"),
    ("<p><b>Нереалізований</b> — оцінка поточної позиції на біржі.</p>", "<p><b>Unrealized</b> — mark-to-market of open position on the exchange.</p>"),
    ("<p class=\"muted\">Не є гарантією майбутнього результату.</p>", "<p class=\"muted\">Not a guarantee of future results.</p>"),
    ("<p>Якщо пробний період і оплачений термін минули, акаунт переходить у статус <b>EXPIRED</b>:</p>",
     "<p>If trial and paid period have ended, the account becomes <b>EXPIRED</b>:</p>"),
    ("<li>Кнопки <b>Start / Pause / Stop</b> та швидкий старт — <b>недоступні</b>.</li>",
     "<li><b>Start / Pause / Stop</b> and quick start — <b>unavailable</b>.</li>"),
    ("<li>Усі активні стратегії автоматично переводяться в <b>паузу</b>.</li>",
     "<li>All active strategies are set to <b>pause</b>.</li>"),
    ("<li>Бот може <b>закрити відкриті позиції</b> за правилами TP (wind-down), але <b>не відкриває новий цикл</b> (BUY0, докупки).</li>",
     "<li>Bot may <b>close open positions</b> per TP rules (wind-down) but <b>does not open new cycles</b> (BUY0, DCA).</li>"),
    ("<li>Перегляд кабінету та оплата тарифу — доступні. Після оплати натисніть <b>Start</b>.</li>",
     "<li>Dashboard view and payment remain available. After payment press <b>Start</b>.</li>"),
    ("<p class=\"muted\">Деталі: <a href=\"/offer.html\">оферта</a>, тарифи — <a href=\"/en/tariffs.html?v=20260512\">оплата</a>.</p>",
     "<p class=\"muted\">Details: <a href=\"/offer.html\">terms</a>, <a href=\"/en/tariffs.html\">pricing</a>.</p>"),
    ("<p>StrIM — інструмент автоматизації спот-торгівлі, не інвестиційна порада.</p>",
     "<p>StrIM automates spot trading; it is not investment advice.</p>"),
    ("<p>Детальніше: <a href=\"/en/risks.html\">Ризики</a>, <a href=\"/offer.html\">Оферта</a>.</p>",
     "<p>More: <a href=\"/en/risks.html\">Risks</a>, <a href=\"/offer.html\">Terms</a>.</p>"),
    ("<p>Напишіть на <a href=\"mailto:support@strim.in.ua\">support@strim.in.ua</a> або в Telegram-підтримку (посилання в кабінеті).</p>",
     "<p>Email <a href=\"mailto:support@strim.in.ua\">support@strim.in.ua</a> or Telegram support (link in dashboard).</p>"),
    ('<script src="/assets/js/strim-public-nav.js', '<script src="/assets/js/strim-public-nav.js'),
]
if '<script src="/assets/js/strim-lang-switch.js' not in t:
    t = t.replace('</body>', '  <script src="/assets/js/strim-lang-switch.js?v=20260602" defer></script>\n</body>')
for a,b in pairs:
    t = t.replace(a,b)
p.write_text(t, encoding="utf-8")
print("ok faq")
