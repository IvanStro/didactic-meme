# SEO та Google Search Console — strim.in.ua

Інструкція: що вже зроблено в коді, що перевірити після деплою, і **що може зробити лише власник** акаунтів (GSC, хостинг, `.env`).

---

## Що зроблено автоматично (код / деплой)

| Зміна | Файл | Ефект |
|-------|------|--------|
| **301 www → apex** | `www/.htaccess` | `https://www.strim.in.ua/*` → `https://strim.in.ua/*` |
| **HSTS** | `www/.htaccess` | Браузер пам’ятає HTTPS 1 рік |
| **canonical login/register** | `www/auth/login.php`, `register.php` | Google не плутає URL з `?gclid=` / `?next=` |
| **noindex forgot/reset** | `forgot_password.php`, `reset_password.php` | Службові сторінки не потрапляють у індекс |
| **Закритий health** | `www/api/_health_access.php`, `health.php` | З інтернету без токена → **403** |
| **curl helper** | `run/curl_health.sh` | Перевірка health з сервера з токеном |

---

## Що може зробити лише ви

### 1. HEALTH_TOKEN на продакшені (обов’язково, ~5 хв)

Після деплoy `health.php` з інтернету віддає 403. Для моніторингу і deploy-скриптів потрібен секрет.

**На сервері** (`/home/bot20/strim.in.ua/.env`):

```bash
# згенеруйте токен (локально або на сервері):
openssl rand -hex 24
```

Додайте рядок у `.env`:

```
HEALTH_TOKEN=ваш_48_символів_hex
```

**Перевірка з сервера:**

```bash
cd /home/bot20/strim.in.ua
bash run/curl_health.sh | head -c 200
```

**З браузера (закладка для адміна, опційно):**

```
https://strim.in.ua/api/health.php?token=ВАШ_ТОКЕН
```

> Не публікуйте URL з токеном і не комітьте `.env` у git.

**Без токена з інтернету:**

```bash
curl -sI https://strim.in.ua/api/health.php   # → 403
```

---

### 2. Google Search Console — індексація (~15–20 хв)

Потрібен Google-акаунт, до якого прив’язаний домен (TXT у DNS уже є).

#### Крок 1. Відкрити GSC

1. Перейдіть на [Google Search Console](https://search.google.com/search-console).
2. Оберіть ресурс **`strim.in.ua`** (Domain або URL prefix — той, що верифікований).

#### Крок 2. Надіслати sitemap

1. Меню зліва: **«Файли Sitemap»** / **Sitemap**.
2. У полі «Додати новий sitemap» введіть: `sitemap.xml`
3. Натисніть **Надіслати**.

Очікуваний URL: `https://strim.in.ua/sitemap.xml` (12 сторінок).

#### Крок 3. Запросити індексацію ключових URL

1. Вгорі — **«Перевірка URL»** / **URL inspection**.
2. По черзі вставте і натисніть **«Запросити індексування»** / **Request indexing**:

   - `https://strim.in.ua/`
   - `https://strim.in.ua/tariffs.html`
   - `https://strim.in.ua/about.html`
   - `https://strim.in.ua/instruction.html`
   - `https://strim.in.ua/auth/register.php`

3. Для кожного дочекайтесь статусу «Запит на індексування надіслано».

> Повна індексація може зайняти **від кількох днів до 2–4 тижнів** для нового домену.

#### Крок 4. Перевірити помилку «Копія без канонічної»

1. **«Сторінки»** / **Pages** → переглянути попередження.
2. Після canonical на login/register і 301 www→apex натисніть **«Validate fix»** / **Перевірити виправлення** біля відповідного issue.
3. Через 3–7 днів Google має підтвердити або закрити попередження.

#### Крок 5. Моніторинг (раз на тиждень)

- **Сторінки** — скільки URL у Google.
- **Ефективність** — покази/кліки (поки можуть бути нульові).
- Пошук у Google: `site:strim.in.ua` — коли з’являться результати, індексація працює.

---

### 3. Панель хостингу Hosting Ukraine (опційно, ~5 хв)

Якщо в панелі є **«Основний домен»** / **Primary domain**:

- Встановіть **`strim.in.ua`** (без www) як основний.
- Переконайтесь, що SSL покриває обидва імена (Let's Encrypt уже має SAN для `strim.in.ua` + `www`).

Редirect www уже в `.htaccess`; панель — додаткова страховка.

---

### 4. Google Ads і Search Console — зв’язок (опційно)

1. [Google Ads](https://ads.google.com) → **Інструменти** → **Пов’язані акаунти**.
2. Пов’язати з Search Console — краща аналітика пошукових запитів у Ads.

---

## Перевірка після деплoy (можете самі або через SSH)

```bash
# www → apex
curl -sI https://www.strim.in.ua/ | grep -i location
# Очікується: Location: https://strim.in.ua/

# HSTS
curl -sI https://strim.in.ua/ | grep -i strict-transport
# Очікується: Strict-Transport-Security: max-age=31536000; includeSubDomains

# health закритий
curl -s https://strim.in.ua/api/health.php
# Очікується: {"ok":false,"error":"forbidden"}

# canonical login
curl -sL https://strim.in.ua/auth/login.php | grep canonical
# Очікується: href="https://strim.in.ua/auth/login.php"
```

---

## Чого не робити

- Не додавайте `health.php` у публічні монітори (UptimeRobot тощо) без токена — використовуйте `curl_health.sh` з cron на сервері.
- Не видаляйте `login`/`register` з sitemap без причини — вони потрібні для рекламного funnel.
- Не force-push і не публікуйте `HEALTH_TOKEN` у чатах/issue.

---

## Контакти / підтримка

Якщо через 2 тижні `site:strim.in.ua` все ще порожній — перевірте в GSC **«Сторінки» → «Не проіндексовано»** і надішліть скрін; типові причини: новий домен, manual action (рідко), блок robots (у нас Allow: /).
