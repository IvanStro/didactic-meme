<?php
declare(strict_types=1);

/**
 * Клієнт API ADM.TOOLS (Hosting Ukraine).
 * Документація: https://adm.tools/user/api/
 * Каталог: ~679 actions (hosting/dns/mail/billing/…).
 */

function strim_adm_tools_token(): string
{
    return trim((string) ($_ENV['ADM.TOOLS_TOKEN'] ?? getenv('ADM.TOOLS_TOKEN') ?: ''));
}

/**
 * Опційний override: ADM.TOOLS_ACCOUNT_ID у .env (інакше береться з hosting/account/list).
 */
function strim_adm_tools_configured_account_id(): int
{
    $raw = trim((string) ($_ENV['ADM.TOOLS_ACCOUNT_ID'] ?? getenv('ADM.TOOLS_ACCOUNT_ID') ?: ''));
    return $raw !== '' && ctype_digit($raw) ? (int) $raw : 0;
}

/**
 * @return array{ok:bool, http:int, result:bool, response:array<string,mixed>|list<mixed>, error?:string, rate?:array<string,int|string>}
 */
function strim_adm_tools_call(string $action, array $post = []): array
{
    $token = strim_adm_tools_token();
    if ($token === '') {
        return ['ok' => false, 'http' => 0, 'result' => false, 'response' => [], 'error' => 'token_missing'];
    }

    $action = trim($action, '/');
    $ch = curl_init('https://adm.tools/action/' . $action . '/');
    if ($ch === false) {
        return ['ok' => false, 'http' => 0, 'result' => false, 'response' => [], 'error' => 'curl_init'];
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
        CURLOPT_POSTFIELDS => http_build_query($post),
        CURLOPT_HEADER => true,
    ]);

    $raw = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    if (!is_string($raw)) {
        return ['ok' => false, 'http' => $http, 'result' => false, 'response' => [], 'error' => 'curl_failed'];
    }

    $headerBlock = substr($raw, 0, $headerSize);
    $body = substr($raw, $headerSize);
    $rate = [];
    foreach (preg_split('/\r?\n/', $headerBlock) ?: [] as $line) {
        if (preg_match('/^X-RateLimit-Limit:\s*(\S+)/i', $line, $m)) {
            $rate['limit'] = is_numeric($m[1]) ? (int) $m[1] : $m[1];
        } elseif (preg_match('/^X-RateLimit-Remaining:\s*(\S+)/i', $line, $m)) {
            $rate['remaining'] = is_numeric($m[1]) ? (int) $m[1] : $m[1];
        } elseif (preg_match('/^Retry-After:\s*(\S+)/i', $line, $m)) {
            $rate['retry_after'] = is_numeric($m[1]) ? (int) $m[1] : $m[1];
        } elseif (preg_match('/^X-RateLimit-(Hour|Day)(?:-Remaining)?:\s*(\S+)/i', $line, $m)) {
            $rate[strtolower($m[1])] = is_numeric($m[2]) ? (int) $m[2] : $m[2];
        }
    }

    $json = json_decode($body, true);
    if (!is_array($json)) {
        return ['ok' => false, 'http' => $http, 'result' => false, 'response' => [], 'error' => 'invalid_json', 'rate' => $rate];
    }

    $result = !empty($json['result']);
    $err = null;
    if (!$result) {
        $msgs = $json['messages']['error'] ?? $json['error'] ?? null;
        if (is_array($msgs)) {
            $err = implode('; ', array_map('strval', $msgs));
        } elseif (is_string($msgs)) {
            $err = $msgs;
        } else {
            $err = 'api_error';
        }
    }

    $response = $json['response'] ?? [];
    if (!is_array($response)) {
        $response = [];
    }

    return [
        'ok' => $http >= 200 && $http < 300 && $result,
        'http' => $http,
        'result' => $result,
        'response' => $response,
        'error' => $err,
        'rate' => $rate,
    ];
}

// ─── Typed wrappers (read) ───────────────────────────────────────────────────

/** @return array{ok:bool, http:int, result:bool, response:array<string,mixed>|list<mixed>, error?:string, rate?:array<string,int|string>} */
function strim_adm_tools_get_services(string $type): array
{
    return strim_adm_tools_call('get_services', ['type' => $type]);
}

function strim_adm_tools_balance_get(): array
{
    return strim_adm_tools_call('billing/balance_get');
}

function strim_adm_tools_balance_history(int $page = 1): array
{
    return strim_adm_tools_call('billing/balance_history', ['page' => max(1, $page)]);
}

function strim_adm_tools_autorenew_hosting(): array
{
    return strim_adm_tools_call('billing/autorenew/list/hosting');
}

function strim_adm_tools_dns_list(): array
{
    return strim_adm_tools_call('dns/list');
}

function strim_adm_tools_dns_records_list(int $domainId): array
{
    return strim_adm_tools_call('dns/records_list', ['domain_id' => $domainId]);
}

function strim_adm_tools_mail_list(): array
{
    return strim_adm_tools_call('mail/list');
}

