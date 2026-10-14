<?php
declare(strict_types=1);

/**
 * Бухгалтерський облік доходів StrIM (ТОВ «СтрІМ Алго»).
 * Єдиний реєстр з bank_incoming_payments, whitepay_orders, admin_action_audit.
 */

function accounting_company_profile(): array
{
    $rate = (float)(getenv('ACCOUNTING_SUBSCRIPTION_UAH') ?: ($_ENV['ACCOUNTING_SUBSCRIPTION_UAH'] ?? '500'));
    if (!is_finite($rate) || $rate <= 0) {
        $rate = 500.0;
    }
    $taxRate = (float)(getenv('ACCOUNTING_TAX_RATE') ?: ($_ENV['ACCOUNTING_TAX_RATE'] ?? '0.05'));
    if (!is_finite($taxRate) || $taxRate < 0) {
        $taxRate = 0.05;
    }

    return [
        'legal_name' => trim((string)(getenv('ACCOUNTING_LEGAL_NAME') ?: ($_ENV['ACCOUNTING_LEGAL_NAME'] ?? 'ТОВ «СтрІМ Алго»'))),
        'edrpou' => trim((string)(getenv('ACCOUNTING_EDRPOU') ?: ($_ENV['ACCOUNTING_EDRPOU'] ?? '46241790'))),
        'iban' => trim((string)(getenv('ACCOUNTING_IBAN') ?: ($_ENV['ACCOUNTING_IBAN'] ?? 'UA343052990000026003026120038'))),
        'bank_name' => trim((string)(getenv('ACCOUNTING_BANK_NAME') ?: ($_ENV['ACCOUNTING_BANK_NAME'] ?? 'АТ КБ «ПриватБанк»'))),
        'tax_group' => trim((string)(getenv('ACCOUNTING_TAX_GROUP') ?: ($_ENV['ACCOUNTING_TAX_GROUP'] ?? '3'))),
        'tax_system' => trim((string)(getenv('ACCOUNTING_TAX_SYSTEM') ?: ($_ENV['ACCOUNTING_TAX_SYSTEM'] ?? 'Спрощена система (єдиний податок)'))),
        'vat_mode' => trim((string)(getenv('ACCOUNTING_VAT_MODE') ?: ($_ENV['ACCOUNTING_VAT_MODE'] ?? 'without_vat'))),
        'vat_label' => 'без ПДВ',
        'subscription_uah' => $rate,
        'tax_rate' => $taxRate,
        'tax_rate_pct' => round($taxRate * 100, 2),
        'service_description' => 'Надання доступу до програмного сервісу StrIM (SaaS, спот-торгівля Binance)',
        'currency' => 'UAH',
    ];
}

function accounting_table_exists(mysqli $db, string $table): bool
{
    $table = preg_replace('/[^a-z0-9_]/', '', strtolower($table));
    if ($table === '') {
        return false;
    }
    $r = $db->query("SHOW TABLES LIKE '{$table}'");
    return (bool)($r && $r->num_rows > 0);
}

/** @return array<string,mixed> */
function accounting_parse_filters(array $in): array
{
    $source = strtolower(trim((string)($in['source'] ?? 'all')));
    if (!in_array($source, ['all', 'privat_bank', 'whitepay', 'admin_manual', 'pending_bank'], true)) {
        $source = 'all';
    }
    $status = strtolower(trim((string)($in['status'] ?? 'all')));
    $dateFrom = trim((string)($in['date_from'] ?? ''));
    $dateTo = trim((string)($in['date_to'] ?? ''));
    if ($dateFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
        $dateFrom = '';
    }
    if ($dateTo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
        $dateTo = '';
    }
    $q = trim((string)($in['q'] ?? ''));
    if (mb_strlen($q) > 120) {
        $q = mb_substr($q, 0, 120);
    }

    return [
        'source' => $source,
        'status' => $status,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'q' => $q,
        'limit' => max(10, min(500, (int)($in['limit'] ?? 100))),
        'offset' => max(0, (int)($in['offset'] ?? 0)),
    ];
}

