<?php
declare(strict_types=1);

/**
 * Розклад тіків стратегії (етап 2 масштабування): next_tick_at.
 */

function scaling_tick_interval_sec(mysqli $db): int
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $sec = (int)(getenv('STRATEGY_TICK_INTERVAL_SEC') ?: ($_ENV['STRATEGY_TICK_INTERVAL_SEC'] ?? 10));
    $res = $db->query("SELECT v FROM app_settings WHERE k='strategy_tick_interval_sec' LIMIT 1");
    if ($res && ($row = $res->fetch_assoc())) {
        $fromDb = (int)($row['v'] ?? 0);
        if ($fromDb >= 3 && $fromDb <= 300) {
            $sec = $fromDb;
        }
    }

    if ($sec < 3) {
        $sec = 3;
    }
    if ($sec > 300) {
        $sec = 300;
    }

    $cached = $sec;
    return $cached;
}

/**
 * Інтервал V1 poll для стратегії (hybrid: довший тік для не-signal-exec).
 */
function scaling_tick_interval_for_strategy(mysqli $db, int $strategyId): int
{
    if ($strategyId <= 0) {
        return scaling_tick_interval_sec($db);
    }

    if (!function_exists('engine_strategy_skip_v1_poll')) {
        $modeFile = dirname(__FILE__) . '/engine_mode.php';
        if (is_file($modeFile)) {
            require_once $modeFile;
        }
    }

    if (function_exists('engine_strategy_skip_v1_poll') && engine_strategy_skip_v1_poll($strategyId)) {
        return scaling_signal_exec_reconcile_interval_sec($db);
    }

    if (function_exists('engine_hybrid_enabled') && engine_hybrid_enabled()) {
        static $hybridSec = null;
        if ($hybridSec === null) {
            $hybridSec = (int)(getenv('V2_HYBRID_V1_TICK_SEC') ?: ($_ENV['V2_HYBRID_V1_TICK_SEC'] ?? 15));
            $hybridSec = max(10, min(120, $hybridSec));
        }
        return $hybridSec;
    }

    return scaling_tick_interval_sec($db);
}

function scaling_has_next_tick_column(mysqli $db): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    $res = $db->query("SHOW COLUMNS FROM strategies LIKE 'next_tick_at'");
    $ok = (bool)($res && $res->num_rows > 0);
    return $ok;
}

function scaling_schedule_next_tick(mysqli $db, int $strategyId, ?int $intervalSec = null): void
{
    if ($strategyId <= 0 || !scaling_has_next_tick_column($db)) {
        return;
    }
    $sec = $intervalSec ?? scaling_tick_interval_sec($db);
    $stmt = $db->prepare(
        'UPDATE strategies SET next_tick_at = DATE_ADD(NOW(), INTERVAL ? SECOND) WHERE id = ? LIMIT 1'
    );
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('ii', $sec, $strategyId);
    $stmt->execute();
    $stmt->close();
}

/** Після Start — тік одразу (enqueue не чекає інтервалу). */
function scaling_schedule_tick_now(mysqli $db, int $strategyId): void
{
    if ($strategyId <= 0 || !scaling_has_next_tick_column($db)) {
        return;
    }
    $db->query('UPDATE strategies SET next_tick_at = NOW() WHERE id = ' . (int)$strategyId . ' LIMIT 1');
}

/**
 * Інтервал reconcile-тіку для стратегій на signal-exec (без V1 poll).
 */
function scaling_signal_exec_reconcile_interval_sec(mysqli $db): int
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $sec = (int)(getenv('V2_SIGNAL_RECONCILE_SEC') ?: ($_ENV['V2_SIGNAL_RECONCILE_SEC'] ?? 60));
    $res = $db->query("SELECT v FROM app_settings WHERE k='v2_signal_reconcile_sec' LIMIT 1");
    if ($res && ($row = $res->fetch_assoc())) {
        $fromDb = (int)($row['v'] ?? 0);
        if ($fromDb >= 30 && $fromDb <= 300) {
            $sec = $fromDb;
        }
    }
    $cached = max(30, min(300, $sec));
    return $cached;
}

/**
 * Періодичний PROCESS_STRATEGY (full/light) для signal-exec sid — sync з біржею.
 *
 * @param mysqli_stmt $checkStmt prepared: active job exists?
 */
function scaling_maybe_enqueue_signal_exec_reconcile(
    mysqli $db,
    int $userId,
    int $strategyId,
    string $symbol,
    mysqli_stmt $checkStmt
): void {
    if ($strategyId <= 0 || $userId <= 0 || $symbol === '') {
        return;
    }
    if (!function_exists('engine_strategy_skip_v1_poll') || !engine_strategy_skip_v1_poll($strategyId)) {
        return;
    }
    if (!scaling_has_next_tick_column($db)) {
        return;
    }

    // Due must use MySQL NOW() — never PHP strtotime()/time().
    // PHP vs MySQL timezone skew previously skipped reconcile for hours while SQL already marked due.
    $st = $db->query(
        'SELECT next_tick_at,
                (next_tick_at IS NULL OR next_tick_at <= NOW()) AS is_due
         FROM strategies WHERE id=' . (int)$strategyId . ' LIMIT 1'
    );
    if (!$st || !($row = $st->fetch_assoc())) {
        return;
    }
    if ((int)($row['is_due'] ?? 0) !== 1) {
        return;
    }

    require_once __DIR__ . '/signal_queue.php';
    if (signal_queue_strategy_busy($strategyId)) {
        return;
    }

    $px = 0.0;
    if (function_exists('ws_ticker_price')) {
        $px = (float)ws_ticker_price($symbol);
    }
    $qid = signal_queue_push(
        $userId,
        $strategyId,
        $symbol,
        'RECONCILE_TICK',
        $px > 0 ? $px : 0.0,
        ['source' => 'reconcile']
    );
    // Always advance next_tick after a due attempt (deduped push still means "healthy cadence").
    scaling_schedule_next_tick($db, $strategyId, scaling_signal_exec_reconcile_interval_sec($db));
    unset($qid);
}