function strim_adm_tools_mail_box_list(int $mailId): array
{
    return strim_adm_tools_call('mail/box/list', ['mail_id' => $mailId]);
}

function strim_adm_tools_hosting_account_list(): array
{
    return strim_adm_tools_call('hosting/account/list');
}

function strim_adm_tools_hosting_quota(int $accountId): array
{
    return strim_adm_tools_call('hosting/account/quota', ['account_id' => $accountId]);
}

function strim_adm_tools_hosting_diskspace(int $accountId): array
{
    return strim_adm_tools_call('hosting/account/diskspace', ['account_id' => $accountId]);
}

function strim_adm_tools_hosting_plan_info(int $accountId): array
{
    return strim_adm_tools_call('hosting/account/plan/info', ['account_id' => $accountId]);
}

function strim_adm_tools_hosting_server_status(int $accountId): array
{
    return strim_adm_tools_call('hosting/account/server_status', ['account_id' => $accountId]);
}

function strim_adm_tools_hosting_virtual_list(int $accountId): array
{
    return strim_adm_tools_call('hosting/virtual/list', ['account_id' => $accountId]);
}

function strim_adm_tools_hosting_supervisor_list(int $accountId): array
{
    return strim_adm_tools_call('hosting/supervisor/list', ['account_id' => $accountId]);
}

function strim_adm_tools_hosting_crontab_list(int $accountId): array
{
    return strim_adm_tools_call('hosting/crontab/list', ['account_id' => $accountId]);
}

function strim_adm_tools_hosting_ftp_list(int $accountId): array
{
    return strim_adm_tools_call('hosting/ftp/list', ['account_id' => $accountId]);
}

function strim_adm_tools_extra_list(int $accountId): array
{
    return strim_adm_tools_call('extra/list', ['account_id' => $accountId]);
}

// ─── Typed wrappers (write / ops) ─────────────────────────────────────────────

function strim_adm_tools_supervisor_edit(
    int $accountId,
    string $command,
    int $numprocs = 1,
    ?int $taskId = null,
    string $comment = '',
    bool $active = true,
    bool $disableHandle = true
): array {
    $post = [
        'account_id' => $accountId,
        'command' => $command,
        'numprocs' => max(1, $numprocs),
        'comment' => $comment,
        'active' => $active ? 1 : 0,
        'disable_handle' => $disableHandle ? 1 : 0,
    ];
    if ($taskId !== null && $taskId > 0) {
        $post['task_id'] = $taskId;
    }
    return strim_adm_tools_call('hosting/supervisor/edit', $post);
}

function strim_adm_tools_supervisor_task_delete(int $taskId): array
{
    return strim_adm_tools_call('hosting/supervisor/task_delete', ['task_id' => $taskId]);
}

function strim_adm_tools_supervisor_restart(int $taskId): array
{
    return strim_adm_tools_call('hosting/supervisor/restart', ['task_id' => $taskId]);
}

function strim_adm_tools_supervisor_update_active(int $taskId, bool $active): array
{
    return strim_adm_tools_call('hosting/supervisor/update_active', [
        'task_id' => $taskId,
        'active' => $active ? 1 : 0,
    ]);
}

function strim_adm_tools_supervisor_log(int $taskId): array
{
    return strim_adm_tools_call('hosting/supervisor/log', ['task_id' => $taskId]);
}

function strim_adm_tools_crontab_edit(
    int $accountId,
    string $task,
    string $minute = '*',
    string $hour = '*',
    string $day = '*',
    string $month = '*',
    string $week = '*',
    ?int $taskId = null,
    string $comment = '',
    bool $active = true,
    bool $disableHandle = true
): array {
    $post = [
        'account_id' => $accountId,
        'task' => $task,
        'minute' => $minute,
        'hour' => $hour,
        'day' => $day,
        'month' => $month,
        'week' => $week,
        'comment' => $comment,
        'active' => $active ? 1 : 0,
        'disable_handle' => $disableHandle ? 1 : 0,
    ];
    if ($taskId !== null && $taskId > 0) {
        $post['task_id'] = $taskId;
    }
    return strim_adm_tools_call('hosting/crontab/edit', $post);
}

function strim_adm_tools_crontab_update_active(int $taskId, bool $active): array
{
    return strim_adm_tools_call('hosting/crontab/update_active', [
        'task_id' => $taskId,
        'active' => $active ? 1 : 0,
    ]);
}

function strim_adm_tools_crontab_task_test(int $taskId): array
{
    return strim_adm_tools_call('hosting/crontab/task_test', ['task_id' => $taskId]);
}

function strim_adm_tools_redis_restart(int $accountId): array
{
    return strim_adm_tools_call('hosting/redis/restart', ['account_id' => $accountId]);
}

function strim_adm_tools_extra_redis_systemd_start(int $accountId): array
{
    return strim_adm_tools_call('extra/redis/systemd_start', ['account_id' => $accountId]);
}

/**
 * Знайти account_id (bot20). Пріоритет: явний аргумент → .env → перший owned у list.
 *
 * @return array{ok:bool, account_id:int, account:?array<string,mixed>, error?:string}
 */
