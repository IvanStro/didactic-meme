<?php
declare(strict_types=1);

require_once __DIR__ . '/_accounting.php';

/**
 * Календар звітності та налаштування відповідно до законодавства України.
 * Профіль за замовч.: ТОВ, єдиний податок 3 група, мікропідприємство, без ПДВ.
 */

/** @return array<string,mixed> */
function accounting_compliance_defaults(): array
{
    return [
        'legal_form' => 'tov',
        'tax_system' => 'ep_group_3',
        'entity_size' => 'micro',
        'vat_payer' => false,
        'has_employees' => false,
        'employee_count' => 0,
        'fop_on_payroll' => false,
        'reports_channel' => 'privat24',
        'privat_eo_api_planned' => false,
        'accountant_name' => '',
        'accountant_email' => '',
        'accountant_phone' => '',
        'notes' => '',
        'completed_event_keys' => [],
    ];
}

/** @return array<string,mixed> */
function accounting_compliance_load(mysqli $db): array
{
    $defaults = accounting_compliance_defaults();
    if (!accounting_table_exists($db, 'accounting_compliance_settings')) {
        return $defaults;
    }
    $r = $db->query('SELECT settings_json FROM accounting_compliance_settings WHERE id=1 LIMIT 1');
    $row = $r ? $r->fetch_assoc() : null;
    if (!$row || empty($row['settings_json'])) {
        return $defaults;
    }
    $decoded = json_decode((string)$row['settings_json'], true);
    if (!is_array($decoded)) {
        return $defaults;
    }
    return array_merge($defaults, $decoded);
}

/** @param array<string,mixed> $settings */
function accounting_compliance_save(mysqli $db, array $settings, int $adminUid): bool
{
    $allowed = accounting_compliance_defaults();
    $clean = [];
    foreach ($allowed as $k => $default) {
        if (!array_key_exists($k, $settings)) {
            $clean[$k] = $default;
            continue;
        }
        $v = $settings[$k];
        if ($k === 'vat_payer' || $k === 'has_employees' || $k === 'fop_on_payroll' || $k === 'privat_eo_api_planned') {
            $clean[$k] = (bool)$v;
        } elseif ($k === 'employee_count') {
            $clean[$k] = max(0, min(9999, (int)$v));
        } elseif ($k === 'completed_event_keys') {
            $clean[$k] = is_array($v) ? array_values(array_unique(array_map('strval', $v))) : [];
        } elseif (is_string($default)) {
            $clean[$k] = mb_substr(trim((string)$v), 0, 500);
        } else {
            $clean[$k] = $v;
        }
    }

    if (!accounting_table_exists($db, 'accounting_compliance_settings')) {
        return false;
    }
    $json = $db->real_escape_string(
        json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}'
    );
    $sql = "INSERT INTO accounting_compliance_settings (id, settings_json, updated_by)
            VALUES (1, '{$json}', " . (int)$adminUid . ")
            ON DUPLICATE KEY UPDATE settings_json='{$json}', updated_by=" . (int)$adminUid;
    return (bool)$db->query($sql);
}

function accounting_compliance_shift_deadline(string $ymd): string
{
    $dt = DateTime::createFromFormat('Y-m-d', $ymd);
    if (!$dt) {
        return $ymd;
    }
    while ((int)$dt->format('N') >= 6) {
        $dt->modify('+1 day');
    }
    return $dt->format('Y-m-d');
}

function accounting_compliance_add_days(string $ymd, int $days): string
{
    $dt = DateTime::createFromFormat('Y-m-d', $ymd);
    if (!$dt) {
        return $ymd;
    }
    if ($days >= 0) {
        $dt->modify('+' . $days . ' days');
    } else {
        $dt->modify((string)$days . ' days');
    }
    return accounting_compliance_shift_deadline($dt->format('Y-m-d'));
}

/** @return array<string,string> */
function accounting_compliance_labels(): array
{
    return [
        'legal_form' => [
            'tov' => 'ТОВ (юридична особа)',
            'fop' => 'ФОП',
        ],
        'tax_system' => [
            'ep_group_1' => 'Єдиний податок, 1 група',
            'ep_group_2' => 'Єдиний податок, 2 група',
            'ep_group_3' => 'Єдиний податок, 3 група (юрособа)',
            'general' => 'Загальна система (податок на прибуток)',
        ],
        'entity_size' => [
            'micro' => 'Мікропідприємство',
            'small' => 'Мале підприємство',
            'medium' => 'Середнє підприємство',
        ],
        'reports_channel' => [
            'privat24' => 'Приват24 для бізнесу (електронна звітність)',
            'tax_cabinet' => 'Електронний кабінет платника (cabinet.tax.gov.ua)',
            'me_doc' => 'M.E.Doc / інший провайдер ЕЦП',
            'accountant' => 'Зовнішній бухгалтер',
        ],
    ];
}

/**
 * @param array<string,mixed> $settings
 * @return list<array<string,mixed>>
 */