/**
 * @return list<array<string,mixed>>
 */
function accounting_fetch_ledger(mysqli $db, array $filters): array
{
    $rows = [];

    if (
        accounting_table_exists($db, 'bank_incoming_payments')
        && in_array($filters['source'], ['all', 'privat_bank', 'pending_bank'], true)
    ) {
        $statusSql = '';
        if ($filters['source'] === 'pending_bank') {
            $statusSql = " AND b.status IN ('new','matched','ignored','ambiguous')";
        } elseif ($filters['status'] !== '' && $filters['status'] !== 'all') {
            $st = $db->real_escape_string($filters['status']);
            $statusSql = " AND b.status='{$st}'";
        }
        $dateSql = accounting_sql_date_range('COALESCE(b.tran_at, b.created_at)', $filters, $db);
        $qSql = accounting_sql_search($filters['q'], $db, [
            'b.osnd', 'b.payer_name', 'b.privat_ref', 'u.email', 'u.nickname',
        ]);
        $sql = "
            SELECT
              CONCAT('privat_', b.id) AS row_key,
              'privat_bank' AS source,
              b.id AS source_id,
              COALESCE(b.tran_at, b.created_at) AS paid_at,
              b.amount_uah,
              b.ccy AS currency,
              b.matched_user_id AS user_id,
              u.email AS user_email,
              u.nickname AS user_nickname,
              b.status,
              b.privat_ref AS tx_ref,
              b.privat_refn AS tx_sub_ref,
              b.payer_name,
              b.osnd AS purpose,
              b.match_note,
              b.applied_at,
              b.acc AS bank_account
            FROM bank_incoming_payments b
            LEFT JOIN users u ON u.id = b.matched_user_id
            WHERE 1=1 {$statusSql} {$dateSql} {$qSql}
        ";
        $r = $db->query($sql);
        while ($r && ($row = $r->fetch_assoc())) {
            $rows[] = accounting_normalize_row($row, 'privat_bank');
        }
    }

    if (
        accounting_table_exists($db, 'whitepay_orders')
        && in_array($filters['source'], ['all', 'whitepay'], true)
    ) {
        $statusSql = " AND w.status='paid'";
        if ($filters['status'] !== '' && $filters['status'] !== 'all' && $filters['status'] !== 'paid') {
            $statusSql = " AND 1=0";
        }
        $dateSql = accounting_sql_date_range('COALESCE(w.paid_at, w.created_at)', $filters, $db);
        $qSql = accounting_sql_search($filters['q'], $db, [
            'w.external_id', 'w.whitepay_invoice_id', 'u.email', 'u.nickname',
        ]);
        $sql = "
            SELECT
              CONCAT('whitepay_', w.id) AS row_key,
              'whitepay' AS source,
              w.id AS source_id,
              COALESCE(w.paid_at, w.created_at) AS paid_at,
              w.amount_uah,
              w.currency,
              w.user_id,
              u.email AS user_email,
              u.nickname AS user_nickname,
              w.status,
              w.external_id AS tx_ref,
              '' AS tx_sub_ref,
              '' AS payer_name,
              CONCAT('WhitePay crypto acquiring ', w.external_id) AS purpose,
              '' AS match_note,
              w.paid_at AS applied_at,
              '' AS bank_account
            FROM whitepay_orders w
            LEFT JOIN users u ON u.id = w.user_id
            WHERE 1=1 {$statusSql} {$dateSql} {$qSql}
        ";
        $r = $db->query($sql);
        while ($r && ($row = $r->fetch_assoc())) {
            $rows[] = accounting_normalize_row($row, 'whitepay');
        }
    }

    if (in_array($filters['source'], ['all', 'admin_manual'], true)) {
        $dateSql = accounting_sql_date_range('a.created_at', $filters, $db);
        $qSql = accounting_sql_search($filters['q'], $db, ['u.email', 'u.nickname', 'a.payload_json']);
        $sql = "
            SELECT
              CONCAT('admin_', a.id) AS row_key,
              'admin_manual' AS source,
              a.id AS source_id,
              a.created_at AS paid_at,
              " . (float)accounting_company_profile()['subscription_uah'] . " AS amount_uah,
              'UAH' AS currency,
              a.target_user_id AS user_id,
              u.email AS user_email,
              u.nickname AS user_nickname,
              a.result_status AS status,
              CONCAT('admin_', a.action_type, '_', a.id) AS tx_ref,
              '' AS tx_sub_ref,
              '' AS payer_name,
              a.action_type AS purpose,
              a.payload_json AS match_note,
              a.created_at AS applied_at,
              '' AS bank_account
            FROM admin_action_audit a
            LEFT JOIN users u ON u.id = a.target_user_id
            WHERE a.action_type = 'billing_add30'
              AND a.result_status IN ('ok','success')
              {$dateSql} {$qSql}
        ";
        if (accounting_table_exists($db, 'admin_action_audit')) {
            $r = $db->query($sql);
            while ($r && ($row = $r->fetch_assoc())) {
                $rows[] = accounting_normalize_row($row, 'admin_manual');
            }
        }
    }

    usort($rows, static function (array $a, array $b): int {
        return strcmp((string)($b['paid_at'] ?? ''), (string)($a['paid_at'] ?? ''));
    });

    $offset = (int)$filters['offset'];
    $limit = (int)$filters['limit'];
    return array_slice($rows, $offset, $limit);
}

