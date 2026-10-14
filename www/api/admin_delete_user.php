<?php
declare(strict_types=1);

require_once __DIR__ . '/_api_bootstrap.php';
require_once __DIR__ . '/_csrf.php';
require_once __DIR__ . '/_admin_audit.php';

header('Content-Type: application/json; charset=utf-8');

$mysqli = new mysqli(
    (string) ($_ENV['DB_HOST'] ?? ''),
    (string) ($_ENV['DB_USER'] ?? ''),
    (string) ($_ENV['DB_PASS'] ?? ''),
    (string) ($_ENV['DB_NAME'] ?? '')
);
if ($mysqli->connect_errno) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'db_connect'], JSON_UNESCAPED_UNICODE);
    exit;
}
$mysqli->set_charset('utf8mb4');

$adminUid = current_user_id($mysqli);
if ($adminUid <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'auth'], JSON_UNESCAPED_UNICODE);
    exit;
}
$admRes = $mysqli->query('SELECT is_admin FROM users WHERE id=' . (int) $adminUid . ' LIMIT 1');
$admRow = $admRes ? $admRes->fetch_assoc() : null;
if (!$admRow || (int) ($admRow['is_admin'] ?? 0) !== 1) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}
require_csrf_or_403();

$targetUserId = (int) ($_POST['user_id'] ?? 0);
if ($targetUserId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid user_id'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($targetUserId === $adminUid) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'self_delete_forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

$uRes = $mysqli->query(
    'SELECT id, email, is_admin FROM users WHERE id=' . (int) $targetUserId . ' LIMIT 1'
);
$uRow = $uRes ? $uRes->fetch_assoc() : null;
if (!$uRow) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'user_not_found'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ((int)($uRow['is_admin'] ?? 0) === 1) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'admin_delete_forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

function table_exists(mysqli $db, string $table): bool {
    $tableEsc = $db->real_escape_string($table);
    $q = $db->query("SHOW TABLES LIKE '{$tableEsc}'");
    return (bool)($q && $q->num_rows > 0);
}

function delete_by_user(mysqli $db, string $table, string $col, int $uid): void {
    if (!table_exists($db, $table)) return;
    $tableEsc = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $colEsc = preg_replace('/[^a-zA-Z0-9_]/', '', $col);
    if ($tableEsc === '' || $colEsc === '') return;
    $db->query("DELETE FROM `{$tableEsc}` WHERE `{$colEsc}` = " . (int)$uid);
}

try {
    $mysqli->begin_transaction();

    $strategyIds = [];
    $sRes = $mysqli->query(
        "SELECT id FROM strategies WHERE user_id=" . (int)$targetUserId . " OR owner_id=" . (int)$targetUserId
    );
    while ($sRes && ($r = $sRes->fetch_assoc())) {
        $sid = (int)($r['id'] ?? 0);
        if ($sid > 0) $strategyIds[] = $sid;
    }

    if ($strategyIds !== []) {
        $idsSql = implode(',', array_map('intval', array_values(array_unique($strategyIds))));
        if (table_exists($mysqli, 'sub_positions') && table_exists($mysqli, 'positions')) {
            $mysqli->query(
                "DELETE sp FROM sub_positions sp
                 INNER JOIN positions p ON p.id = sp.position_id
                 WHERE p.strategy_id IN ({$idsSql})"
            );
        }
        if (table_exists($mysqli, 'positions')) $mysqli->query("DELETE FROM positions WHERE strategy_id IN ({$idsSql})");
        if (table_exists($mysqli, 'strategy_levels')) $mysqli->query("DELETE FROM strategy_levels WHERE strategy_id IN ({$idsSql})");
        if (table_exists($mysqli, 'martingale_levels')) $mysqli->query("DELETE FROM martingale_levels WHERE strategy_id IN ({$idsSql})");
        if (table_exists($mysqli, 'trade_ledger')) $mysqli->query("DELETE FROM trade_ledger WHERE strategy_id IN ({$idsSql})");
        if (table_exists($mysqli, 'engine_journal')) $mysqli->query("DELETE FROM engine_journal WHERE strategy_id IN ({$idsSql})");
        if (table_exists($mysqli, 'signal_queue')) $mysqli->query("DELETE FROM signal_queue WHERE strategy_id IN ({$idsSql})");
        if (table_exists($mysqli, 'job_queue')) $mysqli->query("DELETE FROM job_queue WHERE strategy_id IN ({$idsSql})");
        $mysqli->query("DELETE FROM strategies WHERE id IN ({$idsSql})");
    }

    delete_by_user($mysqli, 'sessions_auth', 'user_id', $targetUserId);
    delete_by_user($mysqli, 'whitepay_orders', 'user_id', $targetUserId);
    delete_by_user($mysqli, 'support_messages', 'user_id', $targetUserId);
    delete_by_user($mysqli, 'referral_withdrawals', 'user_id', $targetUserId);
    delete_by_user($mysqli, 'referral_rewards', 'referrer_user_id', $targetUserId);
    delete_by_user($mysqli, 'referral_rewards', 'referred_user_id', $targetUserId);

    if (table_exists($mysqli, 'users')) {
        $mysqli->query("UPDATE users SET referred_by_user_id=NULL WHERE referred_by_user_id=" . (int)$targetUserId);
    }

    $mysqli->query("DELETE FROM users WHERE id=" . (int)$targetUserId . " LIMIT 1");
    if ($mysqli->affected_rows <= 0) {
        throw new RuntimeException('delete_failed');
    }

    admin_audit_log($mysqli, $adminUid, 'user_delete_admin', $targetUserId, [
        'email' => (string)($uRow['email'] ?? ''),
        'strategies_deleted' => count($strategyIds),
    ], 'ok');

    $mysqli->commit();
    echo json_encode(['ok' => true, 'deleted_user_id' => $targetUserId], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    $mysqli->rollback();
    admin_audit_log($mysqli, $adminUid, 'user_delete_admin', $targetUserId, [
        'email' => (string)($uRow['email'] ?? ''),
        'error' => $e->getMessage(),
    ], 'error');
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'delete_failed'], JSON_UNESCAPED_UNICODE);
}
