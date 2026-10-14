<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

require __DIR__ . '/../../engine/engine_boot.php';
require_once __DIR__ . '/../auth/_session_user.php';
require_once __DIR__ . '/_billing_access.php';
require_once __DIR__ . '/_strategy_status.php';
require_once __DIR__ . '/_csrf.php';
require_once __DIR__ . '/../../engine_v2/scaling_schedule.php';

function strategy_tg_send_user(mysqli $db, int $uid, string $msg): void
{
    $r = $db->query('SELECT tg_chat_id, tg_enabled FROM users WHERE id=' . (int)$uid . ' LIMIT 1');
    $row = $r ? $r->fetch_assoc() : null;
    if (!$row || (int)($row['tg_enabled'] ?? 0) !== 1 || !$row['tg_chat_id']) {
        return;
    }
    $token = $_ENV['TG_BOT_TOKEN'] ?? '';
    if (!$token) {
        return;
    }
    $url = 'https://api.telegram.org/bot' . $token . '/sendMessage';
    @file_get_contents($url . '?' . http_build_query([
        'chat_id' => (int)$row['tg_chat_id'],
        'text' => $msg,
    ]));
}

$uid = current_user_id($mysqli);
if ($uid <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method']);
    exit;
}
ensure_csrf_token();
require_csrf_or_403();

billing_emit_trading_denied(billing_require_trading_access($mysqli, $uid));

$strategyId = (int)($_POST['strategy_id'] ?? ($_POST['sid'] ?? 0));
$row = $strategyId > 0
    ? strategy_resolve_owned($mysqli, $uid, '', $strategyId)
    : null;

if (!$row) {
    $res = $mysqli->query("SELECT id, symbol, status FROM strategies WHERE owner_id=$uid ORDER BY id DESC LIMIT 1");
    $row = $res ? $res->fetch_assoc() : null;
}
if (!$row) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'no strategy']);
    exit;
}

$sid = (int)$row['id'];
$alreadyRunning = (string)($row['status'] ?? '') === 'running';
if (!$alreadyRunning) {
    $maxRun = billing_max_running_strategies($mysqli, $uid);
    $runCnt = billing_count_running_strategies($mysqli, $uid);
    if ($runCnt >= $maxRun) {
        echo json_encode([
            'ok' => false,
            'error' => 'running_limit',
            'max' => $maxRun,
            'running' => $runCnt,
            'message' => "Ліміт одночасних ботів: {$maxRun}. Зупиніть іншу пару або зверніться в підтримку.",
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$apply = strategy_apply_status($mysqli, $uid, $sid, 'start');
if (!$apply['ok']) {
    http_response_code(500);
    echo json_encode($apply, JSON_UNESCAPED_UNICODE);
    exit;
}

scaling_schedule_tick_now($mysqli, $sid);

echo json_encode([
    'ok' => true,
    'strategy_id' => $sid,
    'status' => 'running',
    'changed' => !empty($apply['changed']),
    'prev_status' => (string)($apply['prev_status'] ?? ''),
], JSON_UNESCAPED_UNICODE);
