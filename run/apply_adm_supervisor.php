<?php
declare(strict_types=1);

/**
 * Налаштувати ADM.TOOLS Supervisor під StrIM (foreground-команди, 4 exec-слоти).
 *
 * Usage:
 *   php run/apply_adm_supervisor.php           # apply + restart
 *   php run/apply_adm_supervisor.php --dry-run
 *   php run/apply_adm_supervisor.php --no-restart
 */
$root = dirname(__DIR__);
require $root . '/www/vendor/autoload.php';
require_once $root . '/www/api/_whitepay_dotenv.php';
require_once $root . '/www/api/_adm_tools_client.php';

Dotenv\Dotenv::createImmutable($root)->safeLoad();
strim_whitepay_maybe_load_home_env();

$dry = in_array('--dry-run', $argv, true);
$noRestart = in_array('--no-restart', $argv, true);
$restartOnly = in_array('--restart-only', $argv, true);
$accountId = strim_adm_tools_configured_account_id();
$resolved = strim_adm_tools_resolve_account($accountId > 0 ? $accountId : null);
if (!$resolved['ok']) {
    fwrite(STDERR, "account resolve failed: " . ($resolved['error'] ?? '?') . "\n");
    exit(1);
}
$accountId = $resolved['account_id'];

if ($restartOnly) {
    $list = strim_adm_tools_hosting_supervisor_list($accountId);
    $ids = [];
    foreach (($list['response']['list'] ?? []) as $t) {
        if (!is_array($t)) {
            continue;
        }
        $cmd = (string) ($t['command'] ?? '');
        if (str_contains($cmd, 'strim.in.ua') || str_contains($cmd, 'supervisor_')) {
            $tid = (int) ($t['task_id'] ?? 0);
            if ($tid > 0) {
                $ids[] = $tid;
            }
        }
    }
    $out = [];
    foreach (array_unique($ids) as $tid) {
        $r = strim_adm_tools_supervisor_restart((int) $tid);
        $out[] = ['task_id' => $tid, 'ok' => $r['ok'], 'error' => $r['error'] ?? null];
        usleep(400000);
    }
    echo json_encode(['ok' => true, 'restart_only' => true, 'results' => $out], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}

$php = '/usr/local/php84/bin/php';
$bin = $root . '/run';

$desired = [
    [
        'key' => 'ws_price',
        'match' => 'supervisor_ws_price',
        'command' => '/bin/bash ' . $bin . '/supervisor_ws_price.sh',
        'numprocs' => 1,
        'comment' => 'StrIM WS price cache (foreground)',
        'legacy_match' => 'start_ws_price_cache.sh',
    ],
    [
        'key' => 'signal_daemon',
        'match' => 'supervisor_signal_daemon',
        'command' => '/bin/bash ' . $bin . '/supervisor_signal_daemon.sh',
        'numprocs' => 1,
        'comment' => 'StrIM signal_daemon',
        'legacy_match' => 'engine_v2/signal_daemon.php',
    ],
    [
        'key' => 'trading_ops',
        'match' => 'supervisor_trading_ops',
        'command' => '/bin/bash ' . $bin . '/supervisor_trading_ops.sh',
        'numprocs' => 1,
        'comment' => 'StrIM trading_ops_daemon',
        'legacy_match' => 'trading_ops_daemon.php',
    ],
];

$nWorkers = max(1, (int) ($_ENV['SIGNAL_EXEC_WORKERS'] ?? getenv('SIGNAL_EXEC_WORKERS') ?: 4));
for ($i = 1; $i <= $nWorkers; $i++) {
    $desired[] = [
        'key' => 'exec_s' . $i,
        'match' => 'supervisor_exec_worker.sh ' . $i,
        'command' => '/bin/bash ' . $bin . '/supervisor_exec_worker.sh ' . $i,
        'numprocs' => 1,
        'comment' => 'StrIM exec_worker slot ' . $i,
        // старий одиночний exec_worker.php без слота — замінити першим слотом
        'legacy_match' => $i === 1 ? 'engine_v2/exec_worker.php' : null,
    ];
}

$list = strim_adm_tools_hosting_supervisor_list($accountId);
if (!$list['ok']) {
    fwrite(STDERR, "supervisor/list failed: " . ($list['error'] ?? '?') . "\n");
    exit(1);
}
$existing = is_array($list['response']['list'] ?? null) ? $list['response']['list'] : [];

$findTask = static function (array $existing, string $match, ?string $legacy = null): ?array {
    foreach ($existing as $t) {
        if (!is_array($t)) {
            continue;
        }
        $cmd = (string) ($t['command'] ?? $t['task'] ?? '');
        if (str_contains($cmd, $match)) {
            return $t;
        }
    }
    if ($legacy) {
        foreach ($existing as $t) {
            if (!is_array($t)) {
                continue;
            }
            $cmd = (string) ($t['command'] ?? $t['task'] ?? '');
            if (str_contains($cmd, $legacy) && !str_contains($cmd, 'supervisor_')) {
                return $t;
            }
        }
    }
    return null;
};

$results = [];
$taskIds = [];

foreach ($desired as $want) {
    $hit = $findTask($existing, $want['match'], $want['legacy_match'] ?? null);
    $taskId = $hit ? (int) ($hit['task_id'] ?? 0) : null;
    $row = [
        'key' => $want['key'],
        'task_id' => $taskId,
        'command' => $want['command'],
        'action' => $taskId ? 'edit' : 'create',
    ];

    if ($dry) {
        $row['dry'] = true;
        $results[] = $row;
        continue;
    }

    $edit = strim_adm_tools_supervisor_edit(
        $accountId,
        $want['command'],
        (int) $want['numprocs'],
        $taskId && $taskId > 0 ? $taskId : null,
        (string) $want['comment'],
        true,
        true
    );
    $row['ok'] = $edit['ok'];
    $row['error'] = $edit['error'] ?? null;
    // після create — перечитати id
    if ($edit['ok'] && (!$taskId || $taskId <= 0)) {
        usleep(300000);
        $list2 = strim_adm_tools_hosting_supervisor_list($accountId);
        $existing = is_array($list2['response']['list'] ?? null) ? $list2['response']['list'] : $existing;
        $hit2 = $findTask($existing, $want['match'], null);
        $taskId = $hit2 ? (int) ($hit2['task_id'] ?? 0) : 0;
        $row['task_id'] = $taskId ?: null;
    }
    if ($taskId && $taskId > 0) {
        $taskIds[] = $taskId;
    }
    $results[] = $row;
    usleep(250000);
}

if (!$dry && !$noRestart && $taskIds !== []) {
    $taskIds = array_values(array_unique($taskIds));
    foreach ($taskIds as $tid) {
        $r = strim_adm_tools_supervisor_restart($tid);
        $results[] = [
            'key' => 'restart',
            'task_id' => $tid,
            'ok' => $r['ok'],
            'error' => $r['error'] ?? null,
        ];
        usleep(400000);
    }
}

$final = strim_adm_tools_hosting_supervisor_list($accountId);
$finalList = [];
foreach (($final['response']['list'] ?? []) as $t) {
    if (!is_array($t)) {
        continue;
    }
    $finalList[] = [
        'task_id' => (int) ($t['task_id'] ?? 0),
        'active' => (string) ($t['active'] ?? '') === '1',
        'numprocs' => (int) ($t['numprocs'] ?? 1),
        'command' => (string) ($t['command'] ?? ''),
        'comment' => (string) ($t['comment'] ?? ''),
    ];
}

echo json_encode([
    'ok' => true,
    'dry_run' => $dry,
    'account_id' => $accountId,
    'results' => $results,
    'supervisor' => $finalList,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
