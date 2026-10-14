<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../auth/_session_user.php';
require_once __DIR__ . '/_csrf.php';
require_once __DIR__ . '/_admin_audit.php';
require_once __DIR__ . '/_accounting.php';
require_once __DIR__ . '/_accounting_compliance.php';
require_once __DIR__ . '/_privat_accounting_api.php';
require_once __DIR__ . '/_accounting_declaration.php';

use Dotenv\Dotenv;

Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();

$mysqli = new mysqli(
    $_ENV['DB_HOST'],
    $_ENV['DB_USER'],
    $_ENV['DB_PASS'],
    $_ENV['DB_NAME']
);
if ($mysqli->connect_errno) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'db connect error']);
    exit;
}
$mysqli->set_charset('utf8mb4');

$uid = current_user_id($mysqli);
if ($uid <= 0) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'auth']);
    exit;
}
$admRes = $mysqli->query('SELECT is_admin FROM users WHERE id=' . (int)$uid . ' LIMIT 1');
$admRow = $admRes ? $admRes->fetch_assoc() : null;
if (!$admRow || (int)($admRow['is_admin'] ?? 0) !== 1) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

$action = strtolower(trim((string)($_REQUEST['action'] ?? 'list')));
$isMutating = in_array($action, [
    'privat_apply', 'privat_ignore', 'privat_poll', 'compliance_save', 'compliance_complete', 'eo_upload',
], true);
if ($isMutating) {
    require_csrf_or_403();
}

if ($action === 'declaration_export') {
    $year = (int)($_GET['year'] ?? date('Y'));
    $quarter = (int)($_GET['quarter'] ?? (int)ceil((int)date('n') / 3));
    $pkg = accounting_declaration_export_package($mysqli, $year, $quarter);
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $pkg['filename'] . '"');
    echo $pkg['content'];
    exit;
}

if ($action === 'export') {
    $filters = accounting_parse_filters($_GET);
    $filters['limit'] = 10000;
    $filters['offset'] = 0;
    $rows = accounting_fetch_ledger($mysqli, $filters);
    $profile = accounting_company_profile();
    $month = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['month'] ?? ''))
        ? (string)$_GET['month']
        : date('Y-m');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="strim_accounting_' . $month . '.csv"');
    accounting_csv_stream($rows, $profile);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

