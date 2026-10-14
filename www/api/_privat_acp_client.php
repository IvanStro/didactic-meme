<?php
declare(strict_types=1);

/**
 * Privat24 Autoclient API v3 (acp.privatbank.ua).
 * Док: https://docs.google.com/document/d/e/2PACX-1vTtKvGa3P4E-lDqLg3bHRF6Wi9S7GIjSMFEFxII5qQZBGxuTXs25hQNiUU1hMZQhOyx6BNvIZ1bVKSr/pub
 */

function privat_acp_config(): array
{
    $token = trim((string) (getenv('PRIVAT_ACP_TOKEN') ?: ($_ENV['PRIVAT_ACP_TOKEN'] ?? '')));
    $clientId = trim((string) (getenv('PRIVAT_ACP_CLIENT_ID') ?: ($_ENV['PRIVAT_ACP_CLIENT_ID'] ?? '')));
    $groupClientId = trim((string) (getenv('PRIVAT_ACP_GROUP_CLIENT_ID') ?: ($_ENV['PRIVAT_ACP_GROUP_CLIENT_ID'] ?? '')));
    // Часто копіюють «ID i Token» одним рядком у PRIVAT_ACP_TOKEN — прибираємо префікс UUID.
    if ($clientId !== '' && str_contains($clientId, '-') && str_starts_with($token, $clientId)) {
        $token = ltrim(substr($token, strlen($clientId)));
    }
    $iban = trim((string) (getenv('PRIVAT_ACP_IBAN') ?: ($_ENV['PRIVAT_ACP_IBAN'] ?? '')));
    if ($iban === '') {
        $iban = 'UA343052990000026003026120038';
    }
    $ua = trim((string) (getenv('PRIVAT_ACP_USER_AGENT') ?: ($_ENV['PRIVAT_ACP_USER_AGENT'] ?? 'StrIM-in.ua-billing/1.0')));

    return [
        'token' => $token,
        'client_id' => $clientId,
        'group_client_id' => $groupClientId,
        'iban' => strtoupper(preg_replace('/\s+/', '', $iban)),
        'user_agent' => $ua !== '' ? $ua : 'StrIM-in.ua-billing/1.0',
        'base' => 'https://acp.privatbank.ua',
    ];
}

/** @return array{ok:bool, data?:array, error?:string, http?:int} */
function privat_acp_get(string $path, array $query = []): array
{
    $cfg = privat_acp_config();
    if ($cfg['token'] === '') {
        return ['ok' => false, 'error' => 'missing_token'];
    }
    // Режим групи ПП: числовий id у query (?id=). «ID i Token» (UUID) — у header id:.
    if ($cfg['group_client_id'] !== '') {
        $query['id'] = $cfg['group_client_id'];
    }
    $url = rtrim($cfg['base'], '/') . '/' . ltrim($path, '/');
    if ($query !== []) {
        $url .= '?' . http_build_query($query);
    }

    $headers = [
        'User-Agent: ' . $cfg['user_agent'],
        'token: ' . $cfg['token'],
        'Content-Type: application/json;charset=utf8',
        'Accept: application/json',
    ];
    if ($cfg['client_id'] !== '') {
        $headers[] = 'id: ' . $cfg['client_id'];
    }

    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'error' => 'curl_init'];
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_CONNECTTIMEOUT => 12,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    $body = (string) curl_exec($ch);
    $err = curl_error($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === '' && $err !== '') {
        return ['ok' => false, 'error' => 'curl:' . $err, 'http' => $http];
    }

    $data = json_decode($body, true);
    if (!is_array($data)) {
        return ['ok' => false, 'error' => 'bad_json', 'http' => $http, 'raw' => substr($body, 0, 400)];
    }
    if ($http < 200 || $http >= 300) {
        return ['ok' => false, 'error' => 'http_' . $http, 'http' => $http, 'data' => $data];
    }
    if (($data['status'] ?? '') !== 'SUCCESS') {
        return ['ok' => false, 'error' => 'api_' . (string) ($data['status'] ?? 'fail'), 'data' => $data];
    }

    return ['ok' => true, 'data' => $data, 'http' => $http];
}

