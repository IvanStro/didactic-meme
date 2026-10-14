<?php
declare(strict_types=1);
/**
 * Platform watchdog: tick lag, trading_ops daemon, workers.
 * Sends Telegram to admin (TG_CHAT_ID) on anomalies. Dedup via app_settings.
 *
 * Cron: every 1 minute
 *   php run/platform_watchdog.php
 *
 * Env (optional):
 *   WATCHDOG_TICK_WARN_SEC=90
 *   WATCHDOG_TICK_CRIT_SEC=180
 *   WATCHDOG_STRATEGY_STALE_SEC=240
 *   WATCHDOG_ENQUEUE_WARN_SEC=45
 *   WATCHDOG_ALERT_COOLDOWN_SEC=900
 *   WATCHDOG_PRICE_WARN_SEC=40   (лише лог, без Telegram)
 *   WATCHDOG_PRICE_CRIT_SEC=90   (Telegram після 3 перевірок; ≥2 символи або age≥2×crit)
 *   WATCHDOG_PRICE_MIN_STALE_SYMS=2
 *   WATCHDOG_PRICE_COOLDOWN_SEC=1800
 */

$root = dirname(__DIR__);
require $root . '/www/vendor/autoload.php';
require_once __DIR__ . '/cron_singleton.php';
cron_singleton_acquire('platform_watchdog');
set_time_limit(55);

require_once $root . '/www/api/_worker_manager.php';
require_once $root . '/www/api/_tg_notify.php';
require_once $root . '/www/api/_ws_price.php';
require_once $root . '/engine_v2/engine_mode.php';

Dotenv\Dotenv::createImmutable($root)->safeLoad();

/**
 * V2 signal-exec strategies still update last_tick_at on RECONCILE/PROCESS.
 * Soft thresholds relative to reconcile interval — do not ignore them.
 */
function wd_v2_tick_thresholds(int $tickWarn, int $tickCrit): array
{
    $recon = (int)(getenv('V2_SIGNAL_RECONCILE_SEC') ?: ($_ENV['V2_SIGNAL_RECONCILE_SEC'] ?? 60));
    $recon = max(30, min(300, $recon));
    // warn ~3× reconcile, crit ~6× (floor at caller defaults)
    $w = max($tickWarn, $recon * 3);
    $c = max($tickCrit, $recon * 6);
    if ($c <= $w) {
        $c = $w + 30;
    }
    return [$w, $c];
}

worker_ensure_paths();

$tickWarn = max(30, (int)(getenv('WATCHDOG_TICK_WARN_SEC') ?: ($_ENV['WATCHDOG_TICK_WARN_SEC'] ?? 90)));
$tickCrit = max($tickWarn + 1, (int)(getenv('WATCHDOG_TICK_CRIT_SEC') ?: ($_ENV['WATCHDOG_TICK_CRIT_SEC'] ?? 180)));
if (function_exists('engine_v2_live_enabled') && engine_v2_live_enabled()) {
    [$tickWarn, $tickCrit] = wd_v2_tick_thresholds($tickWarn, $tickCrit);
}
$strategyStaleSec = max($tickCrit, (int)(getenv('WATCHDOG_STRATEGY_STALE_SEC') ?: ($_ENV['WATCHDOG_STRATEGY_STALE_SEC'] ?? 240)));
if (function_exists('engine_v2_live_enabled') && engine_v2_live_enabled()) {
    $strategyStaleSec = max($strategyStaleSec, $tickCrit);
}
$enqueueWarn = max(20, (int)(getenv('WATCHDOG_ENQUEUE_WARN_SEC') ?: ($_ENV['WATCHDOG_ENQUEUE_WARN_SEC'] ?? 45)));
$cooldown = max(60, (int)(getenv('WATCHDOG_ALERT_COOLDOWN_SEC') ?: ($_ENV['WATCHDOG_ALERT_COOLDOWN_SEC'] ?? 900)));
$queueWarn = max(20, (int)(getenv('WATCHDOG_QUEUE_WARN') ?: ($_ENV['WATCHDOG_QUEUE_WARN'] ?? 100)));
$queueCrit = max($queueWarn + 1, (int)(getenv('WATCHDOG_QUEUE_CRIT') ?: ($_ENV['WATCHDOG_QUEUE_CRIT'] ?? 300)));
$autoHeal = ((string)(getenv('WATCHDOG_AUTO_HEAL') ?: ($_ENV['WATCHDOG_AUTO_HEAL'] ?? '1')) === '1');