/** @return array<string,mixed> */
function accounting_normalize_row(array $row, string $source): array
{
    $labels = [
        'privat_bank' => 'Банк (Приват)',
        'whitepay' => 'WhitePay (крипто)',
        'admin_manual' => 'Адмін (вручну)',
    ];
    $amount = (float)($row['amount_uah'] ?? 0);
    $profile = accounting_company_profile();
    $tax = round($amount * (float)$profile['tax_rate'], 2);
    $status = (string)($row['status'] ?? '');
    // Дохід = лише реальні гроші (банк / WhitePay). Адмін +30 — операційна дія, не надходження.
    $countsAsRevenue = match ($source) {
        'privat_bank' => in_array($status, ['applied', 'matched'], true),
        'whitepay' => $status === 'paid',
        'admin_manual' => false,
        default => false,
    };

    return [
        'row_key' => (string)($row['row_key'] ?? ''),
        'source' => $source,
        'source_label' => $labels[$source] ?? $source,
        'source_id' => (int)($row['source_id'] ?? 0),
        'paid_at' => (string)($row['paid_at'] ?? ''),
        'amount_uah' => $amount,
        'currency' => (string)($row['currency'] ?? 'UAH'),
        'user_id' => isset($row['user_id']) ? (int)$row['user_id'] : null,
        'user_email' => (string)($row['user_email'] ?? ''),
        'user_nickname' => (string)($row['user_nickname'] ?? ''),
        'status' => (string)($row['status'] ?? ''),
        'tx_ref' => (string)($row['tx_ref'] ?? ''),
        'tx_sub_ref' => (string)($row['tx_sub_ref'] ?? ''),
        'payer_name' => (string)($row['payer_name'] ?? ''),
        'purpose' => (string)($row['purpose'] ?? ''),
        'match_note' => (string)($row['match_note'] ?? ''),
        'applied_at' => (string)($row['applied_at'] ?? ''),
        'bank_account' => (string)($row['bank_account'] ?? ''),
        'tax_estimated_uah' => $tax,
        'counts_as_revenue' => $countsAsRevenue,
    ];
}

/**
 * @return list<array<string,mixed>>
 */
function accounting_fetch_pending_bank(mysqli $db, int $limit = 100): array
{
    if (!accounting_table_exists($db, 'bank_incoming_payments')) {
        return [];
    }
    $limit = max(1, min(500, $limit));
    $sql = "
        SELECT
          b.id, b.privat_ref, b.amount_uah, b.osnd, b.payer_name, b.status,
          b.matched_user_id, b.tran_at, b.created_at, b.match_note,
          u.email AS user_email
        FROM bank_incoming_payments b
        LEFT JOIN users u ON u.id = b.matched_user_id
        WHERE b.status IN ('new','matched','ambiguous','ignored')
        ORDER BY COALESCE(b.tran_at, b.created_at) DESC
        LIMIT {$limit}
    ";
    $rows = [];
    $r = $db->query($sql);
    while ($r && ($row = $r->fetch_assoc())) {
        $rows[] = $row;
    }
    return $rows;
}

