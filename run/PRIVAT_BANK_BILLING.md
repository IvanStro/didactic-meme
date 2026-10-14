# Privat Autoclient — автоматизація банківської оплати (500 UAH / +30 днів)

## .env (на проді `/home/bot20/strim.in.ua/.env`)

```env
# Тільки token (без UUID на початку). Кнопка «ID i Token» дає два поля — не склеюйте в один рядок.
PRIVAT_ACP_TOKEN=ваш_token
# UUID з кнопки «ID i Token» — передається в HTTP-заголовок id: (обовʼязково).
PRIVAT_ACP_CLIENT_ID=c892431e-e870-49bd-b790-ff04aaec0814
# Лише для режиму групи ПП: числовий id у ?id= (рідко потрібен; зазвичай порожньо).
# PRIVAT_ACP_GROUP_CLIENT_ID=
PRIVAT_ACP_IBAN=UA343052990000026003026120038
PRIVAT_ACP_USER_AGENT=StrIM-in.ua-billing/1.0

# 1 = після матчу одразу +30 днів (прод з 2026-09-15)
PRIVAT_ACP_AUTO_EXTEND=1
# PRIVAT_ACP_DRY_RUN=1

PRIVAT_ACP_AMOUNT_UAH=500
PRIVAT_ACP_AMOUNT_TOLERANCE=2
```

`PRIVAT_ACP_AUTO_EXTEND=1` + `PRIVAT_ACP_DRY_RUN` не `1` — повний автоматизм: виписка → матч по `ref_code`/`id:N` → `paid_until +30`.
Pending `matched` за останні 14 днів добиваються на кожному poll (backfill).

У кабінеті API Autoclient увімкніть **лише** «Отримання балансів і транзакцій за рахунками».  
IP сервера додайте в whitelist додатку API.

## Міграція

```bash
cd /home/bot20/strim.in.ua
/usr/local/php84/bin/php run/apply_privat_bank_migration.php
```

## Ручний запуск

```bash
/usr/local/php84/bin/php run/privat_poll_transactions.php 3
```

## Cron (ADM.TOOLS, кожні 10 хв)

```text
/usr/local/php84/bin/php /home/bot20/strim.in.ua/run/privat_poll_transactions.php 3 >> /home/bot20/strim.in.ua/logs/privat_bank_poll.log 2>&1
```

## Як матчиться користувач

У призначенні платежу (`OSND`) з QR StrIM:

- `ref_code` користувача (пріоритет), або
- `id:18` якщо ref порожній

Сума: **500 ± 2 UAH**, `TRANTYPE=C` (надходження).

Ідемпотентність: `REF` + `REFN` у `bank_incoming_payments`.