function accounting_compliance_build_calendar(array $settings, int $year): array
{
    $events = [];
    $completed = array_flip(array_map('strval', (array)($settings['completed_event_keys'] ?? [])));
    $today = date('Y-m-d');

    $add = static function (
        array &$events,
        string $key,
        string $title,
        string $authority,
        string $form,
        string $periodLabel,
        string $deadlineFile,
        ?string $deadlinePay,
        string $legalRef,
        string $category
    ) use ($completed): void {
        $deadlineFile = accounting_compliance_shift_deadline($deadlineFile);
        $deadlinePay = $deadlinePay !== null ? accounting_compliance_shift_deadline($deadlinePay) : null;
        $today = date('Y-m-d');
        $due = $deadlineFile;
        $status = 'upcoming';
        if (isset($completed[$key])) {
            $status = 'completed';
        } elseif ($due < $today) {
            $status = 'overdue';
        } elseif ($due <= date('Y-m-d', strtotime('+14 days'))) {
            $status = 'due_soon';
        }
        $events[] = [
            'key' => $key,
            'title' => $title,
            'authority' => $authority,
            'form' => $form,
            'period_label' => $periodLabel,
            'deadline_file' => $deadlineFile,
            'deadline_pay' => $deadlinePay,
            'legal_ref' => $legalRef,
            'category' => $category,
            'status' => $status,
            'days_left' => (int)floor((strtotime($due . ' 12:00:00') - strtotime($today . ' 12:00:00')) / 86400),
        ];
    };

    $taxSystem = (string)($settings['tax_system'] ?? 'ep_group_3');
    $entitySize = (string)($settings['entity_size'] ?? 'micro');
    $hasEmployees = !empty($settings['has_employees']) || (int)($settings['employee_count'] ?? 0) > 0;

    if ($taxSystem === 'ep_group_3') {
        $quarters = [
            ['q' => 1, 'end' => "{$year}-03-31", 'label' => "I квартал {$year}"],
            ['q' => 2, 'end' => "{$year}-06-30", 'label' => "II квартал {$year}"],
            ['q' => 3, 'end' => "{$year}-09-30", 'label' => "III квартал {$year}"],
            ['q' => 4, 'end' => "{$year}-12-31", 'label' => "IV квартал {$year} / рік"],
        ];
        foreach ($quarters as $q) {
            $file = accounting_compliance_add_days($q['end'], 40);
            $pay = accounting_compliance_add_days($file, 10);
            $key = "ep3_decl_{$year}_q{$q['q']}";
            $add(
                $events,
                $key,
                'Декларація платника єдиного податку (3 група)',
                'ДПС',
                'J0103509',
                $q['label'],
                $file,
                $pay,
                'п. 296.3, 49.18.2 ПКУ — 40 к.д. після кварталу; сплата ЄП +10 к.д.',
                'tax'
            );
            $add(
                $events,
                $key . '_mil',
                'Військовий збір (1% від доходу ЄП)',
                'ДПС',
                'разом із J0103509',
                $q['label'],
                $file,
                $pay,
                'п. 1.3 п. 16-1 підрозд. 10 розд. XX ПКУ',
                'tax'
            );
        }
    }

    if ($entitySize === 'micro') {
        $statDeadline = accounting_compliance_shift_deadline(($year + 1) . '-02-28');
        $add(
            $events,
            "fin_micro_stat_{$year}",
            'Фінансова звітність мікропідприємства (Держстат)',
            'Держстат',
            '№1-мс, №2-мс',
            "Рік {$year}",
            $statDeadline,
            null,
            'Порядок №419, п.5 — не пізніше 28.02 наступного року',
            'financial'
        );
    }

    if ($hasEmployees) {
        for ($m = 1; $m <= 12; $m++) {
            $period = sprintf('%04d-%02d', $year, $m);
            $nextMonth = $m === 12 ? sprintf('%04d-01', $year + 1) : sprintf('%04d-%02d', $year, $m + 1);
            $esvFile = accounting_compliance_add_days($nextMonth . '-01', 19);
            $add(
                $events,
                "esv_{$period}",
                'Звітність з ЄСВ (Додаток 1 ДФ)',
                'ПФУ / ДПС',
                'Додаток 1 до Декларації з ЄСВ',
                date('m.Y', strtotime($period . '-01')),
                $esvFile,
                $esvFile,
                'Закон про ЄСВ — до 20 числа наступного місяця',
                'payroll'
            );
        }
        foreach ([3, 6, 9, 12] as $m) {
            $end = sprintf('%04d-%02d', $year, $m) . '-' . date('t', strtotime(sprintf('%04d-%02d-01', $year, $m)));
            $file = accounting_compliance_add_days($end, 40);
            $add(
                $events,
                "4df_{$year}_m{$m}",
                'Податковий розрахунок сум доходу (4-ДФ)',
                'ДПС',
                '4-ДФ',
                "Квартал до " . date('m.Y', strtotime($end)),
                $file,
                null,
                'п. 176.2 ПКУ — квартальна звітність при виплатах фізособам',
                'payroll'
            );
        }
        $add(
            $events,
            "unified_pfu_{$year}",
            'Обʼєднаний звіт (ПФУ)',
            'ПФУ',
            'Обʼєднаний звіт',
            "Рік {$year}",
            accounting_compliance_shift_deadline(($year + 1) . '-03-31'),
            null,
            'Закон про збір та облік — річний обʼєднаний звіт',
            'payroll'
        );
    }

    usort($events, static fn(array $a, array $b): int => strcmp((string)$a['deadline_file'], (string)$b['deadline_file']));
    return $events;
}