/**
 * @return array{ok:bool, error?:string, paid_until?:string}
 */
function accounting_privat_manual_apply(mysqli $db, int $adminUid, int $bankId, int $userId): array
{
    if ($bankId <= 0 || $userId <= 0) {
        return ['ok' => false, 'error' => 'invalid_input'];
    }
    if (!accounting_table_exists($db, 'bank_incoming_payments')) {
        return ['ok' => false, 'error' => 'table_missing'];
    }

    require_once __DIR__ . '/_billing_extend.php';
    require_once __DIR__ . '/_admin_audit.php';

    $db->begin_transaction();
    try {
        $res = $db->query(
            'SELECT id, status, privat_ref, privat_refn, amount_uah, osnd FROM bank_incoming_payments
             WHERE id=' . (int)$bankId . ' LIMIT 1 FOR UPDATE'
        );
        $row = $res ? $res->fetch_assoc() : null;
        if (!$row) {
            $db->rollback();
            return ['ok' => false, 'error' => 'bank_row_not_found'];
        }
        if ((string)($row['status'] ?? '') === 'applied') {
            $db->rollback();
            return ['ok' => false, 'error' => 'already_applied'];
        }

        $txKey = trim((string)($row['privat_ref'] ?? '')) . ':' . trim((string)($row['privat_refn'] ?? '1'));
        $ext = billing_extend_subscription_30d($db, $userId, 'privat_bank', $txKey, 10.0);
        if (empty($ext['ok'])) {
            $db->rollback();
            return ['ok' => false, 'error' => (string)($ext['error'] ?? 'billing_failed')];
        }

        $note = $db->real_escape_string('manual_apply admin=' . $adminUid);
        $db->query(
            "UPDATE bank_incoming_payments
             SET status='applied', matched_user_id=" . (int)$userId . ",
                 applied_at=NOW(), match_note='{$note}', updated_at=NOW()
             WHERE id=" . (int)$bankId . " LIMIT 1"
        );

        admin_audit_log($db, $adminUid, 'privat_manual_apply', $userId, [
            'bank_id' => $bankId,
            'amount_uah' => (float)($row['amount_uah'] ?? 0),
            'osnd' => (string)($row['osnd'] ?? ''),
            'tx_key' => $txKey,
        ], 'ok');

        $db->commit();
        return ['ok' => true, 'paid_until' => (string)($ext['paid_until'] ?? '')];
    } catch (Throwable $e) {
        $db->rollback();
        return ['ok' => false, 'error' => 'exception'];
    }
}

/** @return array{ok:bool, error?:string} */
function accounting_privat_ignore(mysqli $db, int $adminUid, int $bankId, string $note = ''): array
{
    if ($bankId <= 0) {
        return ['ok' => false, 'error' => 'invalid_input'];
    }
    require_once __DIR__ . '/_admin_audit.php';
    $note = mb_substr(trim($note), 0, 240);
    $noteEsc = $db->real_escape_string($note !== '' ? $note : ('ignored admin=' . $adminUid));
    $ok = (bool)$db->query(
        "UPDATE bank_incoming_payments
         SET status='ignored', match_note='{$noteEsc}', updated_at=NOW()
         WHERE id=" . (int)$bankId . " AND status <> 'applied' LIMIT 1"
    );
    if (!$ok || $db->affected_rows < 1) {
        return ['ok' => false, 'error' => 'not_updated'];
    }
    admin_audit_log($db, $adminUid, 'privat_bank_ignore', 0, [
        'bank_id' => $bankId,
        'note' => $note,
    ], 'ok');
    return ['ok' => true];
}

