# Бухгалтерія StrIM (адмін)

Кабінет бухгалтера: `/admin/accounting.php` (доступ лише `is_admin=1`).

## Що покриває MVP

- **Єдиний реєстр надходжень** з трьох джерел:
  - ПриватБанк (`bank_incoming_payments`, дохід — статус `applied`)
  - WhitePay (`whitepay_orders`, status `paid`)
  - Ручне продовження адміном (`admin_action_audit`, `billing_add30`)
- **KPI за місяць**: сума доходу, кількість платежів, оцінка єдиного податку (5% за замовч.), невирішені банківські рядки
- **Експорт CSV** для передачі бухгалтеру (UTF-8 BOM, роздільник `;`)
- **Звірка з ПриватБанком**: опит API, ручне застосування / ігнор платежу
- **Google Sheets** (опційно): webhook на кожне підтверджене надходження

## Юридичні реквізити (за замовч.)

| Поле | Значення |
|------|----------|
| Назва | ТОВ «СтрІМ Алго» |
| ЄДРПОУ | 46241790 |
| IBAN | UA343052990000026003026120038 |
| Тариф | 500 UAH / 30 днів, без ПДВ |
| ЄП | група 3, ставка 5% (оцінка) |

Перевизначення через `.env`: `ACCOUNTING_LEGAL_NAME`, `ACCOUNTING_EDRPOU`, `ACCOUNTING_IBAN`, `ACCOUNTING_BANK_NAME`, `ACCOUNTING_TAX_GROUP`, `ACCOUNTING_TAX_SYSTEM`, `ACCOUNTING_TAX_RATE`, `ACCOUNTING_SUBSCRIPTION_UAH`, `ACCOUNTING_VAT_MODE`.

## API

`GET/POST /auth/api_gate.php?path=admin_accounting.php`

| action | Опис |
|--------|------|
| `profile` | Реквізити + статус інтеграцій |
| `stats` | Зведення за місяць (`month=YYYY-MM`) |
| `list` | Реєстр (фільтри: `date_from`, `date_to`, `source`, `q`, `limit`, `offset`) |
| `export` | Завантаження CSV |
| `pending_bank` | Невирішені банківські рядки |
| `privat_poll` | POST: опит ПриватБанку |
| `privat_apply` | POST: `bank_id`, `user_id` — +30 днів |
| `privat_ignore` | POST: `bank_id`, `note` |

## Файли

- `www/admin/accounting.php` — UI
- `www/api/admin_accounting.php` — API
- `www/api/_accounting.php` — логіка реєстру, CSV, Privat apply/ignore
- `www/api/_billing_extend.php` — викликає `accounting_notify_income()` для WhitePay / Privat
- `www/api/admin_billing.php` — `accounting_notify_income()` для ручного add30

## Календар звітності

Вкладка **Календар звітності** в `/admin/accounting.php`:

- Строки для ТОВ ЄП 3 група (J0103509, військовий збір, фінзвітність 1-мс/2-мс)
- Додаткові звіти при наявності працівників (ЄСВ, 4-ДФ, обʼєднаний звіт)
- Позначка «Виконано» зберігається в `accounting_compliance_settings`
- Перенос дедлайнів з вихідних (святкові дні — уточнювати вручну)

Міграція: `run/apply_accounting_compliance_migration.php`

## ПриватБанк і подання в ДПС

| Канал | Можливість |
|-------|------------|
| **Приват24 UI** | Повний цикл: календар, формування, КЕП, надсилання в ДПС/Держстат |
| **Autoclient API** | Завантаження готового XML (`/api/proxy/eo/documents/create`) — потрібен окремий дозвіл API |
| **StrIM зараз** | Лише виписка/вхідні платежі; звітність не автоматизована |

Повна автоматизація «StrIM → ДПС» потребує: генератор XML за форматами ДПС, КЕП директора, етап підпису в Приват24. Рекомендовано: StrIM дає дохід/оцінку ЄП → бухгалтер подає через Приват24 або M.E.Doc.

Деталі: `accounting_compliance_privat_integration_research()` у `_accounting_compliance.php`.