$m = new mysqli(
    (string)($_ENV['DB_HOST'] ?? ''),
    (string)($_ENV['DB_USER'] ?? ''),
    (string)($_ENV['DB_PASS'] ?? ''),
    (string)($_ENV['DB_NAME'] ?? '')
);
if ($m->connect_errno) {
    fwrite(STDERR, "watchdog: db " . $m->connect_error . "\n");
    exit(1);
}
$m->set_charset('utf8mb4');

function wd_should_alert(mysqli $db, string $key, int $cooldownSec): bool
{
    $keyEsc = $db->real_escape_string('watchdog_last_' . $key);
    $res = $db->query("SELECT v FROM app_settings WHERE k='$keyEsc' LIMIT 1");
    $last = 0;
    if ($res && ($row = $res->fetch_assoc())) {
        $last = (int)($row['v'] ?? 0);
    }
    if ($last > 0 && (time() - $last) < $cooldownSec) {
        return false;
    }
    $now = (string)time();
    $db->query("INSERT INTO app_settings (k,v) VALUES ('$keyEsc','$now') ON DUPLICATE KEY UPDATE v=VALUES(v)");
    return true;
}

function wd_alert(mysqli $db, string $key, string $title, array $lines, int $cooldownSec): void
{
    if (!wd_should_alert($db, $key, $cooldownSec)) {
        echo "skip_alert key=$key (cooldown)\n";
        return;
    }
    $body = implode("\n", $lines);
    $text = "🚨 <b>StrIM watchdog</b>\n<b>" . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</b>\n\n" .
        htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    [$ok, $msg] = tg_notify($text);
    echo ($ok ? 'alert_sent' : 'alert_fail') . " key=$key msg=$msg\n";
}

function wd_setting_int(mysqli $db, string $key, int $default = 0): int
{
    $keyEsc = $db->real_escape_string($key);
    $res = $db->query("SELECT v FROM app_settings WHERE k='$keyEsc' LIMIT 1");
    if ($res && ($row = $res->fetch_assoc())) {
        return (int)($row['v'] ?? $default);
    }
    return $default;
}

function wd_setting_set(mysqli $db, string $key, int $value): void
{
    $keyEsc = $db->real_escape_string($key);
    $v = (string)(int)$value;
    $db->query("INSERT INTO app_settings (k,v) VALUES ('$keyEsc','$v') ON DUPLICATE KEY UPDATE v=VALUES(v)");
}

/** @return int streak after update */
function wd_price_stale_streak_update(mysqli $db, bool $stale): int
{
    $key = 'watchdog_price_stale_streak';
    if (!$stale) {
        wd_setting_set($db, $key, 0);
        return 0;
    }
    $n = wd_setting_int($db, $key, 0) + 1;
    wd_setting_set($db, $key, $n);
    return $n;
}

