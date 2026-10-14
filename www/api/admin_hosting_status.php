<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../auth/_session_user.php';
require_once __DIR__ . '/_adm_tools_client.php';
require_once __DIR__ . '/_whitepay_dotenv.php';

Dotenv\Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();
strim_whitepay_maybe_load_home_env();

header('Content-Type: application/json; charset=utf-8');

$mysqli = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
if ($mysqli->connect_errno) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'db_connect'], JSON_UNESCAPED_UNICODE);
    exit;
}
$mysqli->set_charset('utf8mb4');

$uid = current_user_id($mysqli);
if ($uid <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'auth'], JSON_UNESCAPED_UNICODE);
    exit;
}
$admRes = $mysqli->query('SELECT is_admin FROM users WHERE id=' . (int) $uid . ' LIMIT 1');
$admRow = $admRes ? $admRes->fetch_assoc() : null;
if (!$admRow || (int) ($admRow['is_admin'] ?? 0) !== 1) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

$domain = trim((string) ($_GET['domain'] ?? 'strim.in.ua'));
if ($domain === '') {
    $domain = 'strim.in.ua';
}

$report = strim_adm_tools_hosting_report($domain);

// Процеси на сервері (торговий бот)
$processes = [
    'workers' => [],
    'supervisor_running' => false,
    'supervisor_pid' => null,
    'cron_hint' => 'Щохвилини: cron_ensure_trading_ops (підтримує фоновий наглядач)',
];
if (is_file(__DIR__ . '/_worker_manager.php')) {
    require_once __DIR__ . '/_worker_manager.php';
    if (function_exists('worker_running_pids')) {
        foreach (worker_running_pids() as $pid) {
            $processes['workers'][] = [
                'pid' => $pid,
                'uptime_sec' => function_exists('worker_uptime_sec') ? worker_uptime_sec($pid) : null,
                'label' => 'Процес обробки торгівлі',
            ];
        }
    }
    $pidPath = function_exists('worker_log_dir')
        ? worker_log_dir() . '/trading_ops_daemon.pid'
        : dirname(__DIR__, 2) . '/logs/trading_ops_daemon.pid';
    if (is_readable($pidPath)) {
        $spid = (int) trim((string) file_get_contents($pidPath));
        if ($spid > 0) {
            $processes['supervisor_pid'] = $spid;
            $alive = function_exists('posix_kill') ? @posix_kill($spid, 0) : is_dir('/proc/' . $spid);
            $processes['supervisor_running'] = (bool) $alive;
        }
    }
}

$report['info']['processes'] = $processes;
$report['info']['processes_summary'] = count($processes['workers']) . ' процес(ів) торгівлі, наглядач '
    . ($processes['supervisor_running'] ? 'працює' : 'не працює');

// Останні дії адміна (хостинг/скрипти)
$recentAdmin = [];
$aa = $mysqli->query("SHOW TABLES LIKE 'admin_action_audit'");
if ($aa && $aa->num_rows > 0) {
    $q = $mysqli->query("
        SELECT a.created_at, a.action_type, a.result_status, u.email AS actor_email
        FROM admin_action_audit a
        LEFT JOIN users u ON u.id = a.actor_user_id
        ORDER BY a.id DESC
        LIMIT 15
    ");
    if ($q) {
        while ($row = $q->fetch_assoc()) {
            $recentAdmin[] = [
                'at' => (string) ($row['created_at'] ?? ''),
                'action' => strim_admin_audit_action_label((string) ($row['action_type'] ?? '')),
                'result' => (string) ($row['result_status'] ?? ''),
                'who' => (string) ($row['actor_email'] ?? ''),
            ];
        }
    }
}
$report['info']['recent_admin_actions'] = $recentAdmin;

echo json_encode($report, JSON_UNESCAPED_UNICODE);
