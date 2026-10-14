<?php

require __DIR__ . '/../../engine/engine_boot.php';
require_once __DIR__ . '/../auth/_session_user.php';
require_once __DIR__ . '/_billing_access.php';
require_once __DIR__ . '/_strategy_status.php';
require_once __DIR__ . '/_csrf.php';
require_once __DIR__ . '/../../engine_v2/scaling_schedule.php';

header('Content-Type: application/json; charset=utf-8');

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
    $chatId = (int)$row['tg_chat_id'];
    $url = "https://api.telegram.org/bot{$token}/sendMessage";
    @file_get_contents($url . '?' . http_build_query([
        'chat_id' => $chatId,
        'text' => $msg,
    ]));
}

$uid = current_user_id($mysqli);
if ($uid <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method'], JSON_UNESCAPED_UNICODE);
    exit;
}
ensure_csrf_token();
require_csrf_or_403();



/** Мінімум початкової покупки (FBA), USDT — запас над minNotional Binance. */
const STRIM_MIN_FBA_USDT = 7.0;

$symbol = trim((string)($_POST['symbol'] ?? ''));
$action = trim((string)($_POST['action'] ?? ''));
$strategyIdReq = (int)($_POST['strategy_id'] ?? ($_POST['sid'] ?? 0));
$fba = null;
$fbaRaw = $_POST['fba'] ?? null;
if ($fbaRaw !== null && $fbaRaw !== '') {
    $fba = (float)$fbaRaw;
}

$symbol = strtoupper($symbol);
$action = strtolower($action);
if ($action === 'start') { billing_emit_trading_denied(billing_require_trading_access($mysqli,$uid)); require_once __DIR__.'/_legal.php'; strim_legal_require_json($mysqli,$uid); }

