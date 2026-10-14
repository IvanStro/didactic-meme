<?php
declare(strict_types=1);
/** php run/add_trading_analytics_cron_adm.php */
require __DIR__ . '/../www/vendor/autoload.php';
require_once __DIR__ . '/../www/api/_whitepay_dotenv.php';
require_once __DIR__ . '/../www/api/_adm_tools_client.php';

Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
strim_whitepay_maybe_load_home_env();

$resolved = strim_adm_tools_resolve_account();
if (empty($resolved['ok']) || (int)($resolved['account_id'] ?? 0) <= 0) {
    fwrite(STDERR, 'account resolve failed' . PHP_EOL);
    exit(1);
}
$aid = (int)$resolved['account_id'];
$cron = strim_adm_tools_hosting_crontab_list($aid);
$list = $cron['response']['list'] ?? $cron['response'] ?? [];
if (!is_array($list)) {
    $list = [];
}

$want = [
    [
        'needle' => 'cron_trading_analytics_daily',
        'cmd' => '/bin/bash /home/bot20/strim.in.ua/run/cron_trading_analytics_daily.sh',
        'minute' => '0',
        'hour' => '8',
        'week' => '*',
        'comment' => 'Trading analytics daily snapshot 08:00',
    ],
    [
        'needle' => 'cron_trading_analytics_report',
        'cmd' => '/bin/bash /home/bot20/strim.in.ua/run/cron_trading_analytics_report.sh',
        'minute' => '0',
        'hour' => '12',
        'week' => '0',
        'comment' => 'Trading analytics week/month/year report Sunday 12:00',
    ],
];

foreach ($want as $w) {
    $existingId = null;
    foreach ($list as $t) {
        if (!is_array($t)) {
            continue;
        }
        $cmd = (string)($t['task'] ?? $t['command'] ?? '');
        if (str_contains($cmd, $w['needle'])) {
            $existingId = (int)($t['id'] ?? $t['task_id'] ?? 0);
            echo "exists {$w['needle']} id={$existingId}\n";
            if ((string)($t['active'] ?? '') !== '1' && $existingId > 0) {
                strim_adm_tools_crontab_update_active($existingId, true);
            }
            break;
        }
    }
    if ($existingId) {
        continue;
    }
    $edit = strim_adm_tools_crontab_edit(
        $aid,
        $w['cmd'],
        $w['minute'],
        $w['hour'],
        '*',
        '*',
        $w['week'],
        null,
        $w['comment'],
        true,
        true
    );
    echo 'create ' . $w['needle'] . ': ' . json_encode($edit, JSON_UNESCAPED_UNICODE) . "\n";
}
echo "done\n";
