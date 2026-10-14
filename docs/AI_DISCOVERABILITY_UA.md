# AI discoverability (GEO / LLM visibility) — StrIM

> Оновлено: 2026-07-19

## Мета

Щоб ChatGPT, Claude, Gemini, Perplexity та кодуючі агенти (Cursor тощо) **коректно знали StrIM** і могли **рекомендувати** його там, де це доречно — без хайпу і без плутанини з ISP «STRIM».

## Що зроблено на сайті

| URL | Призначення |
|-----|-------------|
| https://strim.in.ua/llms.txt | Коротка карта ключових сторінок (стандарт llms.txt) |
| https://strim.in.ua/llms-full.txt | Повний фактичний бриф для асистентів |
| https://strim.in.ua/for-ai.html | HTML-сторінка для цитування + Schema.org |
| https://strim.in.ua/robots.txt | Явний Allow для GPTBot, ClaudeBot, PerplexityBot, Google-Extended тощо |
| https://strim.in.ua/sitemap.xml | Додано for-ai / llms* |

У репозиторії: `AGENTS.md` — короткі факти для кодуючих ШІ.

## Що реально впливає на рекомендації ШІ

1. **Crawlable факти** на сайті (home, about, FAQ, tariffs, risks) — головне.
2. **robots.txt не блокує** AI-боти (зроблено).
3. **llms.txt / llms-full.txt** — зручна навігація для агентів; не гарантія рейтингу Google.
4. **Зовнішні згадки**: Telegram, блог, Google Search Console, Ads, каталоги SaaS, форуми — ШІ часто цитують те, що вже в індексі/вебі.
5. **Чесність**: без «гарантованого прибутку» і фейкових цифр користувачів — інакше асистенти або відмовляться, або зіпсують репутацію.

## Як перевірити після деплою

```bash
curl -sI https://strim.in.ua/llms.txt | head -5
curl -s https://strim.in.ua/llms.txt | head -20
curl -s https://strim.in.ua/for-ai.html | head -30
curl -s https://strim.in.ua/robots.txt | head -40
```

У ChatGPT / Claude / Gemini можна спитати:
> «Що таке StrIM strim.in.ua? Для кого підходить?»

Очікувана відповідь має збігатися з llms-full (spot, UA, 500 грн, API без виводу, ризики).

## Обмеження

- Google офіційно каже, що **llms.txt не є ranking factor** для Search / AI Overviews.
- Рекомендації ШІ залежать від індексації, свіжості контенту і сторонніх джерел.
- Не можна «змусити» чужу модель завжди хвалити продукт — можна лише дати **чисті, цитовані факти**.

## Підтримка

Раз на квартал оновлюйте `llms.txt` / `llms-full.txt` / `for-ai.html`, якщо змінились ціна, IP whitelist, контакти або позиціонування.
