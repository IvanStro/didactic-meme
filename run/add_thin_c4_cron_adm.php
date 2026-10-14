<?php
declare(strict_types=1);
/**
 * Add Thin C4 hourly cron via ADM.TOOLS if missing.
 * php run/add_thin_c4_cron_adm.php
 */
require __DIR__ . '/../www/vendor/autoload.php';
require_once __DIR__ . '/../www/api/_whitepay_dotenv.php';
require_once __DIR__ . '/../www/api/_adm_tools_client.php';

Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
strim_whitepay_maybe_load_home_env();

$resolved = strim_adm_tools_resolve_account();
if (empty($resolved['ok']) || (int)($resolved['account_id'] ?? 0) <= 0) {
    fwrite(STDERR, 'account resolve failed: ' . json_encode($resolved, JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit(1);
}
$aid = (int)$resolved['account_id'];
$needle = 'cron_thin_exec_c4_check';
$taskCmd = '/bin/bash /home/bot20/strim.in.ua/run/cron_thin_exec_c4_check.sh';

$cron = strim_adm_tools_hosting_crontab_list($aid);
if (empty($cron['ok'])) {
    fwrite(STDERR, 'crontab list failed: ' . json_encode($cron, JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit(1);
}
$list = $cron['response']['list'] ?? $cron['response'] ?? [];
if (!is_array($list)) {
    $list = [];
}

$existingId = null;
foreach ($list as $t) {
    if (!is_array($t)) {
        continue;
    }
    $cmd = (string)($t['task'] ?? $t['command'] ?? '');
    if (str_contains($cmd, $needle)) {
        $existingId = (int)($t['id'] ?? $t['task_id'] ?? 0);
        echo "already_exists id={$existingId} active=" . ($t['active'] ?? '?') . " cmd={$cmd}\n";
        if ((string)($t['active'] ?? '') !== '1' && $existingId > 0) {
            $act = strim_adm_tools_crontab_update_active($existingId, true);
            echo 'activate: ' . json_encode($act, JSON_UNESCAPED_UNICODE) . "\n";
        }
        exit(0);
    }
}

$edit = strim_adm_tools_crontab_edit(
    $aid,
    $taskCmd,
    '12',
    '*',
    '*',
    '*',
    '*',
    null,
    'Thin Exec C0/C4 hourly TG check',
    true,
    true
);
echo 'create: ' . json_encode($edit, JSON_UNESCAPED_UNICODE) . "\n";
exit(!empty($edit['ok']) ? 0 : 1);
