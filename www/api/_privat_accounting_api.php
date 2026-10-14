<?php
declare(strict_types=1);

require_once __DIR__ . '/_privat_acp_client.php';
require_once __DIR__ . '/_accounting.php';

/** @return array<string,mixed> */
function privat_accounting_edrpou(): string
{
    $p = accounting_company_profile();
    return preg_replace('/\D/', '', (string)($p['edrpou'] ?? '')) ?: '46241790';
}

/** @return array<string,mixed> */
function privat_accounting_probe(): array
{
    $cfg = privat_acp_config();
    $iban = (string)($cfg['iban'] ?? '');
    $edrpou = privat_accounting_edrpou();
    $today = date('Y-m-d');
    $from = date('Y-m-d', strtotime('-30 days'));

    $out = [
        'token_set' => $cfg['token'] !== '',
        'client_id_set' => ($cfg['client_id'] ?? '') !== '',
        'iban' => $iban,
        'edrpou' => $edrpou,
        'services' => [],
    ];

    $bal = privat_acp_balance_interim($iban !== '' ? $iban : null);
    $out['services']['balance'] = [
        'ok' => !empty($bal['ok']),
        'detail' => $bal['ok'] ? 'ok' : ($bal['error'] ?? 'fail'),
        'count' => count($bal['balances'] ?? []),
    ];

    $set = privat_acp_statement_settings();
    $out['services']['statements_settings'] = [
        'ok' => !empty($set['ok']),
        'detail' => $set['ok'] ? 'ok' : ($set['error'] ?? 'fail'),
    ];

    $edoc = privat_acp_edoc_journal('all', $from, $today, [$edrpou], 5);
    $out['services']['edoc_journal'] = [
        'ok' => !empty($edoc['ok']),
        'detail' => $edoc['ok'] ? 'ok' : ($edoc['error'] ?? 'fail'),
    ];

    $out['services']['eo_upload'] = [
        'ok' => null,
        'detail' => 'перевіряється при завантаженні XML',
    ];

    return $out;
}

/** @return array{ok:bool, balances?:array, error?:string} */
function privat_accounting_balances(): array
{
    $iban = privat_acp_config()['iban'] ?? '';
    return privat_acp_balance_interim($iban !== '' ? $iban : null);
}

/**
 * @return array{ok:bool, rows?:array, total?:int, error?:string}
 */
function privat_accounting_edoc_list(string $journal, string $dateFrom, string $dateTo, int $limit = 80): array
{
    $edrpou = privat_accounting_edrpou();
    $r = privat_acp_edoc_journal($journal, $dateFrom, $dateTo, [$edrpou], $limit);
    if (!$r['ok']) {
        return $r;
    }
    $data = $r['data'] ?? [];
    $rows = $data['documents'] ?? $data['items'] ?? $data['data'] ?? [];
    if (!is_array($rows)) {
        $rows = [];
    }
    return [
        'ok' => true,
        'rows' => $rows,
        'total' => (int)($data['total'] ?? count($rows)),
        'raw_keys' => array_keys($data),
    ];
}

function accounting_privat_log(
    mysqli $db,
    int $adminUid,
    string $kind,
    string $fileName,
    string $periodKey,
    string $status,
    ?array $response,
    ?float $amountUah = null,
    string $privatRef = ''
): void {
    if (!accounting_table_exists($db, 'accounting_privat_documents')) {
        return;
    }
    $kind = substr(preg_replace('/[^a-z0-9_]/', '', strtolower($kind)) ?: 'misc', 0, 32);
    $fileName = substr($fileName, 0, 255);
    $periodKey = substr($periodKey, 0, 32);
    $status = substr($status, 0, 32);
    $privatRef = substr($privatRef, 0, 128);
    $json = $db->real_escape_string(json_encode($response ?? [], JSON_UNESCAPED_UNICODE) ?: '{}');
    $amt = $amountUah !== null ? (float)$amountUah : 'NULL';
    $amtSql = $amountUah !== null ? (string)$amt : 'NULL';
    $db->query(
        "INSERT INTO accounting_privat_documents
            (doc_kind, privat_ref, file_name, period_key, status, amount_uah, response_json, created_by)
         VALUES
            ('{$kind}', '" . $db->real_escape_string($privatRef) . "', '" . $db->real_escape_string($fileName) . "',
             '" . $db->real_escape_string($periodKey) . "', '" . $db->real_escape_string($status) . "',
             {$amtSql}, '{$json}', " . (int)$adminUid . ")"
    );
}

/** @return list<array<string,mixed>> */
function accounting_privat_documents_list(mysqli $db, int $limit = 50): array
{
    if (!accounting_table_exists($db, 'accounting_privat_documents')) {
        return [];
    }
    $limit = max(1, min(200, $limit));
    $rows = [];
    $r = $db->query(
        "SELECT id, doc_kind, privat_ref, file_name, period_key, status, amount_uah, created_at
         FROM accounting_privat_documents ORDER BY id DESC LIMIT {$limit}"
    );
    while ($r && ($row = $r->fetch_assoc())) {
        $rows[] = $row;
    }
    return $rows;
}