/** @return array<string,mixed> */
function accounting_compliance_privat_integration_research(): array
{
    return [
        'summary' => 'ПриватБанк підтримує подання звітності до ДПС, але повна автоматизація з StrIM обмежена.',
        'current_strim_integration' => [
            'enabled' => 'Отримання виписки / вхідних платежів (Autoclient API)',
            'env' => 'PRIVAT_ACP_TOKEN, PRIVAT_ACP_CLIENT_ID',
            'scope' => 'Лише «Отримання балансів і транзакцій за рахунками»',
        ],
        'privat24_ui' => [
            'name' => 'Електронна звітність + Календар звітності',
            'where' => 'Приват24 для бізнесу → Облік та звіти → Електронна звітність',
            'features' => [
                'Календар термінів подання',
                'Створення та підписання звітів (КЕП/ЕЦП)',
                'Надсилання в ДПС і Держстат',
                'Завантаження квитанцій з ДПС',
                'Імпорт XML з M.E.Doc / 1С / BAS',
            ],
            'cost' => 'Безкоштовно для клієнтів Приват24 для бізнесу',
        ],
        'autoclient_api_eo' => [
            'documented' => true,
            'endpoints' => [
                'POST /api/proxy/eo/documents/create' => 'Завантаження XML звітності (стандарт ДПС), multipart',
                'POST /api/proxy/eo/documents/create-b64' => 'Те саме в Base64',
            ],
            'requires_api_permission' => 'У налаштуваннях API Autoclient увімкнути «Електронна звітність» (зараз у StrIM вимкнено навмисно)',
            'limitations' => [
                'API завантажує XML у кабінет Приват24 — не замінює формування звіту з даних StrIM',
                'Підпис КЕП і фінальне надсилання в ДПС — у Приват24 або мобільному додатку',
                'Календар звітності через API недоступний — лише в UI Приват24',
                'Потрібен готовий XML за форматами ДПС (J0103509, 1-мс, 2-мс тощо)',
            ],
        ],
        'recommended_workflow' => [
            'StrIM формує реєстр доходів і оцінку ЄП для бухгалтера',
            'Бухгалтер готує XML/звіти в обліковій системі або Приват24',
            'Подання — через Приват24 «Електронна звітність» або M.E.Doc',
            'Опційно (етап 2): завантаження готового XML через Autoclient API',
        ],
        'alternatives' => [
            'cabinet.tax.gov.ua' => 'Електронний кабінет платника ДПС',
            'me_doc' => 'M.E.Doc — популярний провайдер для юросіб',
            '1c_bas' => '1С / BAS + модуль обміну Приват24',
        ],
        'docs_url' => 'https://docs.google.com/document/d/e/2PACX-1vTtKvGa3P4E-lDqLg3bHRF6Wi9S7GIjSMFEFxII5qQZBGxuTXs25hQNiUU1hMZQhOyx6BNvIZ1bVKSr/pub',
    ];
}

/**
 * @param list<array<string,mixed>> $events
 * @return array{upcoming:int,due_soon:int,overdue:int,next:?array,file:?array,pay:?array}
 */
function accounting_compliance_calendar_stats(array $events): array
{
    $stats = ['upcoming' => 0, 'due_soon' => 0, 'overdue' => 0, 'next' => null, 'file' => null, 'pay' => null];
    foreach ($events as $ev) {
        $st = (string)($ev['status'] ?? '');
        if ($st === 'completed') {
            continue;
        }
        if ($st === 'overdue') {
            $stats['overdue']++;
        } elseif ($st === 'due_soon') {
            $stats['due_soon']++;
        } else {
            $stats['upcoming']++;
        }
        if ($stats['next'] === null) {
            $stats['next'] = $ev;
        }

        $df = (string)($ev['deadline_file'] ?? '');
        if ($df !== '') {
            $cur = $stats['file'];
            if ($cur === null || $df < (string)($cur['deadline_date'] ?? '9999-99-99')) {
                $stats['file'] = [
                    'deadline_date' => $df,
                    'title' => (string)($ev['title'] ?? ''),
                    'form' => (string)($ev['form'] ?? ''),
                    'period_label' => (string)($ev['period_label'] ?? ''),
                    'status' => $st,
                    'kind' => 'file',
                ];
            }
        }

        $dp = (string)($ev['deadline_pay'] ?? '');
        if ($dp !== '') {
            $cur = $stats['pay'];
            if ($cur === null || $dp < (string)($cur['deadline_date'] ?? '9999-99-99')) {
                $stats['pay'] = [
                    'deadline_date' => $dp,
                    'title' => (string)($ev['title'] ?? ''),
                    'form' => (string)($ev['form'] ?? ''),
                    'period_label' => (string)($ev['period_label'] ?? ''),
                    'status' => $st,
                    'kind' => 'pay',
                ];
            }
        }
    }
    return $stats;
}