function accounting_sql_date_range(string $col, array $filters, mysqli $db): string
{
    $out = '';
    if ($filters['date_from'] !== '') {
        $out .= ' AND ' . $col . " >= '" . $db->real_escape_string($filters['date_from']) . " 00:00:00'";
    }
    if ($filters['date_to'] !== '') {
        $out .= ' AND ' . $col . " <= '" . $db->real_escape_string($filters['date_to']) . " 23:59:59'";
    }
    return $out;
}

function accounting_sql_search(string $q, mysqli $db, array $cols): string
{
    if ($q === '') {
        return '';
    }
    $esc = $db->real_escape_string('%' . $q . '%');
    $parts = [];
    foreach ($cols as $c) {
        $parts[] = "{$c} LIKE '{$esc}'";
    }
    return ' AND (' . implode(' OR ', $parts) . ')';
}

/**
 * @param list<array<string,mixed>> $rows
 * @return array{revenue_uah:float,payment_count:int,by_source:array<string,float>}
 */
function accounting_summarize_ledger_rows(array $rows): array
{
    $bySource = ['privat_bank' => 0.0, 'whitepay' => 0.0, 'admin_manual' => 0.0];
    $count = 0;
    $revenue = 0.0;
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

    return [
        'revenue_uah' => round($revenue, 2),
        'payment_count' => $count,
        'by_source' => $bySource,
    ];
}

/** Останній місяць з банківським / WhitePay надходженням (для фільтра за замовчуванням). */
function accounting_default_activity_month(mysqli $db): string
{
    $latest = '';
    if (accounting_table_exists($db, 'bank_incoming_payments')) {
        $r = $db->query(
            "SELECT MAX(COALESCE(tran_at, created_at)) AS ts FROM bank_incoming_payments
             WHERE status IN ('applied','matched')"
        );
        $latest = (string)(($r && ($x = $r->fetch_assoc())) ? ($x['ts'] ?? '') : '');
    }
    if ($latest === '' && accounting_table_exists($db, 'whitepay_orders')) {
        $r = $db->query("SELECT MAX(COALESCE(paid_at, created_at)) AS ts FROM whitepay_orders WHERE status='paid'");
        $latest = (string)(($r && ($x = $r->fetch_assoc())) ? ($x['ts'] ?? '') : '');
    }
    if ($latest !== '' && preg_match('/^\d{4}-\d{2}/', $latest)) {
        return substr($latest, 0, 7);
    }

    return date('Y-m');
}

/** @return array<string,mixed> */
function accounting_dashboard_summary(mysqli $db): array
{
    $year = (int)date('Y');
    $quarter = (int)ceil((int)date('n') / 3);
    require_once __DIR__ . '/_accounting_declaration.php';
    $qDecl = accounting_quarter_revenue($db, $year, $quarter);

    $activityMonth = accounting_default_activity_month($db);
    $monthSummary = accounting_monthly_summary($db, $activityMonth);

    $bankUnapplied = 0;
    $bankUnappliedUah = 0.0;
    if (accounting_table_exists($db, 'bank_incoming_payments')) {
        $r = $db->query(
            "SELECT COUNT(*) c, COALESCE(SUM(amount_uah),0) s FROM bank_incoming_payments
             WHERE status IN ('new','matched','ambiguous')"
        );
        if ($r && ($x = $r->fetch_assoc())) {
            $bankUnapplied = (int)($x['c'] ?? 0);
            $bankUnappliedUah = (float)($x['s'] ?? 0);
        }
    }

    return [
        'quarter' => [
            'year' => $year,
            'quarter' => $quarter,
            'label' => (string)($qDecl['period']['label'] ?? ('Q' . $quarter . ' ' . $year)),
            'revenue_uah' => (float)($qDecl['revenue_uah'] ?? 0),
            'payment_count' => (int)($qDecl['payment_count'] ?? 0),
            'tax_estimated_uah' => (float)($qDecl['ep_tax_uah'] ?? 0) + (float)($qDecl['military_tax_uah'] ?? 0),
            'ep_tax_uah' => (float)($qDecl['ep_tax_uah'] ?? 0),
            'by_source' => $qDecl['by_source'] ?? [],
        ],
        'activity_month' => $activityMonth,
        'month' => $monthSummary,
        'bank_unapplied_rows' => $bankUnapplied,
        'bank_unapplied_uah' => round($bankUnappliedUah, 2),
        'revenue_basis' => 'Банк (applied/matched) + WhitePay (paid). Адмін +30 не входить у дохід.',
    ];
}