function strim_adm_tools_resolve_account(?int $preferredId = null): array
{
    $want = $preferredId && $preferredId > 0
        ? $preferredId
        : strim_adm_tools_configured_account_id();

    $listCall = strim_adm_tools_hosting_account_list();
    if (!$listCall['ok']) {
        return [
            'ok' => false,
            'account_id' => 0,
            'account' => null,
            'error' => (string) ($listCall['error'] ?? 'account_list_failed'),
        ];
    }

    $list = $listCall['response']['list'] ?? [];
    if (!is_array($list) || $list === []) {
        return ['ok' => false, 'account_id' => 0, 'account' => null, 'error' => 'no_hosting_accounts'];
    }

    $picked = null;
    foreach ($list as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (int) ($row['id'] ?? 0);
        if ($want > 0 && $id === $want) {
            $picked = $row;
            break;
        }
    }
    if ($picked === null) {
        foreach ($list as $row) {
            if (is_array($row) && (string) ($row['is_owner'] ?? '0') === '1') {
                $picked = $row;
                break;
            }
        }
    }
    if ($picked === null && is_array($list[0] ?? null)) {
        $picked = $list[0];
    }
    if ($picked === null) {
        return ['ok' => false, 'account_id' => 0, 'account' => null, 'error' => 'account_not_found'];
    }

    return [
        'ok' => true,
        'account_id' => (int) ($picked['id'] ?? 0),
        'account' => $picked,
    ];
}