if ($symbol === '' || !preg_match('/^[A-Z0-9]{3,}USDT$/', $symbol)) {
    echo json_encode(['ok' => false, 'error' => 'bad symbol'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (!in_array($action, ['start', 'pause', 'stop'], true)) {
    echo json_encode(['ok' => false, 'error' => 'bad action'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'start' && $fba !== null && $fba > 0 && $fba < STRIM_MIN_FBA_USDT) {
    echo json_encode([
        'ok' => false,
        'error' => 'fba_too_small',
        'message' => 'Початкова покупка має бути не менше '
            . number_format(STRIM_MIN_FBA_USDT, 0, '.', '')
            . ' USDT. Менша сума часто блокується біржею при продажу (мінімум ~5 USDT після округлення).',
        'min_fba' => STRIM_MIN_FBA_USDT,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$maxTrialFba = billing_trial_max_fba_usdt($mysqli, $uid);
if ($action === 'start' && $maxTrialFba > 0 && $fba !== null && $fba > $maxTrialFba + 1e-9) {
    echo json_encode([
        'ok' => false,
        'error' => 'fba_trial_cap',
        'message' => 'На тестовому доступі максимальна початкова покупка — '
            . number_format($maxTrialFba, 0, '.', '')
            . ' USDT. Поповніть баланс від 500 грн, щоб зняти обмеження.',
        'max_fba' => $maxTrialFba,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$mysqli->set_charset('utf8mb4');

function pick_template_sid(mysqli $db): int
{
    $r = $db->query('SELECT strategy_id, COUNT(*) cnt FROM martingale_levels GROUP BY strategy_id ORDER BY cnt DESC, strategy_id ASC LIMIT 1');
    $row = $r ? $r->fetch_assoc() : null;
    return (int)($row['strategy_id'] ?? 0);
}

function ensure_levels(mysqli $db, int $sid): void
{
    $sid = (int)$sid;

    $r = $db->query("SELECT COUNT(*) c FROM martingale_levels WHERE strategy_id=$sid");
    $c = (int)(($r && ($row = $r->fetch_assoc())) ? ($row['c'] ?? 0) : 0);
    if ($c > 0) {
        $r2 = $db->query("SELECT COUNT(*) c FROM strategy_levels WHERE strategy_id=$sid");
        $c2 = (int)(($r2 && ($row2 = $r2->fetch_assoc())) ? ($row2['c'] ?? 0) : 0);
        if ($c2 <= 0) {
            $db->query("DELETE FROM strategy_levels WHERE strategy_id=$sid");
            $db->query("
                INSERT INTO strategy_levels(strategy_id, lvl_idx, drop_pct, mult, tp_pct, cb_pct)
                SELECT $sid, idx, drop_pct, mult, tp_sub, cb_sub
                FROM martingale_levels
                WHERE strategy_id=$sid
                ORDER BY idx
            ");
        }
        return;
    }

    $tpl = pick_template_sid($db);
    if ($tpl <= 0) {
        throw new Exception('No template levels found in martingale_levels');
    }

    $db->query("DELETE FROM martingale_levels WHERE strategy_id=$sid");
    $db->query("
        INSERT INTO martingale_levels(strategy_id, idx, drop_pct, mult, tp_sub, cb_sub)
        SELECT $sid, idx, drop_pct, mult, tp_sub, cb_sub
        FROM martingale_levels
        WHERE strategy_id=$tpl
        ORDER BY idx
    ");

    $db->query("DELETE FROM strategy_levels WHERE strategy_id=$sid");
    $db->query("
        INSERT INTO strategy_levels(strategy_id, lvl_idx, drop_pct, mult, tp_pct, cb_pct)
        SELECT $sid, idx, drop_pct, mult, tp_sub, cb_sub
        FROM martingale_levels
        WHERE strategy_id=$sid
        ORDER BY idx
    ");
}

$symEsc = $mysqli->real_escape_string($symbol);
$row = strategy_resolve_owned($mysqli, $uid, $symbol, $strategyIdReq);

if ($action === 'start') {
    $te = $_ENV['TRADING_ENABLED'] ?? getenv('TRADING_ENABLED') ?? '0';
    if ((string)$te !== '1') {
        echo json_encode([
            'ok' => false,
            'error' => 'TRADING_DISABLED',
            'message' => 'Торгівля вимкнена на сервері (TRADING_ENABLED≠1). Додай у .env TRADING_ENABLED=1 перед запуском.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    if (!billing_user_has_access($mysqli, $uid)) {
        echo json_encode([
            'ok' => false,
            'error' => 'billing_expired',
            'message' => 'Підписка не активна (EXPIRED). Запуск стратегії недоступний.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $alreadyRunning = $row && (string)($row['status'] ?? '') === 'running';
    if (!$alreadyRunning) {
        $maxRun = billing_max_running_strategies($mysqli, $uid);
        $runCnt = billing_count_running_strategies($mysqli, $uid);
        if ($runCnt >= $maxRun) {
            echo json_encode([
                'ok' => false,
                'error' => 'running_limit',
                'message' => 'Досягнуто ліміт активних пар (' . $maxRun . '). Зупиніть іншу стратегію або оновіть тариф.',
                'limit' => $maxRun,
                'running' => $runCnt,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
    }
}

if (!$row) {
    $tp_whole = 1.4;
    $cb_whole = 0.1;
    $global_cb = 0.1;
    $insFba = $fba !== null ? $fba : STRIM_MIN_FBA_USDT;
    $newStatus = strategy_target_status($action);

    if (!$mysqli->query("
        INSERT INTO strategies (owner_id,user_id,symbol, status, fba, tp_whole, cb_whole, global_cb, start_ts)
        VALUES ($uid,$uid,
          '{$symEsc}',
          '{$newStatus}',
          " . number_format($insFba, 8, '.', '') . ',
          ' . number_format($tp_whole, 6, '.', '') . ',
          ' . number_format($cb_whole, 6, '.', '') . ',
          ' . number_format($global_cb, 6, '.', '') . ",
          UNIX_TIMESTAMP(NOW())*1000
        )
    ")) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'db_insert', 'message' => $mysqli->error], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $sid = (int)$mysqli->insert_id;
    strategy_sync_symbol_index($mysqli, $sid);
    strategy_emit_user_status_journal($mysqli, $uid, $sid, $symbol, $action, 'new', $newStatus);
    $apply = [
        'ok' => true,
        'strategy_id' => $sid,
        'symbol' => $symbol,
        'status' => $newStatus,
        'prev_status' => 'new',
        'changed' => true,
        'cancelled_signals' => 0,
    ];
} else {
    $sid = (int)$row['id'];
    $apply = strategy_apply_status($mysqli, $uid, $sid, $action, $fba);
    if (!$apply['ok']) {
        http_response_code(500);
        echo json_encode($apply, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if ($action === 'start') {
    try {
        ensure_levels($mysqli, $sid);
    } catch (Throwable $e) {
        // do not block API
    }
    $uid_i = (int)$uid;
    $sid_i = (int)$sid;

    $proc = $mysqli->query("SELECT id FROM job_queue WHERE user_id=$uid_i AND strategy_id=$sid_i AND symbol='$symEsc' AND status='processing' LIMIT 1");
    if (!$proc || !$proc->fetch_assoc()) {
        $qu = $mysqli->query("SELECT id FROM job_queue WHERE user_id=$uid_i AND strategy_id=$sid_i AND symbol='$symEsc' AND status='queued' LIMIT 1");
        if ($qu && ($qr = $qu->fetch_assoc())) {
            $jid = (int)$qr['id'];
            $mysqli->query("UPDATE job_queue SET available_at=NOW(), updated_at=NOW(), attempts=0, last_error=NULL WHERE id=$jid");
        } else {
            $rev = $mysqli->query("SELECT id FROM job_queue WHERE user_id=$uid_i AND strategy_id=$sid_i AND symbol='$symEsc' AND status IN ('done','failed') ORDER BY id DESC LIMIT 1");
            if ($rev && ($rv = $rev->fetch_assoc())) {
                $jid = (int)$rv['id'];
                $mysqli->query("UPDATE job_queue SET job_type='PROCESS_STRATEGY', status='queued', attempts=0, available_at=NOW(), updated_at=NOW(), last_error=NULL, worker_id=NULL, locked_at=NULL WHERE id=$jid");
            } else {
                try {
                    $mysqli->query("INSERT INTO job_queue (job_type,user_id,strategy_id,symbol,payload_json,status,attempts,available_at,created_at,updated_at) VALUES ('PROCESS_STRATEGY',$uid_i,$sid_i,'$symEsc',NULL,'queued',0,NOW(),NOW(),NOW())");
                } catch (mysqli_sql_exception $e) {
                    if ((int)$e->getCode() !== 1062 && stripos($e->getMessage(), 'Duplicate') === false) {
                        throw $e;
                    }
                    $mysqli->query("UPDATE job_queue SET job_type='PROCESS_STRATEGY', status='queued', attempts=0, available_at=NOW(), updated_at=NOW(), last_error=NULL, worker_id=NULL, locked_at=NULL WHERE user_id=$uid_i AND strategy_id=$sid_i AND symbol='$symEsc' ORDER BY id DESC LIMIT 1");
                }
            }
        }
    }
    scaling_schedule_tick_now($mysqli, $sid);
}

echo json_encode([
    'ok' => true,
    'strategy_id' => $sid,
    'sid' => $sid,
    'symbol' => $symbol,
    'action' => $action,
    'status' => (string)($apply['status'] ?? strategy_target_status($action)),
    'prev_status' => (string)($apply['prev_status'] ?? ''),
    'changed' => !empty($apply['changed']),
    'cancelled_signals' => (int)($apply['cancelled_signals'] ?? 0),
    'ts' => date('Y-m-d H:i:s'),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