/** @return array<string,mixed> */
function accounting_monthly_summary(mysqli $db, ?string $month = null): array
{
    $month = $month ?: date('Y-m');
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        $month = date('Y-m');
    }
    $from = $month . '-01';
    $to = date('Y-m-t', strtotime($from . ' 12:00:00'));
    $filters = accounting_parse_filters([
        'source' => 'all',
        'status' => 'all',
        'date_from' => $from,
        'date_to' => $to,
        'limit' => 5000,
        'offset' => 0,
    ]);
    $rows = accounting_fetch_ledger($db, $filters);
    $profile = accounting_company_profile();
    $sum = accounting_summarize_ledger_rows($rows);
    $revenue = (float)$sum['revenue_uah'];
    $count = (int)$sum['payment_count'];
    $bySource = $sum['by_source'];

    $pendingBank = 0;
    if (accounting_table_exists($db, 'bank_incoming_payments')) {
        $r = $db->query(
            "SELECT COUNT(*) c FROM bank_incoming_payments
             WHERE status IN ('new','matched','ambiguous')"
        );
        $pendingBank = (int)(($r && ($x = $r->fetch_assoc())) ? ($x['c'] ?? 0) : 0);
    }

    $tax = round($revenue * (float)$profile['tax_rate'], 2);

    return [
        'month' => $month,
        'revenue_uah' => $revenue,
        'payment_count' => $count,
        'by_source' => $bySource,
        'tax_estimated_uah' => $tax,
        'net_after_tax_estimated_uah' => round($revenue - $tax, 2),
        'pending_bank_rows' => $pendingBank,
        'subscription_tariff_uah' => (float)$profile['subscription_uah'],
    ];
}

/**
 * Google Sheets webhook (бухгалтерія) — best effort.
 *
 * @return array{0:bool,1:string}
 */
function accounting_push_gsheets_webhook(array $payload): array
{
    $url = trim((string)(getenv('GSHEETS_WEBHOOK_URL') ?: ($_ENV['GSHEETS_WEBHOOK_URL'] ?? '')));
    if ($url === '') {
        return [false, 'webhook_url_missing'];
    }

    $secret = trim((string)(getenv('GSHEETS_WEBHOOK_SECRET') ?: ($_ENV['GSHEETS_WEBHOOK_SECRET'] ?? '')));
    if ($secret !== '') {
        $payload['secret'] = $secret;
    }

    $profile = accounting_company_profile();
    $payload['seller_name'] = $profile['legal_name'];
    $payload['seller_edrpou'] = $profile['edrpou'];
    $payload['seller_iban'] = $profile['iban'];
    $payload['vat_mode'] = $profile['vat_label'];

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        return [false, 'json_encode_failed'];
    }

    $sendOnce = static function () use ($url, $json): array {
        $ch = curl_init($url);
        if ($ch === false) {
            return [false, 'curl_init_failed'];
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        ]);
        $resp = (string)curl_exec($ch);
        $errNo = curl_errno($ch);
        $err = curl_error($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($errNo !== 0) {
            return [false, "curl_{$errNo}:{$err}", $http, $resp];
        }
        return [true, 'ok', $http, $resp];
    };

    [$ok, $msg, $http, $resp] = $sendOnce();
    if (!$ok && str_starts_with((string)$msg, 'curl_28:')) {
        usleep(300_000);
        [$ok, $msg, $http, $resp] = $sendOnce();
    }
    if (!$ok) {
        return [false, $msg];
    }
    if ($http < 200 || $http >= 300) {
        return [false, 'http_' . $http . ':' . mb_substr((string)$resp, 0, 300)];
    }
    return [true, 'ok'];
}