/** Коротка назва команди Supervisor/cron (скрипт, не php/bash). */
function strim_adm_tools_task_short(string $cmd): string
{
    $cmd = trim(html_entity_decode($cmd, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($cmd === '') {
        return '';
    }
    if (preg_match('#(/home/[^\s]+\.(?:php|sh)|(?:^|\s)(run/[^\s]+))#', $cmd, $m)) {
        $path = $m[1] !== '' ? $m[1] : ($m[2] ?? '');
        return basename(trim($path));
    }
    $parts = preg_split('/\s+/', $cmd) ?: [];
    $last = (string) end($parts);
    return basename($last !== '' ? $last : $cmd);
}

/**
 * Сайти/піддомени з hosting/virtual/list → плоский список.
 *
 * @param array<string,mixed> $response
 * @return list<array{id:int, fqdn:string, domain:string, php_version:string, https_redirect:string}>
 */
function strim_adm_tools_flatten_virtual_hosts(array $response): array
{
    $out = [];
    $callback = $response['callback'] ?? $response;
    if (!is_array($callback)) {
        return $out;
    }
    foreach ($callback as $hosts) {
        if (!is_array($hosts)) {
            continue;
        }
        foreach ($hosts as $host) {
            if (!is_array($host)) {
                continue;
            }
            $out[] = [
                'id' => (int) ($host['id'] ?? 0),
                'fqdn' => (string) ($host['fqdn'] ?? $host['host'] ?? ''),
                'domain' => (string) ($host['domain'] ?? ''),
                'php_version' => (string) ($host['php_version'] ?? ''),
                'https_redirect' => (string) ($host['https_redirect'] ?? ''),
            ];
        }
    }
    usort($out, static fn ($a, $b) => strcmp($a['fqdn'], $b['fqdn']));
    return $out;
}

function strim_adm_tools_project_root(): string
{
    return dirname(__DIR__, 2);
}

function strim_adm_tools_snapshot_path(): string
{
    return strim_adm_tools_project_root() . '/logs/hosting_status_snapshot.json';
}

/**
 * @param array<string,mixed> $snapshot
 * @return list<array{at:string, title:string, detail:string}>
 */
function strim_adm_tools_diff_snapshots(?array $prev, array $current): array
{
    if ($prev === null) {
        return [];
    }

    $changes = [];
    $pairs = [
        ['mx_primary', 'Головний MX (вхідна пошта)'],
        ['balance_uah', 'Баланс на хостингу'],
        ['domain_expires', 'Оплата домену strim.in.ua'],
        ['support_mailbox', 'Скринька support@'],
        ['spf_ok', 'Запис SPF (відправка листів)'],
        ['dkim_ok', 'Запис DKIM (підпис листів)'],
        ['workers_running', 'Процесів торгового бота'],
        ['account_days_left', 'Днів до кінця тарифу хостингу'],
        ['quota_used_percent', 'Використання диска (%)'],
        ['supervisor_active', 'Активних процесів Supervisor'],
        ['cron_active', 'Активних завдань cron'],
        ['redis_extra', 'Додаткова послуга Redis'],
    ];

    foreach ($pairs as [$key, $title]) {
        $old = $prev[$key] ?? null;
        $new = $current[$key] ?? null;
        if ($old === null || $new === null || $old === $new) {
            continue;
        }
        $changes[] = [
            'at' => (string) ($current['checked_at'] ?? date('Y-m-d H:i:s')),
            'title' => $title,
            'detail' => 'Було: ' . (is_bool($old) ? ($old ? 'так' : 'ні') : (string) $old)
                . ' → стало: ' . (is_bool($new) ? ($new ? 'так' : 'ні') : (string) $new),
        ];
    }

    return $changes;
}

/**
 * Повний звіт для адмінки.
 *
 * @return array<string,mixed>
 */
function strim_adm_tools_hosting_report(string $primaryDomain = 'strim.in.ua'): array
{
    $checkedAt = date('Y-m-d H:i:s');
    $token = strim_adm_tools_token();
    $checks = [];
    $push = static function (array &$list, string $key, string $label, string $status, string $detail, string $hint = ''): void {
        $list[] = [
            'key' => $key,
            'label' => $label,
            'status' => $status,
            'detail' => $detail,
            'hint' => $hint,
        ];
    };

    $mailConfigured = trim((string) ($_ENV['MAIL_SMTP_USER'] ?? '')) !== ''
        && trim((string) ($_ENV['MAIL_SMTP_PASS'] ?? '')) !== '';
    $mailTransport = trim((string) ($_ENV['MAIL_TRANSPORT'] ?? ''));
    if ($mailTransport === '' && $mailConfigured) {
        $mailTransport = 'smtp';
    }

    if ($token === '') {
        $push($checks, 'api_token', 'Звʼязок з панеллю хостингу', 'warn', 'Токен ADM.TOOLS_TOKEN не заданий у .env', 'Додайте токен з adm.tools → API');
        return strim_adm_tools_build_report($checkedAt, $checks, [], $mailConfigured, $mailTransport, $primaryDomain);
    }

    $bal = strim_adm_tools_balance_get();
    $balance = null;
    if ($bal['ok']) {
        $balance = (string) ($bal['response']['balance'] ?? '0');
        $balF = (float) $balance;
        if ($balF < 50) {
            $push($checks, 'balance', 'Гроші на рахунку хостингу', 'warn', $balance . ' грн — мало для продовження домену', 'Поповніть баланс у ADM.TOOLS');
        } else {
            $push($checks, 'balance', 'Гроші на рахунку хостингу', 'ok', $balance . ' грн', '');
        }
    } else {
        $push($checks, 'balance', 'Гроші на рахунку хостингу', 'fail', (string) ($bal['error'] ?? 'помилка API'), '');
    }

    $domains = strim_adm_tools_dns_list();
    $domainId = null;
    $domainExpiry = null;
    $domainExpired = false;
    $allDomains = [];
    if ($domains['ok'] && isset($domains['response']['list']) && is_array($domains['response']['list'])) {
        foreach ($domains['response']['list'] as $name => $info) {
            if (!is_array($info)) {
                continue;
            }
            $allDomains[] = [
                'name' => (string) $name,
                'expires' => (string) ($info['valid_untill_formatted'] ?? $info['valid_untill'] ?? '—'),
                'paid' => ($info['domain_paid'] ?? '0') === '1',
            ];
            if ($name === $primaryDomain) {
                $domainId = (int) ($info['domain_id'] ?? $info['id'] ?? 0);
                $domainExpiry = (string) ($info['valid_untill_formatted'] ?? $info['valid_untill'] ?? '');
                $domainExpired = ($info['expired'] ?? '0') === '1' || ($info['expired'] ?? false) === true;
            }
        }
    }

    if ($domainId === null || $domainId <= 0) {
        $push($checks, 'domain', 'Домен ' . $primaryDomain, 'fail', 'Не знайдено в акаунті хостингу', '');
    } elseif ($domainExpired) {
        $push($checks, 'domain', 'Домен ' . $primaryDomain, 'fail', 'Термін дії закінчився', 'Продовжіть домен у панелі');
    } else {
        $push($checks, 'domain', 'Домен ' . $primaryDomain, 'ok', 'Оплачено до ' . ($domainExpiry ?: '—'), '');
    }

    $mxPrimary = '';
    $spfOk = false;
    $dkimOk = false;
    $dnsRecords = [];
    if ($domainId > 0) {
        $rec = strim_adm_tools_dns_records_list($domainId);
        if ($rec['ok'] && isset($rec['response']['list']) && is_array($rec['response']['list'])) {
            foreach ($rec['response']['list'] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $type = strtoupper((string) ($row['type'] ?? ''));
                $data = strtolower(rtrim((string) ($row['data'] ?? ''), '.'));
                $record = (string) ($row['record'] ?? '@');
                $dnsRecords[] = [
                    'type' => $type,
                    'name' => $record === '@' ? $primaryDomain : ($record . '.' . $primaryDomain),
                    'value' => (string) ($row['data'] ?? ''),
                    'priority' => (string) ($row['prioritet'] ?? $row['priority'] ?? ''),
                ];
                if ($type === 'MX') {
                    $prio = (int) ($row['prioritet'] ?? $row['priority'] ?? 99);
                    if ($mxPrimary === '' || $prio < (int) ($mxPrimary['prio'] ?? 99)) {
                        $mxPrimary = ['host' => $data, 'prio' => $prio];
                    }
                    if (str_contains($data, 'google')) {
                        $push($checks, 'mx_google', 'Пошта не на хостингу', 'fail',
                            'MX вказує на Google (' . $data . ') — листи на support@ не потраплять у WebMail ADM.TOOLS',
                            'Видаліть smtp.google.com, залиште mx.services');
                    }
                }
                if ($type === 'TXT' && $record === '@' && str_contains((string) ($row['data'] ?? ''), 'v=spf1')) {
                    $spfOk = str_contains((string) ($row['data'] ?? ''), '_spf.ukraine.com.ua');
                }
            }
            foreach ($rec['response']['list'] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                if (strtoupper((string) ($row['type'] ?? '')) === 'TXT'
                    && str_contains((string) ($row['record'] ?? ''), 'domainkey')) {
                    $dkimOk = str_contains((string) ($row['data'] ?? ''), 'v=DKIM1');
                }
            }
        }

        $mxHost = is_array($mxPrimary) ? (string) ($mxPrimary['host'] ?? '') : '';
        if ($mxHost === '') {
            $push($checks, 'mx', 'Вхідна пошта (MX)', 'fail', 'MX-запис не знайдено', 'Додайте mx.services');
        } elseif (str_contains($mxHost, 'mx.services') || str_contains($mxHost, 'ukraine.com')) {
            $push($checks, 'mx', 'Вхідна пошта (MX)', 'ok', 'Надходить на хостинг (' . $mxHost . ')', '');
        } else {
            $push($checks, 'mx', 'Вхідна пошта (MX)', 'warn', 'Нестандартний MX: ' . $mxHost, '');
        }

        $push($checks, 'spf', 'Дозвіл на відправку листів (SPF)', $spfOk ? 'ok' : 'warn',
            $spfOk ? 'Запис для хостингу є' : 'Перевірте TXT SPF у DNS', '');

        // DKIM ukraine.com.ua: selector hosting — часто НЕ в dns/records_list (службовий запис).
        if (!$dkimOk) {
            $dkimHost = 'hosting._domainkey.' . $primaryDomain;
            $dkimRecords = @dns_get_record($dkimHost, DNS_TXT);
            if (is_array($dkimRecords)) {
                foreach ($dkimRecords as $dr) {
                    $txt = (string) ($dr['txt'] ?? '');
                    if (str_contains($txt, 'v=DKIM1') && str_contains($txt, 'p=')) {
                        $dkimOk = true;
                        break;
                    }
                }
            }
        }
        $push($checks, 'dkim', 'Підпис листів (DKIM)', $dkimOk ? 'ok' : 'warn',
            $dkimOk ? 'hosting._domainkey є в DNS (SMTP)' : 'Немає TXT hosting._domainkey',
            $dkimOk ? 'Не вимикати в Пошта → DKIM' : 'Панель: Пошта → Налаштувати DKIM → Увімкнути');
    }

    $supportExists = false;
    $mailboxes = [];
    $mailDomains = strim_adm_tools_mail_list();
    if ($mailDomains['ok'] && is_array($mailDomains['response'])) {
        foreach ($mailDomains['response'] as $md) {
            if (!is_array($md) || ($md['domain'] ?? '') !== $primaryDomain) {
                continue;
            }
            $mailId = (int) ($md['id'] ?? 0);
            if ($mailId <= 0) {
                continue;
            }
            $boxes = strim_adm_tools_mail_box_list($mailId);
            if ($boxes['ok'] && isset($boxes['response']['list']) && is_array($boxes['response']['list'])) {
                foreach ($boxes['response']['list'] as $b) {
                    if (!is_array($b)) {
                        continue;
                    }
                    $email = (string) ($b['email_auto'] ?? '');
                    $mailboxes[] = $email;
                    if ($email === 'support@' . $primaryDomain) {
                        $supportExists = true;
                    }
                }
            }
        }
    }

    $push($checks, 'support_mail', 'Скринька support@', $supportExists ? 'ok' : 'fail',
        $supportExists ? 'Існує на хостингу' : 'Не знайдено — створіть у Пошта → Скриньки', '');

    if ($mailConfigured) {
        $push($checks, 'smtp_env', 'Відправка листів з сайту', 'ok',
            'SMTP налаштовано (' . (string) ($_ENV['MAIL_SMTP_HOST'] ?? 'mail.adm.tools') . ')', 'Скидання пароля, сповіщення');
    } else {
        $push($checks, 'smtp_env', 'Відправка листів з сайту', 'fail',
            'У .env немає MAIL_SMTP_USER/PASS', 'Додайте пароль скриньки support@');
    }

    // ── Hosting account / ops ────────────────────────────────────────────────
    $accountId = 0;
    $accountInfo = null;
    $accountDaysLeft = null;
    $quotaUsedPercent = null;
    $quotaLeftTitle = null;
    $planName = null;
    $hosts = [];
    $supervisorTasks = [];
    $supervisorActive = 0;
    $cronTasks = [];
    $cronActive = 0;
    $cronFailedRecent = 0;
    $extras = [];
    $hasRedisExtra = false;
    $hasOpcacheExtra = false;
    $ftpAccounts = [];

    $resolved = strim_adm_tools_resolve_account();
    if ($resolved['ok']) {
        $accountId = $resolved['account_id'];
        $accountInfo = $resolved['account'];
        $accountDaysLeft = isset($accountInfo['days_left']) ? (int) $accountInfo['days_left'] : null;
        $planName = (string) ($accountInfo['plan_name'] ?? '');
        $validUntil = (string) ($accountInfo['valid_untill_formated'] ?? $accountInfo['valid_untill'] ?? '');

        if ($accountDaysLeft !== null && $accountDaysLeft <= 7) {
            $push($checks, 'hosting_plan', 'Тариф хостингу', 'warn',
                'Залишилось ' . $accountDaysLeft . ' дн. (до ' . ($validUntil ?: '—') . ')',
                'Продовжіть або увімкніть автопродовження');
        } elseif ((string) ($accountInfo['is_valid_now'] ?? '0') === '1') {
            $push($checks, 'hosting_plan', 'Тариф хостингу', 'ok',
                ($planName !== '' ? $planName . ' — ' : '') . 'до ' . ($validUntil ?: '—')
                . ($accountDaysLeft !== null ? ' (' . $accountDaysLeft . ' дн.)' : ''), '');
        } else {
            $push($checks, 'hosting_plan', 'Тариф хостингу', 'fail', 'Тариф неактивний', 'Оплатіть хостинг у панелі');
        }

        $quota = strim_adm_tools_hosting_quota($accountId);
        if ($quota['ok']) {
            $quotaUsedPercent = isset($quota['response']['quota_total_percent'])
                ? (int) $quota['response']['quota_total_percent']
                : null;
            $quotaLeftTitle = (string) ($quota['response']['quota_left_title'] ?? '');
            $usedTitle = (string) ($quota['response']['quota_quota_title'] ?? '');
            if ($quotaUsedPercent !== null && $quotaUsedPercent >= 85) {
                $push($checks, 'disk_quota', 'Місце на диску', 'warn',
                    $quotaUsedPercent . '% зайнято (вільно ' . ($quotaLeftTitle ?: '—') . ')',
                    'Очистіть логи/бекапи або збільште тариф');
            } elseif ($quotaUsedPercent !== null) {
                $push($checks, 'disk_quota', 'Місце на диску', 'ok',
                    $quotaUsedPercent . '% з ' . ($usedTitle ?: 'ліміту') . ' (вільно ' . ($quotaLeftTitle ?: '—') . ')', '');
            }
        }

        $virt = strim_adm_tools_hosting_virtual_list($accountId);
        if ($virt['ok']) {
            $hosts = strim_adm_tools_flatten_virtual_hosts($virt['response']);
            $push($checks, 'sites', 'Сайти на акаунті', 'ok', count($hosts) . ' хост(ів)', '');
        }

        $sup = strim_adm_tools_hosting_supervisor_list($accountId);
        if ($sup['ok'] && isset($sup['response']['list']) && is_array($sup['response']['list'])) {
            foreach ($sup['response']['list'] as $t) {
                if (!is_array($t)) {
                    continue;
                }
                $active = (string) ($t['active'] ?? '0') === '1';
                if ($active) {
                    $supervisorActive++;
                }
                $cmd = (string) ($t['command'] ?? $t['task'] ?? '');
                $supervisorTasks[] = [
                    'task_id' => (int) ($t['task_id'] ?? 0),
                    'command' => $cmd,
                    'short' => strim_adm_tools_task_short($cmd),
                    'active' => $active,
                    'numprocs' => (int) ($t['numprocs'] ?? 1),
                ];
            }
            if ($supervisorActive === 0) {
                $push($checks, 'supervisor', 'Supervisor (фонові процеси)', 'fail',
                    'Немає активних процесів', 'Увімкніть trading_ops / signal / exec / ws_price у панелі');
            } else {
                $push($checks, 'supervisor', 'Supervisor (фонові процеси)', 'ok',
                    $supervisorActive . ' з ' . count($supervisorTasks) . ' активні', '');
            }
        } else {
            $push($checks, 'supervisor', 'Supervisor (фонові процеси)', 'warn',
                (string) ($sup['error'] ?? 'немає даних'), '');
        }

        $cron = strim_adm_tools_hosting_crontab_list($accountId);
        if ($cron['ok'] && isset($cron['response']['list']) && is_array($cron['response']['list'])) {
            foreach ($cron['response']['list'] as $t) {
                if (!is_array($t)) {
                    continue;
                }
                $active = (string) ($t['active'] ?? '0') === '1';
                if ($active) {
                    $cronActive++;
                }
                $code = (string) ($t['code'] ?? '0');
                if ($active && $code !== '' && $code !== '0') {
                    $cronFailedRecent++;
                }
                $task = html_entity_decode((string) ($t['task'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $cronTasks[] = [
                    'task_id' => (int) ($t['task_id'] ?? 0),
                    'task' => $task,
                    'short' => strim_adm_tools_task_short($task),
                    'active' => $active,
                    'schedule' => (string) ($t['human_readable'] ?? ''),
                    'last_start' => (string) ($t['start_dtime'] ?? ''),
                    'exit_code' => $code,
                    'domain' => (string) ($t['domain_name'] ?? $t['domain'] ?? ''),
                ];
            }
            if ($cronFailedRecent > 0) {
                $push($checks, 'crontab', 'Cron-завдання', 'warn',
                    $cronActive . ' активних, ' . $cronFailedRecent . ' з ненульовим кодом виходу',
                    'Перевірте логи в панелі ADM.TOOLS → Crontab');
            } elseif ($cronActive === 0) {
                $push($checks, 'crontab', 'Cron-завдання', 'warn', 'Немає активних cron', '');
            } else {
                $push($checks, 'crontab', 'Cron-завдання', 'ok', $cronActive . ' активних', '');
            }
        } else {
            $push($checks, 'crontab', 'Cron-завдання', 'warn',
                (string) ($cron['error'] ?? 'немає даних'), '');
        }

        $extra = strim_adm_tools_extra_list($accountId);
        if ($extra['ok'] && isset($extra['response']['list']) && is_array($extra['response']['list'])) {
            foreach ($extra['response']['list'] as $e) {
                if (!is_array($e)) {
                    continue;
                }
                $type = strtolower((string) ($e['type'] ?? ''));
                if ($type === 'redis') {
                    $hasRedisExtra = true;
                }
                if ($type === 'opcache') {
                    $hasOpcacheExtra = true;
                }
                $extras[] = [
                    'order_id' => (string) ($e['order_id'] ?? ''),
                    'type' => $type,
                    'name' => (string) ($e['name'] ?? ''),
                    'price' => $e['price'] ?? null,
                ];
            }
            $push($checks, 'redis_extra', 'Redis (дод. послуга)', $hasRedisExtra ? 'ok' : 'warn',
                $hasRedisExtra ? 'Підключено' : 'Немає Redis у extra/list',
                $hasRedisExtra ? '' : 'Замовте Redis у панелі або перевірте сокет локально');
            if ($hasOpcacheExtra) {
                $push($checks, 'opcache_extra', 'OPcache', 'ok', 'Підключено', '');
            }
        }

        $ftp = strim_adm_tools_hosting_ftp_list($accountId);
        if ($ftp['ok'] && isset($ftp['response']['list']) && is_array($ftp['response']['list'])) {
            foreach ($ftp['response']['list'] as $f) {
                if (!is_array($f)) {
                    continue;
                }
                $ftpAccounts[] = [
                    'login' => (string) ($f['login_full'] ?? $f['login'] ?? ''),
                    'host' => (string) ($f['host_name'] ?? ''),
                ];
            }
        }
    } else {
        $push($checks, 'hosting_account', 'Хостинг-акаунт', 'warn',
            (string) ($resolved['error'] ?? 'не знайдено'), 'Перевірте токен / ADM.TOOLS_ACCOUNT_ID');
    }

    $apiPing = $bal['ok'];
    $push($checks, 'api_link', 'Звʼязок з API хостингу', $apiPing ? 'ok' : 'fail',
        $apiPing ? 'Токен працює' : ((string) ($bal['error'] ?? 'немає відповіді')), '');

    $mxPrimaryStr = is_array($mxPrimary) ? (string) ($mxPrimary['host'] ?? '') : '';

    $snapshot = [
        'checked_at' => $checkedAt,
        'mx_primary' => $mxPrimaryStr,
        'balance_uah' => $balance,
        'domain_expires' => $domainExpiry,
        'support_mailbox' => $supportExists,
        'spf_ok' => $spfOk,
        'dkim_ok' => $dkimOk,
        'account_days_left' => $accountDaysLeft,
        'quota_used_percent' => $quotaUsedPercent,
        'supervisor_active' => $supervisorActive,
        'cron_active' => $cronActive,
        'redis_extra' => $hasRedisExtra,
    ];

    $prev = null;
    $snapPath = strim_adm_tools_snapshot_path();
    if (is_readable($snapPath)) {
        $prevJson = json_decode((string) file_get_contents($snapPath), true);
        if (is_array($prevJson)) {
            $prev = $prevJson;
        }
    }
    $changes = strim_adm_tools_diff_snapshots($prev, $snapshot);
    @mkdir(dirname($snapPath), 0755, true);
    file_put_contents($snapPath, json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    $info = [
        'api' => [
            'connected' => $apiPing,
            'balance_uah' => $balance,
            'rate_limits' => $bal['rate'] ?? [],
        ],
        'account' => [
            'id' => $accountId,
            'login' => (string) ($accountInfo['login'] ?? ''),
            'plan' => $planName,
            'days_left' => $accountDaysLeft,
            'valid_until' => (string) ($accountInfo['valid_untill_formated'] ?? $accountInfo['valid_untill'] ?? ''),
            'location' => (string) ($accountInfo['location'] ?? ''),
            'quota_used_percent' => $quotaUsedPercent,
            'quota_left' => $quotaLeftTitle,
        ],
        'domains' => $allDomains,
        'primary_domain' => [
            'name' => $primaryDomain,
            'id' => $domainId,
            'expires' => $domainExpiry,
        ],
        'dns' => [
            'mx_primary' => $mxPrimaryStr,
            'records' => array_slice($dnsRecords, 0, 40),
        ],
        'mail' => [
            'mailboxes' => $mailboxes,
            'support_exists' => $supportExists,
        ],
        'site_mail' => [
            'smtp_configured' => $mailConfigured,
            'transport' => $mailTransport,
            'from' => trim((string) ($_ENV['MAIL_FROM'] ?? 'support@strim.in.ua')),
        ],
        'hosts' => $hosts,
        'supervisor' => [
            'active' => $supervisorActive,
            'total' => count($supervisorTasks),
            'tasks' => $supervisorTasks,
        ],
        'crontab' => [
            'active' => $cronActive,
            'total' => count($cronTasks),
            'failed_recent' => $cronFailedRecent,
            'tasks' => array_slice($cronTasks, 0, 40),
        ],
        'extras' => [
            'redis' => $hasRedisExtra,
            'opcache' => $hasOpcacheExtra,
            'list' => $extras,
        ],
        'ftp' => $ftpAccounts,
        'changes_since_last_check' => $changes,
        'last_snapshot_at' => $prev['checked_at'] ?? null,
    ];

    return strim_adm_tools_build_report($checkedAt, $checks, $info, $mailConfigured, $mailTransport, $primaryDomain);
}

/**
 * @param list<array<string,mixed>> $checks
 * @param array<string,mixed> $info
 * @return array<string,mixed>
 */
function strim_adm_tools_build_report(
    string $checkedAt,
    array $checks,
    array $info,
    bool $mailConfigured,
    string $mailTransport,
    string $primaryDomain
): array {
    $fail = 0;
    $warn = 0;
    $ok = 0;
    foreach ($checks as $c) {
        if (($c['status'] ?? '') === 'fail') {
            $fail++;
        } elseif (($c['status'] ?? '') === 'warn') {
            $warn++;
        } else {
            $ok++;
        }
    }

    return [
        'ok' => true,
        'checked_at' => $checkedAt,
        'summary' => [
            'fail' => $fail,
            'warn' => $warn,
            'ok' => $ok,
        ],
        'checks' => $checks,
        'info' => $info,
    ];
}

/** Людські назви перевірок prelaunch (ключ → українська) */
function strim_admin_check_label(string $key, string $fallback): string
{
    $map = [
        'worker_status' => 'Фонові процеси торгівлі',
        'engine_tick' => 'Остання активність торгового рушія',
        'job_queue_failed' => 'Помилки в черзі завдань',
        'job_queue_backlog' => 'Завантаженість черги',
        'job_queue' => 'Черга завдань',
        'referral_pending_delta' => 'Реферали: виводи vs нарахування',
        'referral_duplicates' => 'Реферали: можливі дублікати',
        'admin_audit' => 'Журнал дій адміна',
        'admin_audit_rows' => 'Записи в журналі адміна',
        'tg_webhook' => 'Telegram-бот (привʼязка)',
        'hosting_api' => 'Звʼязок з панеллю хостингу',
        'hosting_balance' => 'Баланс хостингу',
        'hosting_domain' => 'Домен strim.in.ua',
        'hosting_mx' => 'Вхідна пошта',
        'hosting_spf' => 'Відправка листів (SPF)',
        'hosting_dkim' => 'Підпис листів (DKIM)',
        'hosting_support' => 'Скринька support@',
        'hosting_smtp' => 'Листи з сайту (SMTP)',
        'hosting_plan' => 'Тариф хостингу',
        'disk_quota' => 'Місце на диску',
        'supervisor' => 'Supervisor',
        'crontab' => 'Cron',
        'redis_extra' => 'Redis',
    ];

    return $map[$key] ?? $fallback;
}

/** Людські назви дій у журналі адміна */
function strim_admin_audit_action_label(string $action): string
{
    $map = [
        'billing_add30' => 'Підписка +30 днів',
        'billing_trial' => 'Пробний період 3 дні',
        'billing_block' => 'Блокування доступу',
        'withdraw_paid' => 'Вивід: відмічено виплаченим',
        'withdraw_cancel' => 'Вивід: скасовано',
        'billing_invalid' => 'Підписка: некоректний запит',
        'withdraw_invalid' => 'Вивід: некоректний запит',
        'billing_unknown' => 'Підписка: невідома дія',
        'whitepay_webhook_paid' => 'Оплата WhitePay (авто)',
        'privat_manual_apply' => 'ПриватБанк: застосовано вручну',
        'privat_bank_ignore' => 'ПриватБанк: ігнор платежу',
        'accounting_privat_poll' => 'Бухгалтерія: опит ПриватБанку',
        'accounting_compliance_save' => 'Бухгалтерія: збереження налаштувань',
        'admin_script_run' => 'Запуск службової команди',
        'unfreeze_burst_plan' => 'План burst після розморозки',
        'admin_reset_password' => 'Скидання пароля користувачу',
        'supervisor_restart' => 'Supervisor: перезапуск процесу',
    ];

    return $map[$action] ?? $action;
}

/** Ризик скрипта — українською */
function strim_admin_script_risk_label(string $risk): string
{
    return match ($risk) {
        'read' => 'лише перегляд',
        'maintenance' => 'обслуговування',
        'write' => 'змінює дані',
        default => $risk,
    };
}
