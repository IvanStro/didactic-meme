# Блог StrIM (`/blog/`)

## Файли

| Файл | Призначення |
|------|-------------|
| `index.php` | Каталог статей (читає `articles.json`) |
| `index.html` | Статична копія каталогу (для SEO/резерву) |
| `articles.json` | Метадані статей для каталогу та sitemap |
| `article-template.html` | Шаблон нової статті — копіюйте й заповнюйте плейсхолдери |
| `*.html` | Окремі опубліковані статті |

## Нова стаття

1. Скопіюйте `article-template.html` → `slug-statti.html` (латиниця, дефіси).
2. Замініть плейсхолдери в `<title>`, `description`, `canonical`, breadcrumbs, H1 та контенті.
3. Додайте запис у `articles.json` (`slug` без `.html`).
4. Запустіть `php run/generate_sitemap.php` і задеплойте `www/blog/` + `www/sitemap.xml`.

## Стилі

Підключайте разом із `strim-public.css`:

```html
<link rel="stylesheet" href="/assets/css/strim-blog.css?v=20260602">
```