function accounting_notify_income(
    mysqli $db,
    int $userId,
    string $paymentSource,
    string $txRef = '',
    ?float $amountUah = null
): array {
    if ($userId <= 0) {
        return ['pushed' => false, 'detail' => 'invalid_user'];
    }
    $profile = accounting_company_profile();
    $amountUah = $amountUah ?? (float)$profile['subscription_uah'];
    $tax = round($amountUah * (float)$profile['tax_rate'], 2);

    $uRes = $db->query(
        'SELECT email, nickname, telegram, ref_code FROM users WHERE id=' . (int)$userId . ' LIMIT 1'
    );
    $uRow = $uRes ? $uRes->fetch_assoc() : null;
    if (!$uRow) {
        return ['pushed' => false, 'detail' => 'user_not_found'];
    }

    $methodLabels = [
        'privat_bank' => 'Банк (Приват)',
        'whitepay' => 'WhitePay',
        'manual' => 'Адмін (вручну)',
        'admin' => 'Адмін (вручну)',
    ];
    $method = $methodLabels[$paymentSource] ?? $paymentSource;
    $txid = $txRef !== '' ? $txRef : ($paymentSource . '_' . $userId . '_' . date('Ymd_His'));

    $payload = [
        'date' => date('Y-m-d H:i:s'),
        'client' => (string)($uRow['nickname'] ?? ('user_' . $userId)),
        'email' => (string)($uRow['email'] ?? ''),
        'telegram' => (string)($uRow['telegram'] ?? ''),
        'tariff' => '30 днів / StrIM',
        'amount_uah' => $amountUah,
        'currency' => 'UAH',
        'payment_method' => $method,
        'txid' => $txid,
        'ref_code' => (string)($uRow['ref_code'] ?? ''),
        'status' => 'Оплачено',
        'tax' => $tax,
        'comment' => 'Auto from STRIM (' . $paymentSource . ')',
        'Дата' => date('Y-m-d H:i:s'),
        'Клієнт' => (string)($uRow['nickname'] ?? ('user_' . $userId)),
        'Email' => (string)($uRow['email'] ?? ''),
        'Тариф' => '30 днів / StrIM',
        'Сума в грн' => $amountUah,
        'Спосіб оплати' => $method,
        'TX ID / ID платежу' => $txid,
        'Статус' => 'Оплачено',
        'Податок (оцінка)' => $tax,
    ];

    [$ok, $detail] = accounting_push_gsheets_webhook($payload);
    return ['pushed' => $ok, 'detail' => $detail];
}

function accounting_csv_stream(array $rows, array $profile): void
{
    $out = fopen('php://output', 'w');
    if ($out === false) {
        return;
    }
    fprintf($out, "\xEF\xBB\xBF");
    fputcsv($out, [
        'Дата операції',
        'Джерело',
        'Сума UAH',
        'Валюта',
        'Статус',
        'User ID',
        'Email',
        'Платник',
        'Призначення',
        'TX / Ref',
        'ЄДРПОУ продавця',
        'IBAN',
        'Податок (оцінка)',
        'Без ПДВ',
    ], ';');
    foreach ($rows as $r) {
        fputcsv($out, [
            (string)($r['paid_at'] ?? ''),
            (string)($r['source_label'] ?? ''),
            number_format((float)($r['amount_uah'] ?? 0), 2, '.', ''),
            (string)($r['currency'] ?? 'UAH'),
            (string)($r['status'] ?? ''),
            (string)($r['user_id'] ?? ''),
            (string)($r['user_email'] ?? ''),
            (string)($r['payer_name'] ?? ''),
            (string)($r['purpose'] ?? ''),
            (string)($r['tx_ref'] ?? ''),
            (string)($profile['edrpou'] ?? ''),
            (string)($profile['iban'] ?? ''),
            number_format((float)($r['tax_estimated_uah'] ?? 0), 2, '.', ''),
            (string)($profile['vat_label'] ?? 'без ПДВ'),
        ], ';');
    }
    fclose($out);
}
