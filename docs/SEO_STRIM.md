# SEO для strim.in.ua — практичний план

**Мета:** з’являтися в Google за запитами на кшталт «бот binance spot», «торговий бот україна», «strim binance», «автоматизація спот binance».

**Реалістично:** жоден сайт не потрапляє «по будь-якому» слову лише від favicon і sitemap. Потрібні **релевантний текст**, **індексація**, **час** (1–3 міс.) і/або **Google Ads** для миттєвого трафіку.

**AI / LLM видимість:** див. [AI_DISCOVERABILITY_UA.md](./AI_DISCOVERABILITY_UA.md) — `llms.txt`, `for-ai.html`, robots для GPTBot/ClaudeBot/Perplexity.

---

## 1. Технічна база (зроблено в репо)

| Що | Файл / URL |
|----|------------|
| Favicon | `/favicon.ico` |
| Sitemap | `/sitemap.xml` |
| Robots | `/robots.txt` |
| Canonical + OG | `index.html` |
| Schema.org | JSON-LD на головній, FAQ на `faq.html` |
| Швидкість | gzip, стиснуті зображення |

---

## 2. Обов’язково вручну (Google)

### Search Console
1. https://search.google.com/search-console  
2. Додати ресурс **обидва**: `https://strim.in.ua` і `https://www.strim.in.ua`  
3. Підтвердити домен (DNS TXT у ADM.TOOLS)  
4. **Сitemaps** → надіслати `https://strim.in.ua/sitemap.xml`  
5. **Налаштування** → вказати бажаний домен (www або без www — один варіант)  
6. Після змін на сайті: **Перевірка URL** → «Запросити індексування»

### Відрізнитися від «STRIM Інтернет Козин»
- У профілі Google Business (якщо створюєте) — категорія **Software / SaaS**, не ISP  
- На сайті чітко: **ТОВ «СтрІМ Алго»**, торгівля Binance, не провайдер  
- У title/description слова: **Binance spot**, **кабінет**, **бот**

### Google Ads (швидкий результат)
- Вже є тег AW-18167666446  
- Кампанії на: `strim`, `strim binance`, `бот binance spot`, `торговий бот binance україна`  
- Посадкова: `https://strim.in.ua/` або `/tariffs.html`

---

## 3. Ключові запити (кластери)

Пишіть окремі блоки тексту / сторінки під кластер:

| Кластер | Приклади запитів | Де закрити |
|---------|------------------|------------|
| Бренд | strim, стрім алго, strim.in.ua | Головна, title |
| Binance spot | бот binance spot, автоматизація спот | Головна, FAQ, інструкція |
| Україна | торговий бот україна, saas торгівля | Головна, about |
| API / старт | binance api бот, як підключити api | instruction.html |
| Ціна | strim ціна, підписка 500 грн | tariffs.html |
| Безпека | api без виводу, spot only | instruction, risks |

**Не використовуйте:** «гарантований прибуток», «без ризику» — Google і реклама карають.

---

## 4. Контент (найсильніший важіль)

1. **FAQ** — розширюйте питання з реальних листів support@  
2. **Інструкція** — покроково з скріншотами Binance API  
3. **1–2 статті на місяць** (можна `/blog/` або розділ на головній):  
   - «Як працює сітка на споті Binance»  
   - «Чим StrIM відрізняється від RoyalQ / 3Commas» (обережно, без наклепу)  
4. **Внутрішні посилання** — з головної на tariffs, instruction, faq (вже є в меню)

---

## 5. Зовнішні фактори

- Посилання з **YouTube / Telegram** каналу про StrIM  
- Згадка на **forum.binance.com** (обережно з правилами)  
- Профіль компанії: **LinkedIn**, **Facebook** ТОВ «СтрІМ Алго» → посилання на strim.in.ua  
- Каталоги SaaS / fintech UA (якщо є безкоштовні)

---

## 6. Моніторинг

| Інструмент | Що дивитись |
|------------|-------------|
| Search Console | Запити, покази, CTR, індексація |
| PageSpeed Insights | LCP, мобільна версія |
| `site:strim.in.ua` у Google | скільки сторінок в індексі |

---

## 7. Чеклист на тиждень

- [ ] Search Console + sitemap  
- [ ] Обрати www vs non-www  
- [ ] Запросити індексацію головної, tariffs, instruction, faq  
- [ ] Перевірити `site:strim.in.ua`  
- [ ] Запустити/оновити Google Ads на 5–10 ключів  
- [ ] Додати 2–3 питання в FAQ з реальних користувачів  

---

*Оновлено 2026-05-27. Технічні зміни: JSON-LD, meta description на публічних сторінках.*
