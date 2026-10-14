<?php
declare(strict_types=1);

/**
 * V2 фаза 4: eval усіх running sid на символ при оновленні ws_prices.json.
 * Не виконує угод — лише [SIGNAL_DAEMON] у logs/signal_daemon.log
 */

$root = dirname(__DIR__);
require $root . '/www/vendor/autoload.php';
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/engine_mode.php';
require_once __DIR__ . '/signal_queue.php';
require_once __DIR__ . '/scaling_schedule.php';
require_once $root . '/www/api/_ws_price.php';
require_once $root . '/engine/signal/symbol_index.php';
require_once $root . '/engine/engine_logic.php';
require_once $root . '/engine/signal/eval_strategy.php';
require_once $root . '/engine/signal/peak_update.php';

Dotenv\Dotenv::createImmutable($root)->safeLoad();

if (!engine_signal_daemon_enabled()) {
    fwrite(STDERR, "signal_daemon: ENGINE_MODE=" . engine_mode() . " — daemon disabled\n");
    exit(0);
}

$pidDir = $root . '/services/signal';
$pidFile = $pidDir . '/signal_daemon.pid';
$logFile = $root . '/logs/signal_daemon.log';
$stateFile = $root . '/logs/cache/signal_daemon_last.json';

if (!is_dir($pidDir)) {
    @mkdir($pidDir, 0755, true);
}
if (!is_dir(dirname($stateFile))) {
    @mkdir(dirname($stateFile), 0755, true);
}

function signal_daemon_log(string $msg): void
{
    global $logFile;
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}

function signal_daemon_pid_alive(int $pid): bool
{
    if ($pid <= 0) {
        return false;
    }
    if (function_exists('posix_kill')) {
        return @posix_kill($pid, 0);
    }
    return is_dir('/proc/' . $pid);
}

$myPid = getmypid();
if (is_readable($pidFile)) {
    $oldPid = (int)trim((string)file_get_contents($pidFile));
    if ($oldPid > 0 && $oldPid !== $myPid && signal_daemon_pid_alive($oldPid)) {
        fwrite(STDERR, "signal_daemon already running pid={$oldPid}\n");
        exit(1);
    }
}
file_put_contents($pidFile, (string)$myPid);

register_shutdown_function(static function () use ($pidFile, $myPid): void {
    if (is_readable($pidFile) && (int)trim((string)file_get_contents($pidFile)) === $myPid) {
        @unlink($pidFile);
    }
});

/** @return array<string, array{ts:int,mid:float}> */
function signal_daemon_load_state(): array
{
    global $stateFile;
    if (!is_readable($stateFile)) {
        return [];
    }
    $j = json_decode((string)file_get_contents($stateFile), true);
    return is_array($j) ? $j : [];
}