if ($action === 'profile') {
    $gsUrl = trim((string)(getenv('GSHEETS_WEBHOOK_URL') ?: ($_ENV['GSHEETS_WEBHOOK_URL'] ?? '')));
    echo json_encode([
        'ok' => true,
        'company' => accounting_company_profile(),
        'integrations' => [
            'privat_bank' => accounting_table_exists($mysqli, 'bank_incoming_payments'),
            'whitepay' => accounting_table_exists($mysqli, 'whitepay_orders'),
            'google_sheets' => $gsUrl !== '',
            'google_sheets_url_set' => $gsUrl !== '',
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'stats') {
    $month = trim((string)($_GET['month'] ?? ''));
    echo json_encode([
        'ok' => true,
        'dashboard' => accounting_dashboard_summary($mysqli),
        'summary' => $month !== ''
            ? accounting_monthly_summary($mysqli, $month)
            : accounting_monthly_summary($mysqli, accounting_default_activity_month($mysqli)),
        'company' => accounting_company_profile(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'pending_bank') {
    $rows = accounting_fetch_pending_bank($mysqli, (int)($_GET['limit'] ?? 200));
    echo json_encode(['ok' => true, 'rows' => $rows], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'compliance_settings') {
    $settings = accounting_compliance_load($mysqli);
    $year = (int)date('Y');
    $events = accounting_compliance_build_calendar($settings, $year);
    echo json_encode([
        'ok' => true,
        'settings' => $settings,
        'labels' => accounting_compliance_labels(),
        'company' => accounting_company_profile(),
        'privat_research' => accounting_compliance_privat_integration_research(),
        'timers' => accounting_compliance_calendar_stats($events),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'compliance_calendar') {
    $year = (int)($_GET['year'] ?? date('Y'));
    if ($year < 2020 || $year > 2035) {
        $year = (int)date('Y');
    }
    $settings = accounting_compliance_load($mysqli);
    $events = accounting_compliance_build_calendar($settings, $year);
    $monthlyRevenue = accounting_monthly_summary($mysqli, date('Y-m'));
    echo json_encode([
        'ok' => true,
        'year' => $year,
        'events' => $events,
        'stats' => accounting_compliance_calendar_stats($events),
        'revenue_hint' => [
            'month' => $monthlyRevenue['month'] ?? '',
            'revenue_uah' => $monthlyRevenue['revenue_uah'] ?? 0,
            'tax_estimated_uah' => $monthlyRevenue['tax_estimated_uah'] ?? 0,
        ],
        'privat_research' => accounting_compliance_privat_integration_research(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'compliance_save') {
    $raw = $_POST['settings'] ?? '';
    if (is_array($raw)) {
        $decoded = $raw;
    } else {
        $decoded = json_decode((string)$raw, true);
    }
    if (!is_array($decoded)) {
        echo json_encode(['ok' => false, 'error' => 'invalid_settings']);
        exit;
    }
    $ok = accounting_compliance_save($mysqli, $decoded, $uid);
    admin_audit_log($mysqli, $uid, 'accounting_compliance_save', 0, ['ok' => $ok], $ok ? 'ok' : 'db_error');
    echo json_encode(['ok' => $ok, 'settings' => accounting_compliance_load($mysqli)], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'compliance_complete') {
    $eventKey = trim((string)($_POST['event_key'] ?? ''));
    $done = (int)($_POST['done'] ?? 1) === 1;
    if ($eventKey === '' || mb_strlen($eventKey) > 120) {
        echo json_encode(['ok' => false, 'error' => 'invalid_event']);
        exit;
    }
    $settings = accounting_compliance_load($mysqli);
    $keys = array_map('strval', (array)($settings['completed_event_keys'] ?? []));
    if ($done) {
        if (!in_array($eventKey, $keys, true)) {
            $keys[] = $eventKey;
        }
    } else {
        $keys = array_values(array_filter($keys, static fn(string $k): bool => $k !== $eventKey));
    }
    $settings['completed_event_keys'] = $keys;
    $ok = accounting_compliance_save($mysqli, $settings, $uid);
    echo json_encode(['ok' => $ok], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'privat_poll') {
    require_once __DIR__ . '/_privat_bank_billing.php';
    $days = max(1, min(30, (int)($_POST['lookback_days'] ?? 7)));
    $stats = privat_bank_poll_and_process($mysqli, $days);
    admin_audit_log($mysqli, $uid, 'accounting_privat_poll', 0, [
        'lookback_days' => $days,
        'stats' => $stats,
    ], 'ok');
    echo json_encode(['ok' => true, 'stats' => $stats], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'privat_apply') {
    $bankId = (int)($_POST['bank_id'] ?? 0);
    $userId = (int)($_POST['user_id'] ?? 0);
    $result = accounting_privat_manual_apply($mysqli, $uid, $bankId, $userId);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'privat_ignore') {
    $bankId = (int)($_POST['bank_id'] ?? 0);
    $note = trim((string)($_POST['note'] ?? ''));
    $result = accounting_privat_ignore($mysqli, $uid, $bankId, $note);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'privat_probe') {
    echo json_encode(['ok' => true, 'probe' => privat_accounting_probe()], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'privat_balance') {
    $bal = privat_accounting_balances();
    echo json_encode(['ok' => !empty($bal['ok']), 'balances' => $bal['balances'] ?? [], 'error' => $bal['error'] ?? null], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'edoc_journal') {
    $journal = trim((string)($_GET['journal'] ?? 'all'));
    $from = trim((string)($_GET['date_from'] ?? date('Y-m-d', strtotime('-90 days'))));
    $to = trim((string)($_GET['date_to'] ?? date('Y-m-d')));
    $list = privat_accounting_edoc_list($journal, $from, $to, (int)($_GET['limit'] ?? 80));
    echo json_encode($list, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'declaration_preview') {
    $year = (int)($_GET['year'] ?? date('Y'));
    $quarter = (int)($_GET['quarter'] ?? (int)ceil((int)date('n') / 3));
    $summary = accounting_quarter_revenue($mysqli, $year, $quarter);
    echo json_encode([
        'ok' => true,
        'declaration' => $summary,
        'documents' => accounting_privat_documents_list($mysqli, 30),
        'privat24_eo_url' => 'https://24.privatbank.ua/',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'privat_documents') {
    echo json_encode(['ok' => true, 'rows' => accounting_privat_documents_list($mysqli, 100)], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'eo_upload') {
    $year = (int)($_POST['year'] ?? 0);
    $quarter = (int)($_POST['quarter'] ?? 0);
    $periodKey = $year > 0 && $quarter > 0 ? ($year . '-Q' . $quarter) : date('Y-m');
    $xml = '';
    $fileName = '';

    if (!empty($_FILES['xml_file']['tmp_name']) && is_uploaded_file($_FILES['xml_file']['tmp_name'])) {
        $xml = (string)file_get_contents($_FILES['xml_file']['tmp_name']);
        $fileName = basename((string)($_FILES['xml_file']['name'] ?? 'report.xml'));
    } else {
        $xml = (string)($_POST['xml_content'] ?? '');
        $fileName = trim((string)($_POST['file_name'] ?? 'strim_report.xml'));
    }

    if ($xml === '' || strlen($xml) < 32) {
        echo json_encode(['ok' => false, 'error' => 'empty_xml']);
        exit;
    }
    if (!str_ends_with(strtolower($fileName), '.xml')) {
        $fileName .= '.xml';
    }

    $edrpou = privat_accounting_edrpou();
    $upload = privat_acp_eo_upload_b64($fileName, $xml, $edrpou, 'error');
    $status = !empty($upload['ok']) ? 'uploaded' : 'error';
    accounting_privat_log(
        $mysqli,
        $uid,
        'eo_xml',
        $fileName,
        $periodKey,
        $status,
        $upload['data'] ?? ['error' => $upload['error'] ?? ''],
        null,
        (string)(($upload['data']['documents'][0]['id'] ?? '') ?: '')
    );
    admin_audit_log($mysqli, $uid, 'accounting_eo_upload', 0, [
        'file' => $fileName,
        'period' => $periodKey,
        'ok' => !empty($upload['ok']),
    ], $status);

    echo json_encode([
        'ok' => !empty($upload['ok']),
        'error' => $upload['error'] ?? null,
        'data' => $upload['data'] ?? null,
        'next_step' => 'Підпишіть і надішліть звіт у Приват24 → Електронна звітність',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$filters = accounting_parse_filters($_GET);
$limit = (int)$filters['limit'];
$filters['limit'] = $limit + 1;
$all = accounting_fetch_ledger($mysqli, $filters);
$hasMore = count($all) > $limit;
if ($hasMore) {
    array_pop($all);
}
$filters['limit'] = $limit;

echo json_encode([
    'ok' => true,
    'rows' => $all,
    'has_more' => $hasMore,
    'filters' => $filters,
], JSON_UNESCAPED_UNICODE);
