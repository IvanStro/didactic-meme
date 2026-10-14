<?php
declare(strict_types=1);

/**
 * Адмін API: дії Supervisor (list / restart).
 * POST JSON: { action: "list"|"restart", task_id?: int }
 * Rate-limit: клієнт ADM.TOOLS 60/хв — тут лише 1–2 виклики.
 */
require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../auth/_session_user.php';
require_once __DIR__ . '/_adm_tools_client.php';
require_once __DIR__ . '/_whitepay_dotenv.php';
require_once __DIR__ . '/_admin_audit.php';

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

$raw = file_get_contents('php://input');
$body = is_string($raw) ? json_decode($raw, true) : null;
if (!is_array($body)) {
    $body = $_POST;
}
$action = strtolower(trim((string) ($body['action'] ?? $_GET['action'] ?? 'list')));

$resolved = strim_adm_tools_resolve_account();
if (empty($resolved['ok'])) {
    echo json_encode(['ok' => false, 'error' => 'account:' . (string) ($resolved['error'] ?? '?')], JSON_UNESCAPED_UNICODE);
    exit;
}
$accountId = (int) $resolved['account_id'];

if ($action === 'list') {
    $list = strim_adm_tools_hosting_supervisor_list($accountId);
    if (empty($list['ok'])) {
        echo json_encode(['ok' => false, 'error' => (string) ($list['error'] ?? 'list_failed')], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $tasks = [];
    foreach (($list['response']['list'] ?? []) as $t) {
        if (!is_array($t)) {
            continue;
        }
        $cmd = (string) ($t['command'] ?? '');
        $tasks[] = [
            'task_id' => (int) ($t['task_id'] ?? 0),
            'active' => (string) ($t['active'] ?? '') === '1',
            'numprocs' => (int) ($t['numprocs'] ?? 1),
            'command' => $cmd,
            'short' => strim_adm_tools_task_short($cmd),
            'comment' => (string) ($t['comment'] ?? ''),
        ];
    }
    echo json_encode(['ok' => true, 'account_id' => $accountId, 'tasks' => $tasks], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'restart') {
    $taskId = (int) ($body['task_id'] ?? 0);
    if ($taskId <= 0) {
        echo json_encode(['ok' => false, 'error' => 'task_id_required'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // лише наші StrIM-задачі
    $list = strim_adm_tools_hosting_supervisor_list($accountId);
    $allowed = false;
    $short = '';
    foreach (($list['response']['list'] ?? []) as $t) {
        if (!is_array($t) || (int) ($t['task_id'] ?? 0) !== $taskId) {
            continue;
        }
        $cmd = (string) ($t['command'] ?? '');
        if (str_contains($cmd, 'strim.in.ua') || str_contains($cmd, 'supervisor_')) {
            $allowed = true;
            $short = strim_adm_tools_task_short($cmd);
        }
        break;
    }
    if (!$allowed) {
        echo json_encode(['ok' => false, 'error' => 'task_not_allowed'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $r = strim_adm_tools_supervisor_restart($taskId);
    if (function_exists('admin_audit_log')) {
        admin_audit_log($mysqli, $uid, 'supervisor_restart', 0, [
            'task_id' => $taskId,
            'short' => $short,
            'error' => $r['error'] ?? null,
        ], !empty($r['ok']) ? 'ok' : 'fail');
    }
    echo json_encode([
        'ok' => !empty($r['ok']),
        'task_id' => $taskId,
        'short' => $short,
        'error' => $r['error'] ?? null,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'unknown_action'], JSON_UNESCAPED_UNICODE);