/** @return array{ok:bool, settings?:array, error?:string} */
function privat_acp_statement_settings(): array
{
    $r = privat_acp_get('/api/statements/settings');
    if (!$r['ok']) {
        return $r;
    }
    $settings = $r['data']['settings'] ?? $r['data'];
    return ['ok' => true, 'settings' => is_array($settings) ? $settings : []];
}

/**
 * @return array{ok:bool, transactions:array, error?:string}
 */
function privat_acp_fetch_transactions(string $acc, string $startDate, ?string $endDate = null, int $limit = 100): array
{
    $all = [];
    $followId = null;
    $loops = 0;
    do {
        $loops++;
        $q = [
            'acc' => $acc,
            'startDate' => $startDate,
            'limit' => max(1, min(100, $limit)),
        ];
        if ($endDate !== null && $endDate !== '') {
            $q['endDate'] = $endDate;
        }
        if ($followId !== null && $followId !== '') {
            $q['followId'] = $followId;
        }
        $r = privat_acp_get('/api/statements/transactions', $q);
        if (!$r['ok']) {
            return ['ok' => false, 'transactions' => $all, 'error' => $r['error'] ?? 'fetch_fail'];
        }
        $chunk = $r['data']['transactions'] ?? [];
        if (is_array($chunk)) {
            foreach ($chunk as $tx) {
                if (is_array($tx)) {
                    $all[] = $tx;
                }
            }
        }
        $hasNext = !empty($r['data']['exist_next_page']);
        $followId = $hasNext ? (string) ($r['data']['next_page_id'] ?? '') : '';
    } while ($followId !== '' && $loops < 50);

    return ['ok' => true, 'transactions' => $all];
}

/**
 * @return array{ok:bool, data?:array, error?:string, http?:int, raw?:string}
 */
function privat_acp_request(
    string $method,
    string $path,
    ?array $jsonBody = null,
    ?string $formBody = null,
    array $query = [],
    bool $requireSuccessStatus = true
): array {
    $cfg = privat_acp_config();
    if ($cfg['token'] === '') {
        return ['ok' => false, 'error' => 'missing_token'];
    }
    if ($cfg['group_client_id'] !== '') {
        $query['id'] = $cfg['group_client_id'];
    }
    $url = rtrim($cfg['base'], '/') . '/' . ltrim($path, '/');
    if ($query !== []) {
        $url .= '?' . http_build_query($query);
    }

    $headers = [
        'User-Agent: ' . $cfg['user_agent'],
        'token: ' . $cfg['token'],
        'Accept: application/json',
    ];
    if ($cfg['client_id'] !== '') {
        $headers[] = 'id: ' . $cfg['client_id'];
    }
    if ($jsonBody !== null) {
        $headers[] = 'Content-Type: application/json;charset=utf8';
    } elseif ($formBody !== null) {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    } else {
        $headers[] = 'Content-Type: application/json;charset=utf8';
    }

    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'error' => 'curl_init'];
    }
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_CONNECTTIMEOUT => 12,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
    ];
    if ($jsonBody !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($jsonBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } elseif ($formBody !== null) {
        $opts[CURLOPT_POSTFIELDS] = $formBody;
    }
    curl_setopt_array($ch, $opts);
    $body = (string) curl_exec($ch);
    $err = curl_error($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === '' && $err !== '') {
        return ['ok' => false, 'error' => 'curl:' . $err, 'http' => $http];
    }

    $data = json_decode($body, true);
    if (!is_array($data)) {
        return ['ok' => false, 'error' => 'bad_json', 'http' => $http, 'raw' => substr($body, 0, 800)];
    }
    if ($http < 200 || $http >= 300) {
        return ['ok' => false, 'error' => 'http_' . $http, 'http' => $http, 'data' => $data];
    }
    if ($requireSuccessStatus && isset($data['status']) && (string)$data['status'] !== 'SUCCESS') {
        return ['ok' => false, 'error' => 'api_' . (string)($data['status'] ?? 'fail'), 'data' => $data, 'http' => $http];
    }

    return ['ok' => true, 'data' => $data, 'http' => $http];
}