function wd_ws_price_heal(string $root, string $phpBin): void
{
    require_once $root . '/run/cron_supervisor_guard.php';
    if (strim_supervisor_managed()) {
        echo "ws_heal skip: Supervisor managed\n";
        return;
    }
    // Prefer start script directly — cron_ensure often holds singleton lock and heal becomes a no-op.
    $startSh = $root . '/run/start_ws_price_cache.sh';
    if (is_file($startSh)) {
        @exec('bash ' . escapeshellarg($startSh) . ' 2>&1', $out, $code);
        echo 'ws_heal code=' . $code . ' ' . trim(implode(' ', $out)) . "\n";
        return;
    }
    $script = $root . '/run/cron_ensure_ws_price.php';
    if (!is_file($script)) {
        return;
    }
    @exec(escapeshellarg($phpBin) . ' ' . escapeshellarg($script) . ' 2>&1', $out, $code);
    echo 'ws_heal code=' . $code . ' ' . trim(implode(' ', $out)) . "\n";
}

$issues = [];
$alertKeys = [];
$severity = 'ok';

// 1) Global tick lag (MySQL TIMESTAMPDIFF — timezone-safe). V2 also updates last_tick_at via reconcile.
$resTick = $m->query("
    SELECT COUNT(*) AS running,
           TIMESTAMPDIFF(SECOND, MAX(last_tick_at), NOW()) AS lag_sec
    FROM strategies
    WHERE status='running'
      AND symbol <> 'TEMPLATE'
      AND symbol NOT LIKE '%TEMPLATE%'
");
$tickRow = $resTick ? $resTick->fetch_assoc() : null;
$runningN = (int)($tickRow['running'] ?? 0);
$tickLag = isset($tickRow['lag_sec']) ? (int)$tickRow['lag_sec'] : null;
if ($runningN > 0 && $tickLag !== null && $tickLag < 0) {
    $tickLag = 0;
}
if ($runningN > 0 && ($tickLag === null || $tickLag > $tickCrit)) {
    $severity = 'crit';
    $issues[] = 'tick_lag=' . ($tickLag ?? '?') . 's (crit>' . $tickCrit . 's), running=' . $runningN;
    $alertKeys[] = 'tick_lag_crit';
} elseif ($runningN > 0 && $tickLag !== null && $tickLag > $tickWarn) {
    if ($severity !== 'crit') {
        $severity = 'warn';
    }
    $issues[] = 'tick_lag=' . $tickLag . 's (warn>' . $tickWarn . 's)';
    $alertKeys[] = 'tick_lag_warn';
}

// 2) Per-strategy stale — лише running і лише якщо lag > strategyStaleSec (не черга на 90s)
$stale = $m->query("
    SELECT id, symbol, user_id, last_tick_at, TIMESTAMPDIFF(SECOND, last_tick_at, NOW()) AS lag_sec
    FROM strategies
    WHERE status='running'
      AND symbol <> 'TEMPLATE'
      AND symbol NOT LIKE '%TEMPLATE%'
      AND last_tick_at IS NOT NULL
      AND last_tick_at < (NOW() - INTERVAL {$strategyStaleSec} SECOND)
    ORDER BY last_tick_at ASC
    LIMIT 5
");
$staleLines = [];
while ($stale && ($r = $stale->fetch_assoc())) {
    $sid = (int)$r['id'];
    $staleLines[] = sprintf(
        'sid=%d uid=%d %s lag=%ss',
        $sid,
        (int)$r['user_id'],
        (string)$r['symbol'],
        (string)$r['lag_sec']
    );
    $alertKeys[] = 'stale_sid_' . $sid;
}
if ($staleLines) {
    $severity = 'crit';
    $issues[] = "stale_running_strategies (>" . $strategyStaleSec . "s):\n" . implode("\n", $staleLines);
}

// 3) trading_ops daemon
$daemonAlive = worker_trading_ops_daemon_alive();
$daemonPid = worker_trading_ops_pid();
if (!$daemonAlive) {
    $severity = 'crit';
    $issues[] = 'trading_ops_daemon=DOWN pid_file=' . $daemonPid;
    $alertKeys[] = 'daemon_down';
}

// 3b) WS price freshness (V2 — пороги м’якші; Telegram лише при стійкому/масовому stale)
$priceWorst = null;
$priceHealDone = false;
if ($runningN > 0) {
    $phpBinEarly = worker_php_binary();
    $priceWarn = max(15, (int)(getenv('WATCHDOG_PRICE_WARN_SEC') ?: ($_ENV['WATCHDOG_PRICE_WARN_SEC'] ?? 40)));
    $priceCrit = max($priceWarn + 10, (int)(getenv('WATCHDOG_PRICE_CRIT_SEC') ?: ($_ENV['WATCHDOG_PRICE_CRIT_SEC'] ?? 90)));
    $priceHealAt = max(20, (int)(getenv('WATCHDOG_PRICE_HEAL_SEC') ?: ($_ENV['WATCHDOG_PRICE_HEAL_SEC'] ?? 50)));
    $priceStreakNeed = max(2, (int)(getenv('WATCHDOG_PRICE_STREAK_ALERT') ?: ($_ENV['WATCHDOG_PRICE_STREAK_ALERT'] ?? 3)));
    $priceMinStaleSyms = max(1, (int)(getenv('WATCHDOG_PRICE_MIN_STALE_SYMS') ?: ($_ENV['WATCHDOG_PRICE_MIN_STALE_SYMS'] ?? 2)));
    $symList = [];
    $rsSym = $m->query("
        SELECT DISTINCT s.symbol FROM strategies s
        LEFT JOIN positions p ON p.strategy_id = s.id
        WHERE s.symbol <> 'TEMPLATE' AND s.symbol NOT LIKE '%TEMPLATE%'
          AND (
            s.status='running'
            OR (s.status='paused' AND p.qty IS NOT NULL AND p.qty > 1e-12)
          )
    ");
    while ($rsSym && ($sr = $rsSym->fetch_assoc())) {
        $symList[] = (string)$sr['symbol'];
    }
    $ph = ws_prices_health_summary($symList);
    // Ігноруємо «зомбі» >1h у worst_age (старий ts у кеші), щоб один ARB не робив worst=76000.
    $agesUseful = [];
    $zombies = [];
    foreach (($ph['symbols'] ?? []) as $sym => $info) {
        $age = $info['age_sec'] ?? null;
        if ($age === null) {
            continue;
        }
        if ((int)$age > 3600) {
            $zombies[] = $sym . ':' . (int)$age . 's';
            continue;
        }
        $agesUseful[] = (int)$age;
    }
    $priceWorst = $agesUseful !== [] ? max($agesUseful) : ($ph['worst_age_sec'] ?? null);
    // Якщо всі живі символи свіжі, а є лише зомбі — це не platform price_stale.
    if ($agesUseful !== [] && max($agesUseful) <= $priceWarn && $zombies !== []) {
        echo 'price_zombie ' . implode(',', array_slice($zombies, 0, 4)) . " (excluded from crit)\n";
    }

    if ($autoHeal && $priceWorst !== null && $priceWorst > $priceHealAt) {
        wd_ws_price_heal($root, $phpBinEarly);
        $priceHealDone = true;
        $ph2 = ws_prices_health_summary($symList);
        $ph = $ph2;
        $agesUseful = [];
        foreach (($ph['symbols'] ?? []) as $sym => $info) {
            $age = $info['age_sec'] ?? null;
            if ($age !== null && (int)$age <= 3600) {
                $agesUseful[] = (int)$age;
            }
        }
        $priceWorst = $agesUseful !== [] ? max($agesUseful) : ($ph['worst_age_sec'] ?? null);
    }

    $staleSyms = [];
    foreach (($ph['symbols'] ?? []) as $sym => $info) {
        $age = $info['age_sec'] ?? null;
        if ($age !== null && (int)$age > $priceCrit && (int)$age <= 3600) {
            $staleSyms[] = $sym . ':' . (int)$age . 's';
        }
    }
    $staleSymN = count($staleSyms);
    $symTotal = max(1, count($symList));
    // Crit лише якщо реально масовий/стійкий stale (не 1 пара і не reconnect-бліп).
    $needStale = max($priceMinStaleSyms, (int)ceil($symTotal * 0.35));
    $priceStaleForAlert = ($priceWorst !== null && $priceWorst > $priceCrit && $staleSymN >= $needStale);
    $streak = wd_price_stale_streak_update($m, $priceStaleForAlert);

    if ($priceStaleForAlert && $streak >= $priceStreakNeed) {
        $severity = 'crit';
        $symPart = $staleSyms !== [] ? (' [' . implode(', ', array_slice($staleSyms, 0, 6)) . ']') : '';
        $issues[] = 'price_stale worst_age=' . $priceWorst . 's (crit>' . $priceCrit . 's, streak=' . $streak
            . ', stale_syms=' . $staleSymN . '/' . $symTotal . ')' . $symPart . ', running=' . $runningN;
        $alertKeys[] = 'price_stale';
    } elseif ($priceWorst !== null && $priceWorst > $priceWarn) {
        echo 'price_warn worst_age=' . $priceWorst . "s stale_syms={$staleSymN} (no Telegram)\n";
        if ($severity !== 'crit') {
            $severity = 'warn';
        }
        $issues[] = 'price_stale worst_age=' . $priceWorst . 's (warn>' . $priceWarn . 's)';
    }
}

// 4) Enqueue heartbeat (written by trading_ops_daemon each loop)
$enqueueAge = worker_read_heartbeat($m, 'trading_ops_last_enqueue_utc', 86400);
if ($daemonAlive && ($enqueueAge === null || $enqueueAge > $enqueueWarn)) {
    $severity = 'crit';
    $issues[] = 'enqueue_silent age=' . ($enqueueAge === null ? 'null' : ($enqueueAge . 's')) . ' (>' . $enqueueWarn . 's)';
    $alertKeys[] = 'enqueue_silent';
}

// 5) Queue depth (scaling stage 1)
$queuedN = 0;
$rq = $m->query("SELECT COUNT(*) AS c FROM job_queue WHERE status='queued'");
if ($rq && ($qr = $rq->fetch_assoc())) {
    $queuedN = (int)($qr['c'] ?? 0);
}
if ($queuedN > $queueCrit) {
    $severity = 'crit';
    $issues[] = 'job_queue_queued=' . $queuedN . ' (crit>' . $queueCrit . ')';
    $alertKeys[] = 'queue_crit';
} elseif ($queuedN > $queueWarn) {
    if ($severity !== 'crit') {
        $severity = 'warn';
    }
    $issues[] = 'job_queue_queued=' . $queuedN . ' (warn>' . $queueWarn . ')';
    $alertKeys[] = 'queue_warn';
}

// 6) Workers
$settings = worker_get_settings($m);
$workers = worker_running_pids();
$workerN = count($workers);
if ((int)$settings['auto'] === 1 && $workerN < (int)$settings['target']) {
    if ($severity !== 'crit') {
        $severity = 'warn';
    }
    $issues[] = 'workers=' . $workerN . ' target=' . (int)$settings['target'];
    $alertKeys[] = 'workers_low';
}

// 7) Stuck processing jobs
$stuck = $m->query("
    SELECT COUNT(*) AS c FROM job_queue
    WHERE status='processing'
      AND locked_at IS NOT NULL
      AND locked_at < (NOW() - INTERVAL 120 SECOND)
");
$stuckN = $stuck ? (int)($stuck->fetch_assoc()['c'] ?? 0) : 0;
if ($stuckN > 0) {
    if ($severity !== 'crit') {
        $severity = 'warn';
    }
    $issues[] = 'stuck_processing_jobs=' . $stuckN;
    $alertKeys[] = 'stuck_jobs';
}

// 8) Failed jobs last hour
$failed = $m->query("SELECT COUNT(*) c FROM job_queue WHERE status='failed' AND updated_at >= (NOW() - INTERVAL 1 HOUR)");
$failedN = $failed ? (int)($failed->fetch_assoc()['c'] ?? 0) : 0;
if ($failedN > 0) {
    $issues[] = 'failed_jobs_1h=' . $failedN;
    $alertKeys[] = 'failed_jobs';
}

$summary = 'severity=' . $severity . ' issues=' . count($issues) .
    ' tick_lag=' . ($tickLag ?? 'null') .
    ' price_worst=' . ($priceWorst ?? 'null') .
    ' queued=' . $queuedN .
    ' daemon=' . ($daemonAlive ? 'up' : 'down') .
    ' workers=' . $workerN;
echo $summary . "\n";

if ($issues === []) {
    worker_touch_heartbeat($m, 'watchdog_last_ok_utc');
    exit(0);
}

$healActions = [];
if ($autoHeal && $severity === 'crit') {
    $phpBin = worker_php_binary();
    require_once $root . '/run/cron_supervisor_guard.php';
    if (!$daemonAlive) {
        if (strim_supervisor_managed()) {
            $sr = worker_trading_ops_supervisor_restart();
            $healActions[] = 'supervisor_restart_trading_ops ' . ($sr['ok'] ? 'ok' : 'fail') . ' ' . $sr['detail'];
        } else {
            worker_trading_ops_break_stale_lock();
            $code = worker_trading_ops_spawn();
            $healActions[] = 'spawned_trading_ops_daemon code=' . $code;
        }
    }
    if ($runningN > 0 && in_array('price_stale', $alertKeys, true) && !$priceHealDone) {
        wd_ws_price_heal($root, $phpBin);
        $healActions[] = 'ws_price_heal';
    }
    if ($workerN < (int)$settings['target'] && (int)$settings['auto'] === 1) {
        $started = worker_start_missing((int)$settings['target']);
        $healActions[] = 'started_workers=' . count($started['started']);
    }
}

$title = $severity === 'crit' ? 'Критична аномалія платформи' : 'Попередження платформи';
$lines = array_merge(
    $issues,
    ['checked_at=' . gmdate('Y-m-d H:i:s') . ' UTC'],
    $healActions ? ['auto_heal:', ...$healActions] : []
);

sort($alertKeys);
$dedupKey = $severity . '_' . implode('_', array_unique($alertKeys));
$alertCooldown = $cooldown;
if ($severity === 'crit') {
    $alertCooldown = min(300, $cooldown);
}
if (in_array('price_stale', $alertKeys, true) && count($alertKeys) === 1) {
    $priceCooldown = max(600, (int)(getenv('WATCHDOG_PRICE_COOLDOWN_SEC') ?: ($_ENV['WATCHDOG_PRICE_COOLDOWN_SEC'] ?? 1800)));
    $alertCooldown = $priceCooldown;
    $dedupKey = 'crit_price_stale';
}

// Попередження price_stale — тільки в лог cron, без Telegram
$tgAlert = !($severity === 'warn' && count($issues) === 1 && str_contains((string)$issues[0], 'price_stale'));
if ($issues !== [] && $tgAlert) {
    wd_alert($m, $dedupKey, $title, $lines, $alertCooldown);
} elseif ($issues !== []) {
    echo "skip_tg severity=$severity (price warn or muted)\n";
}

// ADM.TOOLS показує «ERROR #2» при exit(2). Після auto-heal перевіряємо, чи відновилось.
if ($autoHeal && $severity === 'crit') {
    $healed = true;
    if (in_array('daemon_down', $alertKeys, true) && !worker_trading_ops_daemon_alive()) {
        $healed = false;
    }
    if ($healed) {
        echo "post_heal=ok\n";
        exit(0);
    }
}

// Cron ADM.TOOLS: завжди 0 (алерт у Telegram). Критичність — у логах severity=...
exit(0);