/** @param array<string, array{ts:int,mid:float}> $state */
function signal_daemon_save_state(array $state): void
{
    global $stateFile;
    @file_put_contents(
        $stateFile,
        json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
}

signal_daemon_log('SIGNAL_DAEMON start pid=' . $myPid . ' mode=' . engine_mode());

$evalFile = $root . '/engine/signal/eval_strategy.php';
$evalFileMtime = is_file($evalFile) ? (int)filemtime($evalFile) : 0;

$db = db();
$lastState = signal_daemon_load_state();
$noneThrottle = [];
$loop = 0;
$lastReconcilePass = 0;
$pollUs = (int)(getenv('SIGNAL_DAEMON_POLL_US') ?: ($_ENV['SIGNAL_DAEMON_POLL_US'] ?? 300000));
$pollUs = max(100000, min(2000000, $pollUs));
$reconcileEverySec = max(30, min(180, (int)(getenv('SIGNAL_DAEMON_RECONCILE_EVERY_SEC') ?: ($_ENV['SIGNAL_DAEMON_RECONCILE_EVERY_SEC'] ?? 60))));

while (true) {
    $loop++;
    if ($loop % 200 === 0) {
        refresh_env_from_disk();
        if (is_file($evalFile)) {
            $mt = (int)filemtime($evalFile);
            if ($mt > $evalFileMtime) {
                signal_daemon_log('SIGNAL_DAEMON exit: eval_strategy.php updated, restart for new code');
                break;
            }
        }
        if (!engine_signal_daemon_enabled()) {
            signal_daemon_log('SIGNAL_DAEMON stop: mode=' . engine_mode());
            break;
        }
        if ($loop % 600 === 0) {
            try {
                symbol_index_rebuild($db);
            } catch (Throwable $e) {
                signal_daemon_log('symbol_index rebuild err: ' . $e->getMessage());
            }
        }
    }

    // Періодичний RECONCILE навіть без зміни ціни — інакше last_tick «вмирає», коли WS-пара зависла.
    $nowWall = time();
    if (($nowWall - $lastReconcilePass) >= $reconcileEverySec) {
        $lastReconcilePass = $nowWall;
        try {
            $check = $db->prepare("
                SELECT id FROM job_queue
                WHERE user_id = ?
                  AND strategy_id = ?
                  AND symbol = ?
                  AND status IN ('queued','processing')
                LIMIT 1
            ");
            $rs = $db->query("
                SELECT s.id, s.user_id, s.symbol FROM strategies s
                LEFT JOIN positions p ON p.strategy_id = s.id
                WHERE s.symbol <> 'TEMPLATE' AND s.symbol NOT LIKE '%TEMPLATE%'
                  AND (
                    s.status='running'
                    OR (s.status='paused' AND p.qty IS NOT NULL AND p.qty > 1e-12)
                  )
            ");
            $nRec = 0;
            while ($rs && ($row = $rs->fetch_assoc())) {
                $sid = (int)$row['id'];
                if (!engine_strategy_uses_signal_exec($sid)) {
                    continue;
                }
                scaling_maybe_enqueue_signal_exec_reconcile(
                    $db,
                    (int)$row['user_id'],
                    $sid,
                    (string)$row['symbol'],
                    $check
                );
                $nRec++;
            }
            if ($nRec > 0 && ($loop % 20 === 0)) {
                signal_daemon_log("SIGNAL_DAEMON reconcile_pass sids={$nRec}");
            }
        } catch (Throwable $e) {
            signal_daemon_log('SIGNAL_DAEMON reconcile_pass err: ' . $e->getMessage());
        }
    }

    $prices = ws_prices_load_all();
    $now = time();
    $changed = [];

    foreach ($prices as $sym => $entry) {
        $sym = strtoupper((string)$sym);
        if ($sym === '' || $sym === '_META') {
            continue;
        }
        $norm = ws_price_normalize_entry($sym, $entry);
        if (!$norm || ($norm['mid'] ?? 0) <= 0) {
            continue;
        }
        $ts = (int)($norm['ts'] ?? 0);
        $mid = (float)$norm['mid'];
        // Не вважати «зміною» застарілий тик з кешу (маскування heartbeat).
        if ($ts > 0 && ($now - $ts) > 120) {
            unset($lastState[$sym]);
            continue;
        }
        $prev = $lastState[$sym] ?? null;
        if (
            !$prev
            || (int)($prev['ts'] ?? 0) !== $ts
            || abs((float)($prev['mid'] ?? 0) - $mid) > 1e-8
        ) {
            $changed[$sym] = ['ts' => $ts, 'mid' => $mid];
        }
    }

    // Прибрати зомбі зі state (напр. ARBUSDT після паузи).
    foreach (array_keys($lastState) as $staleSym) {
        if (!isset($prices[$staleSym]) && !isset($prices[strtoupper((string)$staleSym)])) {
            unset($lastState[$staleSym]);
        }
    }

    if ($changed !== []) {
        foreach ($changed as $sym => $px) {
            $sids = symbol_index_get_strategy_ids($sym);
            if ($sids === []) {
                continue;
            }
            $price = (float)$px['mid'];
            foreach ($sids as $sid) {
                $sid = (int)$sid;
                $st = $db->query(
                    "SELECT id, user_id, symbol, fba, tp_whole, cb_whole, status
                     FROM strategies WHERE id={$sid} LIMIT 1"
                );
                if (!$st || !($row = $st->fetch_assoc())) {
                    continue;
                }
                $status = (string)($row['status'] ?? '');
                if ($status !== 'running' && $status !== 'paused') {
                    continue;
                }
                $uid = (int)($row['user_id'] ?? 0);
                $fba = (float)($row['fba'] ?? 0);
                $tpw = (float)($row['tp_whole'] ?? 0);
                $cbw = (float)($row['cb_whole'] ?? 0);

                try {
                    $eval = signal_eval_strategy($db, $sid, $sym, $uid, $price, $tpw, $cbw, $fba, null);
                } catch (Throwable $e) {
                    signal_daemon_log("[{$sym}] sid={$sid} eval ERR " . $e->getMessage());
                    continue;
                }

                $list = $eval['signals'];

                // Trailing TP: оновити last_peak одразу (MAIN_PEAK_UPDATE раніше не потрапляв у exec).
                if (in_array('MAIN_PEAK_UPDATE', $list, true)) {
                    $peakRes = signal_position_apply_peak_update($db, $sid, $uid, $sym, $price, $tpw);
                    if (!empty($peakRes['updated'])) {
                        signal_daemon_log(
                            "[PEAK_APPLY][{$sym}] sid={$sid} peak "
                            . number_format((float)$peakRes['old_peak'], 8, '.', '')
                            . ' -> ' . number_format((float)$peakRes['new_peak'], 8, '.', '')
                            . ' tp=' . number_format((float)$peakRes['tp_price'], 8, '.', '')
                        );
                        $posFresh = get_position($db, $sid, $sym, $uid);
                        $eval = signal_eval_strategy($db, $sid, $sym, $uid, $price, $tpw, $cbw, $fba, $posFresh);
                        $list = $eval['signals'];
                    }
                }

                $sigStr = $list === [] ? 'none' : implode(',', $list);
                if ($sigStr === 'none') {
                    $lastNone = $noneThrottle[$sid] ?? 0;
                    if (($now - $lastNone) < 120) {
                        continue;
                    }
                    $noneThrottle[$sid] = $now;
                }
                signal_daemon_log(
                    "[SIGNAL_DAEMON][{$sym}] sid={$sid} status={$status} signals={$sigStr} price={$price} meta="
                    . json_encode($eval['meta'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                );

                if (engine_strategy_uses_signal_exec($sid)) {
                    $actionable = signal_actionable_from_list($list);
                    if ($status === 'paused') {
                        $actionable = signal_actionable_exits_only($actionable);
                    }
                    foreach ($actionable as $sigName) {
                        $qid = signal_queue_push(
                            $uid,
                            $sid,
                            $sym,
                            $sigName,
                            $price,
                            ['source' => 'signal_daemon', 'meta' => $eval['meta']]
                        );
                        if ($qid > 0) {
                            signal_daemon_log("[SIGNAL_ENQUEUE][{$sym}] sid={$sid} signal={$sigName} qid={$qid}");
                        }
                    }
                }
            }
            $lastState[$sym] = $px;
        }
        signal_daemon_save_state($lastState);
    }

    usleep($pollUs);
}

signal_daemon_log('SIGNAL_DAEMON exit pid=' . $myPid);