/** @return array{ok:bool, balances?:array, error?:string} */
function privat_acp_balance_interim(?string $acc = null): array
{
    $q = [];
    if ($acc !== null && $acc !== '') {
        $q['acc'] = $acc;
    }
    $r = privat_acp_request('GET', '/api/statements/balance/interim', null, null, $q, true);
    if (!$r['ok']) {
        return $r;
    }
    $raw = $r['data']['balances'] ?? [];
    if (!is_array($raw)) {
        $raw = [];
    }

    // API повертає balanceIn/balanceOut/dpd — нормалізуємо для UI; один рядок на рахунок (найсвіжіший dpd).
    $byAcc = [];
    foreach ($raw as $row) {
        if (!is_array($row)) {
            continue;
        }
        $iban = (string)($row['acc'] ?? $row['iban'] ?? '');
        $dpd = (string)($row['dpd'] ?? '');
        $prev = $byAcc[$iban]['_dpd'] ?? '';
        if ($iban !== '' && $prev !== '' && strcmp($dpd, $prev) < 0) {
            continue;
        }
        $out = (float)($row['balanceOut'] ?? $row['balanceOutEq'] ?? $row['balance'] ?? $row['rest'] ?? 0);
        $in = (float)($row['balanceIn'] ?? $row['balanceInEq'] ?? $out);
        $updated = $dpd;
        if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})/', $dpd, $m)) {
            $updated = $m[3] . '-' . $m[2] . '-' . $m[1];
        }
        $byAcc[$iban] = [
            'acc' => $iban,
            'iban' => $iban,
            'currency' => (string)($row['currency'] ?? $row['ccy'] ?? 'UAH'),
            'balance' => $out,
            'balance_in' => $in,
            'available' => $out,
            'name' => (string)($row['nameACC'] ?? $row['name'] ?? ''),
            'updated' => $updated,
            'dpd' => $dpd,
            'state' => (string)($row['state'] ?? ''),
            '_dpd' => $dpd,
        ];
    }
    foreach ($byAcc as &$item) {
        unset($item['_dpd']);
    }
    unset($item);

    return ['ok' => true, 'balances' => array_values($byAcc)];
}

/** @return array{ok:bool, data?:array, error?:string} */
function privat_acp_edoc_journal(string $journal, string $dateBegin, string $dateEnd, array $okpoList, int $limit = 50): array
{
    $journal = preg_replace('/[^a-z-]/', '', strtolower($journal)) ?: 'all';
    $allowed = ['inbox', 'outbox', 'in-process', 'all', 'to-pay'];
    if (!in_array($journal, $allowed, true)) {
        $journal = 'all';
    }
    $body = [
        'dateBegin' => $dateBegin,
        'dateEnd' => $dateEnd,
        'okpo' => array_values($okpoList),
        'limit' => (string)max(1, min(200, $limit)),
        'offset' => '0',
    ];
    return privat_acp_request('POST', '/api/proxy/edoc/journal/' . $journal, $body, null, [], false);
}

/** @return array{ok:bool, data?:array, error?:string} */
function privat_acp_eo_upload_b64(string $fileName, string $xmlContent, string $edrpou, string $duplicate = 'error'): array
{
    $edrpou = preg_replace('/\D/', '', $edrpou);
    if ($edrpou === '' || $fileName === '' || $xmlContent === '') {
        return ['ok' => false, 'error' => 'invalid_input'];
    }
    $duplicate = in_array($duplicate, ['error', 'remove'], true) ? $duplicate : 'error';
    $form = http_build_query([
        'uploadedFileB64' => base64_encode($xmlContent),
        'uploadedFileName' => $fileName,
        'okpo' => $edrpou,
        'duplicate' => $duplicate,
    ]);
    $r = privat_acp_request('POST', '/api/proxy/eo/documents/create-b64', null, $form, [], false);
    if (!$r['ok']) {
        return $r;
    }
    $data = $r['data'] ?? [];
    if (($data['result'] ?? '') === 'error') {
        return ['ok' => false, 'error' => (string)($data['error_message'] ?? 'eo_error'), 'data' => $data];
    }
    return ['ok' => true, 'data' => $data];
}
