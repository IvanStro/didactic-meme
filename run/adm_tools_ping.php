<?php
declare(strict_types=1);

/**
 * CLI ping ADM.TOOLS: токен + ключові ops-методи.
 * Usage: php run/adm_tools_ping.php
 */
require __DIR__ . '/../www/vendor/autoload.php';
require_once __DIR__ . '/../www/api/_whitepay_dotenv.php';
require_once __DIR__ . '/../www/api/_adm_tools_client.php';

Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
strim_whitepay_maybe_load_home_env();

$out = [
    'token_set' => strim_adm_tools_token() !== '',
    'account_id_env' => strim_adm_tools_configured_account_id() ?: null,
];

$bal = strim_adm_tools_balance_get();
$out['balance'] = [
    'ok' => $bal['ok'],
    'uah' => $bal['ok'] ? ($bal['response']['balance'] ?? null) : null,
    'error' => $bal['error'] ?? null,
    'rate' => $bal['rate'] ?? [],
];

$resolved = strim_adm_tools_resolve_account();
$out['account'] = [
    'ok' => $resolved['ok'],
    'id' => $resolved['account_id'],
    'login' => $resolved['account']['login'] ?? null,
    'plan' => $resolved['account']['plan_name'] ?? null,
    'days_left' => $resolved['account']['days_left'] ?? null,
    'error' => $resolved['error'] ?? null,
];

$aid = (int) ($resolved['account_id'] ?? 0);
if ($aid > 0) {
    $sup = strim_adm_tools_hosting_supervisor_list($aid);
    $cron = strim_adm_tools_hosting_crontab_list($aid);
    $quota = strim_adm_tools_hosting_quota($aid);
    $extra = strim_adm_tools_extra_list($aid);
    $hosts = strim_adm_tools_get_services('host');

    $supList = is_array($sup['response']['list'] ?? null) ? $sup['response']['list'] : [];
    $cronList = is_array($cron['response']['list'] ?? null) ? $cron['response']['list'] : [];
    $extraList = is_array($extra['response']['list'] ?? null) ? $extra['response']['list'] : [];

    $out['supervisor'] = [
        'ok' => $sup['ok'],
        'total' => count($supList),
        'active' => count(array_filter($supList, static fn ($t) => is_array($t) && (string) ($t['active'] ?? '') === '1')),
    ];
    $out['crontab'] = [
        'ok' => $cron['ok'],
        'total' => count($cronList),
        'active' => count(array_filter($cronList, static fn ($t) => is_array($t) && (string) ($t['active'] ?? '') === '1')),
    ];
    $out['quota'] = [
        'ok' => $quota['ok'],
        'used_percent' => $quota['response']['quota_total_percent'] ?? null,
        'left' => $quota['response']['quota_left_title'] ?? null,
    ];
    $out['extras'] = [
        'ok' => $extra['ok'],
        'items' => array_map(static fn ($e) => is_array($e) ? ($e['name'] ?? $e['type'] ?? '') : '', $extraList),
    ];
    $out['hosts'] = [
        'ok' => $hosts['ok'],
        'count' => is_array($hosts['response']) ? count($hosts['response']) : 0,
    ];
}

$dns = strim_adm_tools_dns_list();
$out['domains_ok'] = $dns['ok'];
$out['domain_count'] = is_array($dns['response']['list'] ?? null) ? count($dns['response']['list']) : 0;

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
exit(!empty($out['balance']['ok']) ? 0 : 1);
