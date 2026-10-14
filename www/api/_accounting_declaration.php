<?php
declare(strict_types=1);

require_once __DIR__ . '/_accounting.php';
require_once __DIR__ . '/_accounting_compliance.php';

/**
 * @return array{year:int, quarter:int, date_from:string, date_to:string, label:string}
 */
function accounting_quarter_bounds(int $year, int $quarter): array
{
    $quarter = max(1, min(4, $quarter));
    $ends = [1 => '03-31', 2 => '06-30', 3 => '09-30', 4 => '12-31'];
    $labels = [1 => 'I', 2 => 'II', 3 => 'III', 4 => 'IV'];
    $from = $year . '-' . sprintf('%02d', ($quarter - 1) * 3 + 1) . '-01';
    $to = $year . '-' . $ends[$quarter];
    return [
        'year' => $year,
        'quarter' => $quarter,
        'date_from' => $from,
        'date_to' => $to,
        'label' => $labels[$quarter] . ' квартал ' . $year,
    ];
}

/**
 * Дохід за квартал з реєстру StrIM (лише підтверджені надходження).
 *
 * @return array<string,mixed>
 */
function accounting_quarter_revenue(mysqli $db, int $year, int $quarter): array
{
    $b = accounting_quarter_bounds($year, $quarter);
    $filters = accounting_parse_filters([
        'source' => 'all',
        'date_from' => $b['date_from'],
        'date_to' => $b['date_to'],
        'limit' => 10000,
        'offset' => 0,
    ]);
    $rows = accounting_fetch_ledger($db, $filters);
    $profile = accounting_company_profile();
    $revenue = 0.0;
    $count = 0;
    $bySource = ['privat_bank' => 0.0, 'whitepay' => 0.0, 'admin_manual' => 0.0];
    foreach ($rows as $row) {
        if (empty($row['counts_as_revenue'])) {
            continue;
        }
        $amt = (float)($row['amount_uah'] ?? 0);
        $revenue += $amt;
        $count++;
        $src = (string)($row['source'] ?? '');
        if (isset($bySource[$src])) {
            $bySource[$src] += $amt;
        }
    }
    $epRate = (float)$profile['tax_rate'];
    $milRate = (float)(getenv('ACCOUNTING_MILITARY_TAX_RATE') ?: ($_ENV['ACCOUNTING_MILITARY_TAX_RATE'] ?? '0.01'));
    $epTax = round($revenue * $epRate, 2);
    $milTax = round($revenue * $milRate, 2);

    $fileDeadline = accounting_compliance_add_days($b['date_to'], 40);
    $payDeadline = accounting_compliance_add_days($fileDeadline, 10);

    return [
        'period' => $b,
        'payment_count' => $count,
        'revenue_uah' => round($revenue, 2),
        'by_source' => $bySource,
        'ep_tax_uah' => $epTax,
        'military_tax_uah' => $milTax,
        'total_tax_uah' => round($epTax + $milTax, 2),
        'ep_rate_pct' => round($epRate * 100, 2),
        'military_rate_pct' => round($milRate * 100, 2),
        'deadline_file' => $fileDeadline,
        'deadline_pay' => $payDeadline,
        'form' => 'J0103509',
        'company' => $profile,
    ];
}

/**
 * Пакет даних для бухгалтера / імпорту в Приват24 (не офіційний XML ДПС).
 *
 * @return array{filename:string, content:string, summary:array}
 */
function accounting_declaration_export_package(mysqli $db, int $year, int $quarter): array
{
    $summary = accounting_quarter_revenue($db, $year, $quarter);
    $p = $summary['period'];
    $c = $summary['company'];
    $lines = [
        'STRIM_DECLARATION_PACKAGE',
        'generated_at=' . date('c'),
        'legal_name=' . ($c['legal_name'] ?? ''),
        'edrpou=' . ($c['edrpou'] ?? ''),
        'period=' . ($p['label'] ?? ''),
        'date_from=' . ($p['date_from'] ?? ''),
        'date_to=' . ($p['date_to'] ?? ''),
        'revenue_uah=' . number_format((float)$summary['revenue_uah'], 2, '.', ''),
        'ep_tax_uah=' . number_format((float)$summary['ep_tax_uah'], 2, '.', ''),
        'military_tax_uah=' . number_format((float)$summary['military_tax_uah'], 2, '.', ''),
        'payment_count=' . (int)$summary['payment_count'],
        'form=J0103509',
        'deadline_file=' . ($summary['deadline_file'] ?? ''),
        'deadline_pay=' . ($summary['deadline_pay'] ?? ''),
        '',
        'source;amount_uah',
    ];
    $filters = accounting_parse_filters([
        'source' => 'all',
        'date_from' => $p['date_from'],
        'date_to' => $p['date_to'],
        'limit' => 10000,
        'offset' => 0,
    ]);
    foreach (accounting_fetch_ledger($db, $filters) as $row) {
        if (empty($row['counts_as_revenue'])) {
            continue;
        }
        if ($row['source'] === 'privat_bank' && ($row['status'] ?? '') !== 'applied') {
            continue;
        }
        $lines[] = ($row['source_label'] ?? $row['source']) . ';' .
            number_format((float)$row['amount_uah'], 2, '.', '');
    }
    $content = implode("\n", $lines) . "\n";
    $fn = 'strim_ep3_' . $year . '_q' . $quarter . '_' . date('Ymd') . '.txt';

    return ['filename' => $fn, 'content' => $content, 'summary' => $summary];
}
