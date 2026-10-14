<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../auth/_session_user.php';
require_once __DIR__ . '/../api/_csrf.php';
require_once __DIR__ . '/../api/_accounting.php';

Dotenv\Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();

$mysqli = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
$mysqli->set_charset('utf8mb4');

$uid = current_user_id($mysqli);
if ($uid <= 0) {
    header('Location: /auth/login.php?next=' . urlencode('/admin/accounting.php'), true, 302);
    exit;
}
$admRes = $mysqli->query('SELECT is_admin FROM users WHERE id=' . (int)$uid . ' LIMIT 1');
$admRow = $admRes ? $admRes->fetch_assoc() : null;
if (!$admRow || (int)($admRow['is_admin'] ?? 0) !== 1) {
    http_response_code(403);
    echo '403 Forbidden';
    exit;
}

$csrfToken = ensure_csrf_token();
$csrfJson = json_encode($csrfToken, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
$company = accounting_company_profile();
$monthDefault = accounting_default_activity_month($mysqli);

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!doctype html>
<html lang="uk">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Бухгалтерія — StrIM Admin</title>
  <link rel="stylesheet" href="/admin/admin-panel.css?v=20260529">
</head>
<body class="adm-body">
<div class="adm-wrap">
  <header class="adm-header">
    <div>
      <h1>Бухгалтерія StrIM</h1>
      <p>Реєстр надходжень, ПриватБанк API, звітність ДПС, ЕДО та календар строків (Україна)</p>
    </div>
    <nav class="adm-nav-actions">
      <a class="adm-btn" href="/admin/users.php">← Адмін</a>
      <a class="adm-btn" href="/dashboard.php">Кабінет</a>
      <a class="adm-btn" href="/auth/logout.php">Вихід</a>
    </nav>
  </header>

  <div class="adm-kpis" id="accKpis">
    <div class="adm-kpi"><div class="label" id="kpiRevenueLabel">Дохід (квартал)</div><div class="value" id="kpiRevenue">…</div></div>
    <div class="adm-kpi"><div class="label">Платежів (квартал)</div><div class="value" id="kpiCount">…</div></div>
    <div class="adm-kpi"><div class="label">ЄП + ВЗ (квартал)</div><div class="value" id="kpiTax">…</div></div>
    <div class="adm-kpi"><div class="label">Банк (не застосовано)</div><div class="value" id="kpiPending">…</div></div>
  </div>
  <p class="adm-muted" style="margin:-8px 0 12px;font-size:0.82rem" id="kpiHint"></p>

  <details class="adm-card adm-acc" open>
    <summary>Юридична особа та тариф</summary>
    <div class="adm-card__body">
      <div class="adm-grid-2">
        <div><span class="adm-muted">Назва</span><br><strong><?= h($company['legal_name']) ?></strong></div>
        <div><span class="adm-muted">ЄДРПОУ</span><br><strong class="adm-mono"><?= h($company['edrpou']) ?></strong></div>
        <div><span class="adm-muted">IBAN</span><br><strong class="adm-mono"><?= h($company['iban']) ?></strong></div>
        <div><span class="adm-muted">Банк</span><br><?= h($company['bank_name']) ?></div>
        <div><span class="adm-muted">Група ЄП</span><br><?= h($company['tax_group']) ?> — <?= h($company['tax_system']) ?></div>
        <div><span class="adm-muted">ПДВ</span><br><?= h($company['vat_label']) ?></div>
        <div><span class="adm-muted">Тариф підписки</span><br><?= h(number_format((float)$company['subscription_uah'], 2, '.', ' ')) ?> UAH / 30 днів</div>
        <div><span class="adm-muted">Послуга</span><br><?= h($company['service_description']) ?></div>
      </div>
      <p class="adm-muted" style="margin:12px 0 0;font-size:0.82rem">
        Інтеграції: <span id="intPrivat">ПриватБанк …</span> · <span id="intWhitepay">WhitePay …</span> · <span id="intSheets">Google Sheets …</span>
      </p>
    </div>
  </details>

  <nav class="adm-tabs" role="tablist">
    <button type="button" class="adm-tab is-active" data-tab="ledger">Реєстр платежів</button>
    <button type="button" class="adm-tab" data-tab="bank">Банк (звірка) <span class="badge" id="bankTabBadge" hidden>0</span></button>
    <button type="button" class="adm-tab" data-tab="privat">ПриватБанк API</button>
    <button type="button" class="adm-tab" data-tab="dps">Звітність ДПС</button>
    <button type="button" class="adm-tab" data-tab="edo">ЕДО</button>
    <button type="button" class="adm-tab" data-tab="calendar">Календар звітності <span class="badge badge--warn" id="calTabBadge" hidden>0</span></button>
    <button type="button" class="adm-tab" data-tab="settings">Налаштування</button>
    <button type="button" class="adm-tab" data-tab="help">Довідка</button>
  </nav>

  <section id="tab-ledger" class="adm-panel is-active" role="tabpanel">
    <div class="adm-card">
      <div class="adm-card__body">
        <div class="adm-card__tools adm-filters">
          <label>Місяць <input type="month" id="fltMonth" value="<?= h($monthDefault) ?>"></label>
          <label>З <input type="date" id="fltFrom"></label>
          <label>По <input type="date" id="fltTo"></label>
          <label>Джерело
            <select id="fltSource">
              <option value="all">Усі</option>
              <option value="privat_bank">ПриватБанк</option>
              <option value="whitepay">WhitePay</option>
              <option value="admin_manual">Адмін (вручну)</option>
            </select>
          </label>
          <label>Пошук <input type="search" id="fltQ" placeholder="email, ref, призначення…"></label>
          <button class="adm-btn adm-btn--primary" type="button" id="btnReload">Оновити</button>
          <button class="adm-btn" type="button" id="btnExport">CSV для бухгалтерії</button>
        </div>
        <div class="adm-table-wrap">
          <table class="adm-table" id="ledgerTable">
            <thead>
              <tr>
                <th>Дата</th>
                <th>Джерело</th>
                <th>Сума</th>
                <th>Клієнт</th>
                <th>Платник</th>
                <th>Статус</th>
                <th>Ref / TX</th>
              </tr>
            </thead>
            <tbody id="ledgerBody"><tr><td colspan="7" class="adm-muted">Завантаження…</td></tr></tbody>
          </table>
        </div>
        <div class="adm-card__tools">
          <button class="adm-btn" type="button" id="btnMore" hidden>Ще</button>
          <span class="adm-muted" id="ledgerMsg"></span>
        </div>
      </div>
    </div>
  </section>

  <section id="tab-bank" class="adm-panel" role="tabpanel" hidden>
    <div class="adm-card">
      <div class="adm-card__body">
        <div class="adm-card__tools">
          <button class="adm-btn adm-btn--primary" type="button" id="btnPrivatPoll">Опитати ПриватБанк</button>
          <span class="adm-muted" id="pollMsg"></span>
        </div>
        <div class="adm-table-wrap">
          <table class="adm-table">
            <thead>
              <tr>
                <th>Дата</th>
                <th>Сума</th>
                <th>Платник</th>
                <th>Призначення</th>
                <th>Статус</th>
                <th>User</th>
                <th></th>
              </tr>
            </thead>
            <tbody id="bankBody"><tr><td colspan="7" class="adm-muted">Завантаження…</td></tr></tbody>
          </table>
        </div>
      </div>
    </div>
  </section>

  <section id="tab-privat" class="adm-panel" role="tabpanel" hidden>
    <div class="adm-card">
      <div class="adm-card__body">
        <p class="adm-muted">Перевірка Autoclient API (ті самі ключі <code>PRIVAT_ACP_*</code>). Після увімкнення дозволів у Приват24 для бізнесу.</p>
        <div class="adm-card__tools">
          <button class="adm-btn adm-btn--primary" type="button" id="btnPrivatProbe">Перевірити API</button>
          <button class="adm-btn" type="button" id="btnPrivatBalance">Оновити баланс</button>
          <span class="adm-muted" id="privatProbeMsg"></span>
        </div>
        <div class="adm-grid-2" id="privatServices" style="margin:12px 0"></div>
        <div class="adm-table-wrap">
          <table class="adm-table">
            <thead>
              <tr>
                <th>Рахунок</th>
                <th>Валюта</th>
                <th>Залишок</th>
                <th>Доступно</th>
                <th>Оновлено</th>
              </tr>
            </thead>
            <tbody id="privatBalanceBody"><tr><td colspan="5" class="adm-muted">Натисніть «Оновити баланс»</td></tr></tbody>
          </table>
        </div>
      </div>
    </div>
  </section>

  <section id="tab-dps" class="adm-panel" role="tabpanel" hidden>
    <div class="adm-card">
      <div class="adm-card__body">
        <p class="adm-muted">Квартальна декларація ЄП (J0103509) — розрахунок з реєстру StrIM. XML завантажується в Приват24; підпис і відправка в ДПС — у кабінеті банку.</p>
        <div class="adm-card__tools adm-filters">
          <label>Рік <input type="number" id="dpsYear" min="2020" max="2035" value="<?= (int)date('Y') ?>"></label>
          <label>Квартал
            <select id="dpsQuarter">
              <?php $cq = (int)ceil((int)date('n') / 3); for ($q = 1; $q <= 4; $q++): ?>
              <option value="<?= $q ?>"<?= $q === $cq ? ' selected' : '' ?>><?= $q ?> кв.</option>
              <?php endfor; ?>
            </select>
          </label>
          <button class="adm-btn adm-btn--primary" type="button" id="btnDpsPreview">Розрахувати</button>
          <button class="adm-btn" type="button" id="btnDpsExport">Пакет для бухгалтера (.txt)</button>
          <a class="adm-btn" href="https://24.privatbank.ua/" target="_blank" rel="noopener">Приват24 → Е-звітність</a>
        </div>
        <div class="adm-kpis" id="dpsSummary" style="margin-bottom:12px">
          <div class="adm-kpi"><div class="label">Дохід (квартал)</div><div class="value" id="dpsRevenue">—</div></div>
          <div class="adm-kpi"><div class="label">ЄП</div><div class="value" id="dpsEp">—</div></div>
          <div class="adm-kpi"><div class="label">Військовий збір</div><div class="value" id="dpsMil">—</div></div>
          <div class="adm-kpi"><div class="label">Дедлайн подання</div><div class="value" id="dpsDeadlineFile" style="font-size:1rem">—</div></div>
        </div>
        <p class="adm-muted" id="dpsBreakdown" style="margin-bottom:12px"></p>
        <details class="adm-card adm-acc" open>
          <summary>Завантажити XML у ПриватБанк (API)</summary>
          <div class="adm-card__body">
            <form id="eoUploadForm">
              <label>Файл XML <input type="file" name="xml_file" accept=".xml,application/xml,text/xml"></label>
              <p class="adm-muted">Або вставте XML нижче (якщо згенеровано зовні):</p>
              <textarea name="xml_content" rows="6" style="width:100%;font-family:monospace;font-size:0.8rem" placeholder="&lt;?xml version=&quot;1.0&quot; ..."></textarea>
              <input type="hidden" name="year" id="eoUploadYear">
              <input type="hidden" name="quarter" id="eoUploadQuarter">
              <div class="adm-card__tools" style="margin-top:8px">
                <button class="adm-btn adm-btn--primary" type="submit">Надіслати в Приват24</button>
                <span class="adm-muted" id="eoUploadMsg"></span>
              </div>
            </form>
          </div>
        </details>
        <h4 style="margin:16px 0 8px">Журнал завантажень</h4>
        <div class="adm-table-wrap">
          <table class="adm-table">
            <thead>
              <tr>
                <th>Дата</th>
                <th>Тип</th>
                <th>Файл</th>
                <th>Період</th>
                <th>Статус</th>
                <th>Ref</th>
              </tr>
            </thead>
            <tbody id="dpsDocsBody"><tr><td colspan="6" class="adm-muted">Завантаження…</td></tr></tbody>
          </table>
        </div>
      </div>
    </div>
  </section>

  <section id="tab-edo" class="adm-panel" role="tabpanel" hidden>
    <div class="adm-card">
      <div class="adm-card__body">
        <p class="adm-muted">Електронний документообіг ПриватБанку (вхідні / вихідні / в обробці).</p>
        <div class="adm-card__tools adm-filters">
          <label>Журнал
            <select id="edoJournal">
              <option value="all">Усі</option>
              <option value="inbox">Вхідні</option>
              <option value="outbox">Вихідні</option>
              <option value="in-process">В обробці</option>
              <option value="to-pay">До оплати</option>
            </select>
          </label>
          <label>З <input type="date" id="edoFrom" value="<?= h(date('Y-m-d', strtotime('-90 days'))) ?>"></label>
          <label>По <input type="date" id="edoTo" value="<?= h(date('Y-m-d')) ?>"></label>
          <button class="adm-btn adm-btn--primary" type="button" id="btnEdoReload">Оновити</button>
          <span class="adm-muted" id="edoMsg"></span>
        </div>
        <div class="adm-table-wrap">
          <table class="adm-table">
            <thead>
              <tr>
                <th>Дата</th>
                <th>Номер</th>
                <th>Контрагент</th>
                <th>Сума</th>
                <th>Статус</th>
                <th>Тип</th>
              </tr>
            </thead>
            <tbody id="edoBody"><tr><td colspan="6" class="adm-muted">Оберіть журнал і натисніть «Оновити»</td></tr></tbody>
          </table>
        </div>
      </div>
    </div>
  </section>

  <section id="tab-calendar" class="adm-panel" role="tabpanel" hidden>
    <div class="adm-card">
      <div class="adm-card__body">
        <div class="adm-card__tools adm-filters">
          <label>Рік <input type="number" id="calYear" min="2020" max="2035" value="<?= (int)date('Y') ?>"></label>
          <button class="adm-btn adm-btn--primary" type="button" id="btnCalReload">Оновити календар</button>
          <span class="adm-muted" id="calHint"></span>
        </div>
        <div class="adm-kpis" style="margin-bottom:12px" id="calStats">
          <div class="adm-kpi"><div class="label">Прострочено</div><div class="value" id="calOverdue">0</div></div>
          <div class="adm-kpi"><div class="label">До 14 днів</div><div class="value" id="calSoon">0</div></div>
        </div>
        <div class="acc-deadline-row" id="calTimerRow">
          <div class="acc-deadline-card" id="calTimerFile">
            <div class="acc-deadline-card__label">Подання звітності</div>
            <div class="acc-deadline-card__title" id="calTimerFileTitle">—</div>
            <div class="acc-deadline-card__date" id="calTimerFileDate"></div>
            <div class="acc-deadline-card__countdown" id="calTimerFileCountdown">—</div>
          </div>
          <div class="acc-deadline-card" id="calTimerPay">
            <div class="acc-deadline-card__label">Сплата внесків</div>
            <div class="acc-deadline-card__title" id="calTimerPayTitle">—</div>
            <div class="acc-deadline-card__date" id="calTimerPayDate"></div>
            <div class="acc-deadline-card__countdown" id="calTimerPayCountdown">—</div>
          </div>
        </div>
        <div class="adm-table-wrap">
          <table class="adm-table">
            <thead>
              <tr>
                <th>Дедлайн</th>
                <th>Залишилось</th>
                <th>Звіт / дія</th>
                <th>Орган</th>
                <th>Форма</th>
                <th>Період</th>
                <th>Сплата</th>
                <th>Статус</th>
                <th></th>
              </tr>
            </thead>
            <tbody id="calBody"><tr><td colspan="9" class="adm-muted">Завантаження…</td></tr></tbody>
          </table>
        </div>
        <details class="adm-card adm-acc" style="margin-top:14px">
          <summary>ПриватБанк і подання в ДПС (дослідження)</summary>
          <div class="adm-card__body adm-prose" id="privatResearch"></div>
        </details>
      </div>
    </div>
  </section>

  <section id="tab-settings" class="adm-panel" role="tabpanel" hidden>
    <div class="adm-card">
      <div class="adm-card__body">
        <p class="adm-muted">Профіль підприємства для розрахунку строків звітності. За замовчуванням: ТОВ «СтрІМ Алго», ЄП 3 група, мікропідприємство.</p>
        <form id="complianceForm" class="adm-grid-2" style="margin-top:12px">
          <label>Система оподаткування
            <select name="tax_system" id="setTaxSystem"></select>
          </label>
          <label>Категорія підприємства
            <select name="entity_size" id="setEntitySize"></select>
          </label>
          <label>Канал подання звітності
            <select name="reports_channel" id="setReportsChannel"></select>
          </label>
          <label>
            <input type="checkbox" name="vat_payer" id="setVatPayer"> Платник ПДВ
          </label>
          <label>
            <input type="checkbox" name="has_employees" id="setHasEmployees"> Є штатні працівники
          </label>
          <label>Кількість працівників
            <input type="number" name="employee_count" id="setEmployeeCount" min="0" max="9999" value="0">
          </label>
          <label>Бухгалтер (ПІБ)
            <input type="text" name="accountant_name" id="setAccountantName" maxlength="200">
          </label>
          <label>Email бухгалтера
            <input type="email" name="accountant_email" id="setAccountantEmail" maxlength="200">
          </label>
          <label>Телефон бухгалтера
            <input type="text" name="accountant_phone" id="setAccountantPhone" maxlength="40">
          </label>
          <label style="grid-column:1/-1">Примітки
            <textarea name="notes" id="setNotes" rows="3" style="width:100%"></textarea>
          </label>
        </form>
        <div class="adm-card__tools" style="margin-top:12px">
          <button class="adm-btn adm-btn--primary" type="button" id="btnSaveSettings">Зберегти налаштування</button>
          <span class="adm-muted" id="settingsMsg"></span>
        </div>
        <details class="adm-card adm-acc" style="margin-top:14px" open>
          <summary>Нормативна база (коротко)</summary>
          <div class="adm-card__body adm-prose">
            <div class="acc-deadline-row acc-deadline-row--compact" id="normativeTimers">
              <div class="acc-deadline-card">
                <div class="acc-deadline-card__label">Крайній термін подання</div>
                <div class="acc-deadline-card__title" id="normTimerFileTitle">Завантаження…</div>
                <div class="acc-deadline-card__date" id="normTimerFileDate"></div>
                <div class="acc-deadline-card__countdown" id="normTimerFileCountdown">—</div>
              </div>
              <div class="acc-deadline-card">
                <div class="acc-deadline-card__label">Крайній термін сплати</div>
                <div class="acc-deadline-card__title" id="normTimerPayTitle">Завантаження…</div>
                <div class="acc-deadline-card__date" id="normTimerPayDate"></div>
                <div class="acc-deadline-card__countdown" id="normTimerPayCountdown">—</div>
              </div>
            </div>
            <ul>
              <li><strong>ЄП 3 група (юрособа):</strong> декларація J0103509 щокварталу, 40 к.д. після кварталу; сплата ЄП +10 к.д. (ст. 296.3, 49.18.2, 295.3 ПКУ).</li>
              <li><strong>Військовий збір:</strong> 1% від доходу ЄП — разом із сплатою ЄП (п. 16-1 підрозд. 10 ПКУ).</li>
              <li><strong>Фінзвітність мікро:</strong> форми №1-мс, №2-мс річно до Держстату до 28.02 (Порядок №419).</li>
              <li><strong>ЄСВ / 4-ДФ / обʼєднаний звіт:</strong> якщо є працівники (Закон про ЄСВ, п. 176.2 ПКУ).</li>
            </ul>
            <p class="adm-muted">Календар орієнтовний: перенос лише з вихідних. Святкові дні не враховані — уточнюйте у бухгалтера.</p>
          </div>
        </details>
      </div>
    </div>
  </section>

  <section id="tab-help" class="adm-panel" role="tabpanel" hidden>
    <div class="adm-card">
      <div class="adm-card__body adm-prose">
        <h3>Що враховано в реєстрі</h3>
        <ul>
          <li><strong>ПриватБанк</strong> — вхідні перекази; у дохід входять <code>matched</code> і <code>applied</code> (гроші на рахунку).</li>
          <li><strong>WhitePay</strong> — оплачені крипто-інвойси (<code>paid</code>).</li>
          <li><strong>Адмін +30</strong> — лише продовження підписки в системі, <em>не рахується доходом</em> (щоб не дублювати банк і тестові кліки).</li>
        </ul>
        <h3>ПриватБанк API</h3>
        <p>Вкладки <strong>ПриватБанк API</strong>, <strong>Звітність ДПС</strong> та <strong>ЕДО</strong> використовують Autoclient API (<code>PRIVAT_ACP_TOKEN</code>, <code>PRIVAT_ACP_CLIENT_ID</code>). Потрібні дозволи в Приват24 для бізнесу: баланси, ЕДО, електронна звітність.</p>
        <p>Завантаження XML у ДПС через API лише доставляє файл у Приват24 — підпис КЕП і фінальна відправка в ДПС виконуються в кабінеті банку.</p>
        <h3>Експорт CSV</h3>
        <p>Кнопка «CSV для бухгалтерії» формує файл з колонками: дата, джерело, сума UAH, email, платник, призначення, TX, ЄДРПОУ, IBAN, оцінка єдиного податку, позначка «без ПДВ».</p>
        <h3>Google Sheets</h3>
        <p>Якщо в <code>.env</code> задано <code>GSHEETS_WEBHOOK_URL</code>, кожне підтверджене надходження (Приват, WhitePay, адмін) дублюється в таблицю через webhook.</p>
        <h3>Налаштування через .env</h3>
        <p><code>ACCOUNTING_LEGAL_NAME</code>, <code>ACCOUNTING_EDRPOU</code>, <code>ACCOUNTING_IBAN</code>, <code>ACCOUNTING_TAX_GROUP</code>, <code>ACCOUNTING_TAX_RATE</code> (за замовч. 0.05), <code>ACCOUNTING_SUBSCRIPTION_UAH</code> (500).</p>
        <p class="adm-muted">Пакет J0103509 (.txt) — дані з реєстру для бухгалтера; офіційний XML ДПС формується в M.E.Doc / Приват24 або зовнішнім сервісом.</p>
      </div>
    </div>
  </section>
</div>

<div class="modal" id="applyModal" onclick="if(event.target===this) closeApplyModal()">
  <div class="modalCard" style="width:min(420px,100%)">
    <h3>Застосувати банківський платіж</h3>
    <p class="adm-muted" id="applySummary"></p>
    <label>User ID <input type="number" id="applyUserId" min="1" step="1"></label>
    <div style="display:flex;gap:8px;margin-top:12px">
      <button class="adm-btn" type="button" onclick="closeApplyModal()">Скасувати</button>
      <button class="adm-btn adm-btn--primary" type="button" id="btnApplyConfirm">+30 днів і applied</button>
    </div>
    <p class="adm-muted" id="applyMsg"></p>
  </div>
</div>

<script>
const CSRF = <?= $csrfJson ?>;
let ledgerOffset = 0;
let ledgerHasMore = false;
let applyBankId = 0;

function adminApi(path, query) {
  let url = '/auth/api_gate.php?path=' + encodeURIComponent(String(path).replace(/^\//, ''));
  if (query && typeof query === 'object') {
    const qs = new URLSearchParams();
    Object.keys(query).forEach(k => qs.set(k, String(query[k])));
    const tail = qs.toString();
    if (tail) url += '&' + tail;
  }
  return url;
}

function fmtUah(v) {
  const n = Number(v);
  if (!isFinite(n)) return '—';
  return n.toLocaleString('uk-UA', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ₴';
}

function fmtDt(s) {
  if (!s) return '—';
  const d = new Date(String(s).replace(' ', 'T'));
  if (isNaN(d)) return s;
  return d.toLocaleString('uk-UA', { day: '2-digit', month: '2-digit', year: '2-digit', hour: '2-digit', minute: '2-digit' });
}

function statusBadge(st) {
  const s = String(st || '');
  let cls = 'badge--muted';
  if (s === 'applied' || s === 'paid' || s === 'ok' || s === 'matched') cls = '';
  if (s === 'ignored') cls = 'badge--muted';
  if (s === 'ambiguous' || s === 'new') cls = 'badge--warn';
  return '<span class="badge ' + cls + '">' + s + '</span>';
}

function filterParams() {
  const m = document.getElementById('fltMonth').value;
  let from = document.getElementById('fltFrom').value;
  let to = document.getElementById('fltTo').value;
  if (!from && !to && m) {
    from = m + '-01';
    const parts = m.split('-');
    const y = parseInt(parts[0], 10), mo = parseInt(parts[1], 10);
    const last = new Date(y, mo, 0).getDate();
    to = m + '-' + String(last).padStart(2, '0');
  }
  return {
    date_from: from,
    date_to: to,
    source: document.getElementById('fltSource').value,
    q: document.getElementById('fltQ').value.trim(),
  };
}

async function loadStats() {
  const month = document.getElementById('fltMonth').value || '<?= h($monthDefault) ?>';
  const r = await fetch(adminApi('admin_accounting.php', { action: 'stats', month }), { credentials: 'same-origin' });
  const j = await r.json();
  if (!j.ok) return;
  const d = j.dashboard || {};
  const q = d.quarter || {};
  const m = j.summary || {};
  document.getElementById('kpiRevenueLabel').textContent =
    'Дохід (' + (q.label || 'квартал') + ')';
  document.getElementById('kpiRevenue').textContent = fmtUah(q.revenue_uah);
  document.getElementById('kpiCount').textContent = String(q.payment_count ?? 0);
  document.getElementById('kpiTax').textContent = fmtUah(q.tax_estimated_uah);
  const pend = Number(d.bank_unapplied_rows || 0);
  const pendUah = Number(d.bank_unapplied_uah || 0);
  document.getElementById('kpiPending').textContent = pend > 0
    ? (pend + ' · ' + fmtUah(pendUah))
    : '0';
  const hint = document.getElementById('kpiHint');
  if (hint) {
    const bs = q.by_source || {};
    hint.textContent =
      (d.revenue_basis || '') +
      ' · Місяць ' + (m.month || d.activity_month || '') + ': ' + fmtUah(m.revenue_uah) +
      ' (' + (m.payment_count ?? 0) + ' пл.)' +
      (bs.privat_bank ? (' · Банк: ' + fmtUah(bs.privat_bank)) : '') +
      (bs.whitepay ? (' · WhitePay: ' + fmtUah(bs.whitepay)) : '');
  }
  const badge = document.getElementById('bankTabBadge');
  if (pend > 0) { badge.hidden = false; badge.textContent = String(pend); } else { badge.hidden = true; }
}

async function loadProfile() {
  const r = await fetch(adminApi('admin_accounting.php', { action: 'profile' }), { credentials: 'same-origin' });
  const j = await r.json();
  if (!j.ok) return;
  const i = j.integrations || {};
  document.getElementById('intPrivat').textContent = 'ПриватБанк: ' + (i.privat_bank ? '✓' : '—');
  document.getElementById('intWhitepay').textContent = 'WhitePay: ' + (i.whitepay ? '✓' : '—');
  document.getElementById('intSheets').textContent = 'Google Sheets: ' + (i.google_sheets ? '✓ webhook' : 'не налаштовано');
}

function renderLedgerRows(rows, append) {
  const tb = document.getElementById('ledgerBody');
  if (!append) tb.innerHTML = '';
  if (!rows.length && !append) {
    tb.innerHTML = '<tr><td colspan="7" class="adm-muted">Немає записів за період</td></tr>';
    return;
  }
  rows.forEach(row => {
    const tr = document.createElement('tr');
    const client = row.user_email
      ? (row.user_email + (row.user_id ? ' #' + row.user_id : ''))
      : (row.user_id ? '#' + row.user_id : '—');
    tr.innerHTML =
      '<td>' + fmtDt(row.paid_at) + '</td>' +
      '<td>' + (row.source_label || row.source) + '</td>' +
      '<td class="adm-mono">' + fmtUah(row.amount_uah) + (row.counts_as_revenue ? '' : ' <span class="adm-muted">(не дохід)</span>') + '</td>' +
      '<td>' + client + '</td>' +
      '<td>' + (row.payer_name || '—') + '</td>' +
      '<td>' + statusBadge(row.status) + '</td>' +
      '<td class="adm-mono" style="max-width:160px;overflow:hidden;text-overflow:ellipsis" title="' + (row.purpose || '') + '">' + (row.tx_ref || '—') + '</td>';
    tb.appendChild(tr);
  });
}

async function loadLedger(append) {
  if (!append) ledgerOffset = 0;
  const fp = filterParams();
  const q = Object.assign({ action: 'list', limit: 80, offset: ledgerOffset }, fp);
  const r = await fetch(adminApi('admin_accounting.php', q), { credentials: 'same-origin' });
  const j = await r.json();
  document.getElementById('ledgerMsg').textContent = '';
  if (!j.ok) {
    document.getElementById('ledgerMsg').textContent = j.error || 'Помилка';
    return;
  }
  renderLedgerRows(j.rows || [], append);
  ledgerHasMore = !!j.has_more;
  document.getElementById('btnMore').hidden = !ledgerHasMore;
  if (ledgerHasMore) ledgerOffset += (j.rows || []).length;
}

async function loadPendingBank() {
  const r = await fetch(adminApi('admin_accounting.php', { action: 'pending_bank' }), { credentials: 'same-origin' });
  const j = await r.json();
  const tb = document.getElementById('bankBody');
  if (!j.ok) {
    tb.innerHTML = '<tr><td colspan="7" class="adm-muted">Помилка завантаження</td></tr>';
    return;
  }
  const rows = j.rows || [];
  if (!rows.length) {
    tb.innerHTML = '<tr><td colspan="7" class="adm-muted">Немає невирішених банківських рядків</td></tr>';
    return;
  }
  tb.innerHTML = '';
  rows.forEach(row => {
    const tr = document.createElement('tr');
    const uid = row.matched_user_id ? ('#' + row.matched_user_id + ' ' + (row.user_email || '')) : '—';
  const canApply = row.status !== 'applied';
    tr.innerHTML =
      '<td>' + fmtDt(row.tran_at || row.created_at) + '</td>' +
      '<td class="adm-mono">' + fmtUah(row.amount_uah) + '</td>' +
      '<td>' + (row.payer_name || '—') + '</td>' +
      '<td style="max-width:220px">' + (row.osnd || '—') + '</td>' +
      '<td>' + statusBadge(row.status) + '</td>' +
      '<td>' + uid + '</td>' +
      '<td>' + (canApply
        ? '<button class="adm-btn" type="button" data-apply="' + row.id + '">Застосувати</button> '
          + '<button class="adm-btn" type="button" data-ignore="' + row.id + '">Ігнор</button>'
        : '') + '</td>';
    tb.appendChild(tr);
  });
  tb.querySelectorAll('[data-apply]').forEach(btn => {
    btn.addEventListener('click', () => openApplyModal(Number(btn.getAttribute('data-apply')), rows));
  });
  tb.querySelectorAll('[data-ignore]').forEach(btn => {
    btn.addEventListener('click', () => ignoreBank(Number(btn.getAttribute('data-ignore'))));
  });
}

function openApplyModal(bankId, rows) {
  applyBankId = bankId;
  const row = (rows || []).find(r => Number(r.id) === bankId) || {};
  document.getElementById('applySummary').textContent =
    fmtUah(row.amount_uah) + ' · ' + (row.osnd || '') + (row.matched_user_id ? (' · підказка user #' + row.matched_user_id) : '');
  document.getElementById('applyUserId').value = row.matched_user_id ? String(row.matched_user_id) : '';
  document.getElementById('applyMsg').textContent = '';
  document.getElementById('applyModal').classList.add('open');
}

function closeApplyModal() {
  document.getElementById('applyModal').classList.remove('open');
  applyBankId = 0;
}

async function confirmApply() {
  const userId = Number(document.getElementById('applyUserId').value);
  if (!applyBankId || userId <= 0) {
    document.getElementById('applyMsg').textContent = 'Вкажіть User ID';
    return;
  }
  const fd = new FormData();
  fd.set('action', 'privat_apply');
  fd.set('bank_id', String(applyBankId));
  fd.set('user_id', String(userId));
  fd.set('csrf_token', CSRF);
  const r = await fetch(adminApi('admin_accounting.php'), { method: 'POST', body: fd, credentials: 'same-origin' });
  const j = await r.json();
  if (!j.ok) {
    document.getElementById('applyMsg').textContent = j.error || 'Помилка';
    return;
  }
  closeApplyModal();
  await refreshAll();
}

async function ignoreBank(bankId) {
  const note = prompt('Коментар (необовʼязково):') || '';
  const fd = new FormData();
  fd.set('action', 'privat_ignore');
  fd.set('bank_id', String(bankId));
  fd.set('note', note);
  fd.set('csrf_token', CSRF);
  await fetch(adminApi('admin_accounting.php'), { method: 'POST', body: fd, credentials: 'same-origin' });
  await refreshAll();
}

async function pollPrivat() {
  document.getElementById('pollMsg').textContent = 'Опитування…';
  const fd = new FormData();
  fd.set('action', 'privat_poll');
  fd.set('lookback_days', '7');
  fd.set('csrf_token', CSRF);
  const r = await fetch(adminApi('admin_accounting.php'), { method: 'POST', body: fd, credentials: 'same-origin' });
  const j = await r.json();
  if (!j.ok) {
    document.getElementById('pollMsg').textContent = 'Помилка';
    return;
  }
  const s = j.stats || {};
  document.getElementById('pollMsg').textContent =
    'Отримано: ' + (s.fetched ?? 0) + ', applied: ' + (s.applied ?? 0) + ', matched: ' + (s.matched ?? 0);
  await refreshAll();
}

function exportCsv() {
  const fp = filterParams();
  const q = Object.assign({ action: 'export' }, fp);
  window.location.href = adminApi('admin_accounting.php', q);
}

function fmtDateOnly(s) {
  if (!s) return '—';
  const p = String(s).split('-');
  if (p.length !== 3) return s;
  return p[2] + '.' + p[1] + '.' + p[0];
}

function serviceBadge(ok, detail) {
  let cls = 'badge--fail';
  let label = '✗ ' + (detail || 'fail');
  if (ok === true) { cls = ''; label = '✓ ' + (detail || 'ok'); }
  if (ok === null) { cls = 'badge--muted'; label = detail || '—'; }
  return '<span class="badge ' + cls + '">' + label + '</span>';
}

async function loadPrivatProbe() {
  document.getElementById('privatProbeMsg').textContent = 'Перевірка…';
  const r = await fetch(adminApi('admin_accounting.php', { action: 'privat_probe' }), { credentials: 'same-origin' });
  const j = await r.json();
  if (!j.ok) {
    document.getElementById('privatProbeMsg').textContent = j.error || 'Помилка';
    return;
  }
  const p = j.probe || {};
  document.getElementById('privatProbeMsg').textContent =
    'IBAN ' + (p.iban || '—') + ' · ЄДРПОУ ' + (p.edrpou || '—');
  const box = document.getElementById('privatServices');
  const labels = {
    balance: 'Баланси / транзакції',
    statements_settings: 'Налаштування виписки',
    edoc_journal: 'ЕДО (журнал)',
    eo_upload: 'Е-звітність (upload)',
  };
  box.innerHTML = '';
  Object.keys(labels).forEach(k => {
    const s = (p.services || {})[k] || {};
    const div = document.createElement('div');
    div.className = 'adm-card';
    div.style.padding = '10px 12px';
    div.innerHTML = '<div class="adm-muted" style="font-size:0.78rem">' + labels[k] + '</div>' +
      serviceBadge(s.ok, s.detail) +
      (s.count != null ? ' <span class="adm-muted">(' + s.count + ')</span>' : '');
    box.appendChild(div);
  });
}

async function loadPrivatBalance() {
  const tb = document.getElementById('privatBalanceBody');
  tb.innerHTML = '<tr><td colspan="5" class="adm-muted">Завантаження…</td></tr>';
  const r = await fetch(adminApi('admin_accounting.php', { action: 'privat_balance' }), { credentials: 'same-origin' });
  const j = await r.json();
  if (!j.ok) {
    tb.innerHTML = '<tr><td colspan="5" class="adm-muted">Помилка: ' + (j.error || 'API') + '</td></tr>';
    return;
  }
  const rows = j.balances || [];
  if (!rows.length) {
    tb.innerHTML = '<tr><td colspan="5" class="adm-muted">Немає даних</td></tr>';
    return;
  }
  tb.innerHTML = '';
  rows.forEach(row => {
    const tr = document.createElement('tr');
    const bal = row.balance ?? row.balanceOut ?? row.balanceOutEq ?? row.rest ?? row.amount;
    const avail = row.available ?? row.availableBalance ?? bal;
    tr.innerHTML =
      '<td class="adm-mono">' + (row.acc || row.iban || row.account || '—') + '</td>' +
      '<td>' + (row.currency || row.ccy || 'UAH') + '</td>' +
      '<td class="adm-mono">' + fmtUah(bal) + '</td>' +
      '<td class="adm-mono">' + fmtUah(avail) + '</td>' +
      '<td>' + fmtDt(row.updated || row.dpd || row.actualDate || row.date) + '</td>';
    tb.appendChild(tr);
  });
}

function renderDpsDocs(rows) {
  const tb = document.getElementById('dpsDocsBody');
  if (!rows.length) {
    tb.innerHTML = '<tr><td colspan="6" class="adm-muted">Ще немає завантажень</td></tr>';
    return;
  }
  tb.innerHTML = '';
  rows.forEach(row => {
    const tr = document.createElement('tr');
    tr.innerHTML =
      '<td>' + fmtDt(row.created_at) + '</td>' +
      '<td>' + (row.doc_kind || '—') + '</td>' +
      '<td>' + (row.file_name || '—') + '</td>' +
      '<td>' + (row.period_key || '—') + '</td>' +
      '<td>' + statusBadge(row.status) + '</td>' +
      '<td class="adm-mono">' + (row.privat_ref || '—') + '</td>';
    tb.appendChild(tr);
  });
}

async function loadDpsPreview() {
  const year = Number(document.getElementById('dpsYear').value) || new Date().getFullYear();
  const quarter = Number(document.getElementById('dpsQuarter').value) || 1;
  document.getElementById('eoUploadYear').value = String(year);
  document.getElementById('eoUploadQuarter').value = String(quarter);
  const r = await fetch(adminApi('admin_accounting.php', { action: 'declaration_preview', year, quarter }), { credentials: 'same-origin' });
  const j = await r.json();
  if (!j.ok) return;
  const d = j.declaration || {};
  document.getElementById('dpsRevenue').textContent = fmtUah(d.revenue_uah);
  document.getElementById('dpsEp').textContent = fmtUah(d.ep_tax_uah) + ' (' + (d.ep_rate_pct || '') + '%)';
  document.getElementById('dpsMil').textContent = fmtUah(d.military_tax_uah);
  document.getElementById('dpsDeadlineFile').textContent = fmtDateOnly(d.deadline_file);
  const bs = d.by_source || {};
  const br = document.getElementById('dpsBreakdown');
  if (br) {
    br.textContent =
      (d.period?.label || '') + ': ' + (d.payment_count ?? 0) + ' надходжень — ' +
      'банк ' + fmtUah(bs.privat_bank) + ', WhitePay ' + fmtUah(bs.whitepay) +
      '. Кнопки адміна «+30 днів» у дохід не входять (лише реальні перекази).';
  }
  renderDpsDocs(j.documents || []);
}

function exportDpsPackage() {
  const year = Number(document.getElementById('dpsYear').value) || new Date().getFullYear();
  const quarter = Number(document.getElementById('dpsQuarter').value) || 1;
  window.location.href = adminApi('admin_accounting.php', { action: 'declaration_export', year, quarter });
}

async function submitEoUpload(ev) {
  ev.preventDefault();
  const msg = document.getElementById('eoUploadMsg');
  msg.textContent = 'Надсилання…';
  const fd = new FormData(document.getElementById('eoUploadForm'));
  fd.set('action', 'eo_upload');
  fd.set('year', document.getElementById('eoUploadYear').value || document.getElementById('dpsYear').value);
  fd.set('quarter', document.getElementById('eoUploadQuarter').value || document.getElementById('dpsQuarter').value);
  fd.set('csrf_token', CSRF);
  const r = await fetch(adminApi('admin_accounting.php'), { method: 'POST', body: fd, credentials: 'same-origin' });
  const j = await r.json();
  if (!j.ok) {
    msg.textContent = j.error || 'Помилка завантаження';
    return;
  }
  msg.textContent = j.next_step || 'Завантажено';
  await loadDpsPreview();
}

async function loadEdoc() {
  const journal = document.getElementById('edoJournal').value;
  const date_from = document.getElementById('edoFrom').value;
  const date_to = document.getElementById('edoTo').value;
  document.getElementById('edoMsg').textContent = 'Завантаження…';
  const r = await fetch(adminApi('admin_accounting.php', {
    action: 'edoc_journal', journal, date_from, date_to, limit: 80,
  }), { credentials: 'same-origin' });
  const j = await r.json();
  const tb = document.getElementById('edoBody');
  if (!j.ok) {
    document.getElementById('edoMsg').textContent = j.error || 'Помилка';
    tb.innerHTML = '<tr><td colspan="6" class="adm-muted">Помилка API</td></tr>';
    return;
  }
  document.getElementById('edoMsg').textContent = 'Знайдено: ' + (j.total ?? (j.rows || []).length);
  const rows = j.rows || [];
  if (!rows.length) {
    tb.innerHTML = '<tr><td colspan="6" class="adm-muted">Немає документів за період</td></tr>';
    return;
  }
  tb.innerHTML = '';
  rows.forEach(row => {
    const tr = document.createElement('tr');
    const amt = row.amount ?? row.sum ?? row.total;
    tr.innerHTML =
      '<td>' + fmtDateOnly(row.date || row.docDate || row.created) + '</td>' +
      '<td class="adm-mono">' + (row.number || row.docNumber || row.id || '—') + '</td>' +
      '<td>' + (row.counterparty || row.partner || row.name || '—') + '</td>' +
      '<td class="adm-mono">' + (amt != null ? fmtUah(amt) : '—') + '</td>' +
      '<td>' + statusBadge(row.status || row.state || '—') + '</td>' +
      '<td>' + (row.type || row.docType || '—') + '</td>';
    tb.appendChild(tr);
  });
}

async function loadPrivatTab() {
  await loadPrivatProbe();
  await loadPrivatBalance();
}

async function loadDpsTab() {
  await loadDpsPreview();
}

window.COMPLIANCE_TIMERS = { file: null, pay: null };

function countdownToYmd(ymd) {
  if (!ymd) return { text: '—', overdue: false, empty: true };
  const end = new Date(String(ymd) + 'T23:59:59');
  const now = new Date();
  let diff = end - now;
  const overdue = diff < 0;
  if (overdue) diff = -diff;
  const sec = Math.floor(diff / 1000);
  const days = Math.floor(sec / 86400);
  const hours = Math.floor((sec % 86400) / 3600);
  const mins = Math.floor((sec % 3600) / 60);
  const secs = sec % 60;
  const parts = [];
  if (days > 0) parts.push(days + ' дн');
  parts.push(String(hours).padStart(2, '0') + ' год');
  parts.push(String(mins).padStart(2, '0') + ' хв');
  parts.push(String(secs).padStart(2, '0') + ' с');
  return {
    text: (overdue ? 'прострочено на ' : 'залишилось ') + parts.join(' '),
    overdue,
    empty: false,
  };
}

function renderDeadlineCard(prefix, item) {
  const titleEl = document.getElementById(prefix + 'Title');
  const dateEl = document.getElementById(prefix + 'Date');
  const cdEl = document.getElementById(prefix + 'Countdown');
  const card = cdEl ? cdEl.closest('.acc-deadline-card') : null;
  if (!titleEl || !dateEl || !cdEl) return;
  if (!item || !item.deadline_date) {
    titleEl.textContent = 'Немає найближчих строк';
    dateEl.textContent = '';
    cdEl.textContent = '—';
    cdEl.className = 'acc-deadline-card__countdown';
    if (card) card.classList.remove('is-overdue', 'is-active');
    return;
  }
  titleEl.textContent = item.title || '—';
  const form = item.form ? (' · ' + item.form) : '';
  dateEl.textContent = 'крайній термін: ' + fmtDateOnly(item.deadline_date) +
    (item.period_label ? ' (' + item.period_label + ')' : '') + form;
  if (card) card.classList.toggle('is-overdue', item.status === 'overdue');
}

function tickComplianceCountdowns() {
  [
    ['normTimerFile', window.COMPLIANCE_TIMERS.file],
    ['normTimerPay', window.COMPLIANCE_TIMERS.pay],
    ['calTimerFile', window.COMPLIANCE_TIMERS.file],
    ['calTimerPay', window.COMPLIANCE_TIMERS.pay],
  ].forEach(([prefix, item]) => {
    const cdEl = document.getElementById(prefix + 'Countdown');
    if (!cdEl) return;
    const r = countdownToYmd(item?.deadline_date);
    cdEl.textContent = r.text;
    cdEl.className = 'acc-deadline-card__countdown' +
      (r.overdue ? ' is-overdue' : (r.empty ? '' : ' is-active'));
    const card = cdEl.closest('.acc-deadline-card');
    if (card) card.classList.toggle('is-overdue', r.overdue);
  });
  document.querySelectorAll('[data-countdown-ymd]').forEach(el => {
    const r = countdownToYmd(el.getAttribute('data-countdown-ymd'));
    el.textContent = r.text;
    el.className = 'adm-mono acc-cd-cell' + (r.overdue ? ' is-overdue' : '');
  });
}

function applyComplianceTimers(timers) {
  window.COMPLIANCE_TIMERS = {
    file: timers?.file || null,
    pay: timers?.pay || null,
  };
  renderDeadlineCard('normTimerFile', window.COMPLIANCE_TIMERS.file);
  renderDeadlineCard('normTimerPay', window.COMPLIANCE_TIMERS.pay);
  renderDeadlineCard('calTimerFile', window.COMPLIANCE_TIMERS.file);
  renderDeadlineCard('calTimerPay', window.COMPLIANCE_TIMERS.pay);
  tickComplianceCountdowns();
  if (window._complianceTimerIv) clearInterval(window._complianceTimerIv);
  window._complianceTimerIv = setInterval(tickComplianceCountdowns, 1000);
}

function calStatusBadge(st) {
  const map = { completed: 'виконано', overdue: 'прострочено', due_soon: 'скоро', upcoming: 'очікується' };
  let cls = 'badge--muted';
  if (st === 'overdue') cls = 'badge--fail';
  if (st === 'due_soon') cls = 'badge--warn';
  if (st === 'completed') cls = '';
  return '<span class="badge ' + cls + '">' + (map[st] || st) + '</span>';
}

function renderPrivatResearch(data) {
  const el = document.getElementById('privatResearch');
  if (!el || !data) return;
  const api = data.autoclient_api_eo || {};
  const lim = (api.limitations || []).map(x => '<li>' + x + '</li>').join('');
  const wf = (data.recommended_workflow || []).map((x, i) => '<li>' + x + '</li>').join('');
  el.innerHTML =
    '<p><strong>' + (data.summary || '') + '</strong></p>' +
    '<p>Зараз StrIM: <em>' + (data.current_strim_integration?.scope || '') + '</em></p>' +
    '<h4>UI Приват24</h4><p>' + (data.privat24_ui?.where || '') + '</p>' +
    '<h4>API Autoclient (електронна звітність)</h4><ul>' +
    Object.keys(api.endpoints || {}).map(k => '<li><code>' + k + '</code> — ' + api.endpoints[k] + '</li>').join('') +
    '</ul><p class="adm-muted">' + (api.requires_api_permission || '') + '</p>' +
    '<h4>Обмеження API</h4><ul>' + lim + '</ul>' +
    '<h4>Рекомендований процес</h4><ol>' + wf + '</ol>' +
    '<p><a href="' + (data.docs_url || '#') + '" target="_blank" rel="noopener">Документація Autoclient API</a></p>';
}

async function loadCalendar() {
  const tb = document.getElementById('calBody');
  try {
    const year = Number(document.getElementById('calYear').value) || new Date().getFullYear();
    const r = await fetch(adminApi('admin_accounting.php', { action: 'compliance_calendar', year }), { credentials: 'same-origin' });
    const j = await r.json();
    if (!j.ok) {
      if (tb) tb.innerHTML = '<tr><td colspan="9" class="adm-muted">Помилка: ' + (j.error || 'завантаження') + '</td></tr>';
      return;
    }
  const events = j.events || [];
  const st = j.stats || {};
  document.getElementById('calOverdue').textContent = String(st.overdue ?? 0);
  document.getElementById('calSoon').textContent = String(st.due_soon ?? 0);
  applyComplianceTimers(st);
  const badge = document.getElementById('calTabBadge');
  const alertN = Number(st.overdue || 0) + Number(st.due_soon || 0);
  if (alertN > 0) { badge.hidden = false; badge.textContent = String(alertN); } else { badge.hidden = true; }
  const hint = j.revenue_hint || {};
  document.getElementById('calHint').textContent =
    'Оцінка ЄП за ' + (hint.month || '') + ': ' + fmtUah(hint.tax_estimated_uah) + ' (доход ' + fmtUah(hint.revenue_uah) + ')';
  renderPrivatResearch(j.privat_research);
  if (!events.length) {
    tb.innerHTML = '<tr><td colspan="9" class="adm-muted">Немає подій за обраний профіль</td></tr>';
    return;
  }
  tb.innerHTML = '';
  events.forEach(ev => {
    const tr = document.createElement('tr');
    const done = ev.status === 'completed';
    const cdYmd = done ? '' : (ev.deadline_file || '');
    tr.innerHTML =
      '<td class="adm-mono">' + fmtDateOnly(ev.deadline_file) + '</td>' +
      '<td class="adm-mono acc-cd-cell" data-countdown-ymd="' + cdYmd + '">' + (done ? '—' : '…') + '</td>' +
      '<td>' + (ev.title || '') + '</td>' +
      '<td>' + (ev.authority || '') + '</td>' +
      '<td class="adm-mono">' + (ev.form || '') + '</td>' +
      '<td>' + (ev.period_label || '') + '</td>' +
      '<td class="adm-mono">' + (ev.deadline_pay ? fmtDateOnly(ev.deadline_pay) : '—') + '</td>' +
      '<td>' + calStatusBadge(ev.status) + '</td>' +
      '<td><button class="adm-btn" type="button" data-ev="' + ev.key + '" data-done="' + (done ? '0' : '1') + '">' +
      (done ? 'Скасувати ✓' : 'Виконано') + '</button></td>';
    tb.appendChild(tr);
  });
  tb.querySelectorAll('[data-ev]').forEach(btn => {
    btn.addEventListener('click', async () => {
      const fd = new FormData();
      fd.set('action', 'compliance_complete');
      fd.set('event_key', btn.getAttribute('data-ev'));
      fd.set('done', btn.getAttribute('data-done'));
      fd.set('csrf_token', CSRF);
      await fetch(adminApi('admin_accounting.php'), { method: 'POST', body: fd, credentials: 'same-origin' });
      await loadCalendar();
    });
  });
  tickComplianceCountdowns();
  } catch (e) {
    if (tb) tb.innerHTML = '<tr><td colspan="9" class="adm-muted">Помилка мережі або сервера</td></tr>';
    console.error('loadCalendar', e);
  }
}

function fillSelect(sel, labels, value) {
  sel.innerHTML = '';
  Object.keys(labels).forEach(k => {
    const o = document.createElement('option');
    o.value = k;
    o.textContent = labels[k];
    if (k === value) o.selected = true;
    sel.appendChild(o);
  });
}

async function loadSettings() {
  const r = await fetch(adminApi('admin_accounting.php', { action: 'compliance_settings' }), { credentials: 'same-origin' });
  const j = await r.json();
  if (!j.ok) return;
  const s = j.settings || {};
  const lb = j.labels || {};
  fillSelect(document.getElementById('setTaxSystem'), lb.tax_system || {}, s.tax_system);
  fillSelect(document.getElementById('setEntitySize'), lb.entity_size || {}, s.entity_size);
  fillSelect(document.getElementById('setReportsChannel'), lb.reports_channel || {}, s.reports_channel);
  document.getElementById('setVatPayer').checked = !!s.vat_payer;
  document.getElementById('setHasEmployees').checked = !!s.has_employees;
  document.getElementById('setEmployeeCount').value = String(s.employee_count ?? 0);
  document.getElementById('setAccountantName').value = s.accountant_name || '';
  document.getElementById('setAccountantEmail').value = s.accountant_email || '';
  document.getElementById('setAccountantPhone').value = s.accountant_phone || '';
  document.getElementById('setNotes').value = s.notes || '';
  if (j.timers) applyComplianceTimers(j.timers);
}

async function saveSettings() {
  const payload = {
    tax_system: document.getElementById('setTaxSystem').value,
    entity_size: document.getElementById('setEntitySize').value,
    reports_channel: document.getElementById('setReportsChannel').value,
    vat_payer: document.getElementById('setVatPayer').checked,
    has_employees: document.getElementById('setHasEmployees').checked,
    employee_count: Number(document.getElementById('setEmployeeCount').value),
    accountant_name: document.getElementById('setAccountantName').value,
    accountant_email: document.getElementById('setAccountantEmail').value,
    accountant_phone: document.getElementById('setAccountantPhone').value,
    notes: document.getElementById('setNotes').value,
  };
  const fd = new FormData();
  fd.set('action', 'compliance_save');
  fd.set('settings', JSON.stringify(payload));
  fd.set('csrf_token', CSRF);
  const r = await fetch(adminApi('admin_accounting.php'), { method: 'POST', body: fd, credentials: 'same-origin' });
  const j = await r.json();
  document.getElementById('settingsMsg').textContent = j.ok ? 'Збережено' : (j.error || 'Помилка');
  if (j.ok) await loadCalendar();
}

async function refreshAll() {
  await Promise.all([loadStats(), loadLedger(false), loadPendingBank(), loadCalendar()]);
}

document.querySelectorAll('.adm-tab').forEach(tab => {
  tab.addEventListener('click', () => {
    document.querySelectorAll('.adm-tab').forEach(t => t.classList.remove('is-active'));
    document.querySelectorAll('.adm-panel').forEach(p => { p.classList.remove('is-active'); p.hidden = true; });
    tab.classList.add('is-active');
    const id = 'tab-' + tab.getAttribute('data-tab');
    const panel = document.getElementById(id);
    if (panel) { panel.classList.add('is-active'); panel.hidden = false; }
    const t = tab.getAttribute('data-tab');
    if (t === 'calendar') loadCalendar();
    if (t === 'settings') loadSettings();
    if (t === 'privat') loadPrivatTab();
    if (t === 'dps') loadDpsTab();
    if (t === 'edo') loadEdoc();
  });
});

document.getElementById('btnReload').addEventListener('click', refreshAll);
document.getElementById('btnMore').addEventListener('click', () => loadLedger(true));
document.getElementById('btnExport').addEventListener('click', exportCsv);
document.getElementById('btnPrivatPoll').addEventListener('click', pollPrivat);
document.getElementById('btnApplyConfirm').addEventListener('click', confirmApply);
document.getElementById('btnCalReload').addEventListener('click', loadCalendar);
document.getElementById('btnSaveSettings').addEventListener('click', saveSettings);
document.getElementById('btnPrivatProbe').addEventListener('click', loadPrivatProbe);
document.getElementById('btnPrivatBalance').addEventListener('click', loadPrivatBalance);
document.getElementById('btnDpsPreview').addEventListener('click', loadDpsPreview);
document.getElementById('btnDpsExport').addEventListener('click', exportDpsPackage);
document.getElementById('eoUploadForm').addEventListener('submit', submitEoUpload);
document.getElementById('btnEdoReload').addEventListener('click', loadEdoc);

loadProfile();
loadSettings();
refreshAll();
</script>
</body>
</html>
