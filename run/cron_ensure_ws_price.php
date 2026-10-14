<?php
declare(strict_types=1);

/**
 * Cron (~1 min): WS price daemon — PID + свіжість ws_prices.json.
 *   php run/cron_ensure_ws_price.php
 */

$root = dirname(__DIR__);
require $root . '/www/vendor/autoload.php';
require_once __DIR__ . '/cron_singleton.php';
cron_singleton_acquire('cron_ensure_ws_price');
set_time_limit(45);

Dotenv\Dotenv::createImmutable($root)->safeLoad();
require_once __DIR__ . '/cron_supervisor_guard.php';

$pidPath = $root . '/services/ws/ws_price_cache.pid';
$dataPath = $root . '/www/data/ws_prices.json';
$logPath = $root . '/logs/ws_price_cache.log';
$startSh = $root . '/run/start_ws_price_cache.sh';

function ws_price_pid_alive(int $pid): bool
{
    if ($pid <= 0) {
        return false;
    }
    if (function_exists('posix_kill')) {
        return @posix_kill($pid, 0);
    }
    return is_dir('/proc/' . $pid);
}

/**
 * Daemon «мертвий» лише якщо файл/_meta не оновлювались.
 * Вік окремого символу (тиха пара) НЕ привід вбивати весь WS — це спричиняє restart-шторм.
 */
function ws_price_data_stale(string $path, int $maxAgeSec): bool
{
    if (!is_file($path)) {
        return true;
    }
    $age = time() - (int)(filemtime($path) ?: 0);
    if ($age > $maxAgeSec) {
        return true;
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return true;
    }
    $j = json_decode($raw, true);
    if (!is_array($j)) {
        return true;
    }
    $metaTs = (int)($j['_meta']['updated_at'] ?? 0);
    if ($metaTs > 0 && (time() - $metaTs) > $maxAgeSec) {
        return true;
    }
    // Немає жодної ціни взагалі — теж stale
    $n = 0;
    foreach ($j as $sym => $row) {
        if ($sym === '_meta' || !is_array($row)) {
            continue;
        }
        if ((float)($row['mid'] ?? $row['price'] ?? 0) > 0) {
            $n++;
        }
    }
    return $n === 0;
}

$maxAge = max(45, (int)(getenv('WS_PRICE_MAX_AGE_SEC') ?: ($_ENV['WS_PRICE_MAX_AGE_SEC'] ?? 60)));
$pid = is_readable($pidPath) ? (int)trim((string)file_get_contents($pidPath)) : 0;
$alive = ws_price_pid_alive($pid);

// Supervisor / orphans: якщо PID-файл застарів, шукаємо живий python-процес.
if (!$alive) {
    $pgrepOut = [];
    @exec('pgrep -f ' . escapeshellarg($root . '/services/ws/ws_price_cache.py') . ' 2>/dev/null', $pgrepOut);
    foreach ($pgrepOut as $line) {
        $cand = (int) trim((string) $line);
        if ($cand > 0 && ws_price_pid_alive($cand)) {
            $pid = $cand;
            $alive = true;
            @file_put_contents($pidPath, (string) $pid);
            break;
        }
    }
}

$stale = ws_price_data_stale($dataPath, $maxAge);

/**
 * Якщо ≥2 running-символи мають age > softAge — м’який рестарт з cooldown (не death-spiral).
 * Один тихий символ ігноруємо; масовий stale після нашого heartbeat-only фіксу — ні.
 */
function ws_price_running_symbols_mass_stale(string $path, int $softAgeSec, int $minStaleSyms = 2): array
{
    if (!is_file($path)) {
        return ['stale' => false, 'syms' => []];
    }
    $raw = @file_get_contents($path);
    $j = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($j)) {
        return ['stale' => false, 'syms' => []];
    }
    $root = dirname(__DIR__);
    try {
        require_once $root . '/www/vendor/autoload.php';
        Dotenv\Dotenv::createImmutable($root)->safeLoad();
        $m = new mysqli(
            (string)($_ENV['DB_HOST'] ?? ''),
            (string)($_ENV['DB_USER'] ?? ''),
            (string)($_ENV['DB_PASS'] ?? ''),
            (string)($_ENV['DB_NAME'] ?? '')
        );
        if ($m->connect_errno) {
            return ['stale' => false, 'syms' => []];
        }
        $m->set_charset('utf8mb4');
        // Running + paused-with-open (same set as ws_price_cache.py — wind-down needs live prices).
        $rs = $m->query(
            "SELECT DISTINCT s.symbol FROM strategies s
             LEFT JOIN positions p ON p.strategy_id = s.id
             WHERE s.symbol<>'TEMPLATE' AND s.symbol NOT LIKE '%TEMPLATE%'
               AND (
                 s.status='running'
                 OR (s.status='paused' AND p.qty IS NOT NULL AND p.qty > 1e-12)
               )"
        );
        $staleSyms = [];
        $now = time();
        while ($rs && ($row = $rs->fetch_assoc())) {
            $sym = strtoupper((string)$row['symbol']);
            $entry = $j[$sym] ?? null;
            if (!is_array($entry)) {
                $staleSyms[] = $sym . ':missing';
                continue;
            }
            $ts = (int)($entry['ts'] ?? 0);
            $age = $ts > 0 ? ($now - $ts) : 999999;
            if ($age > $softAgeSec) {
                $staleSyms[] = $sym . ':' . $age . 's';
            }
        }
        $m->close();
        return ['stale' => count($staleSyms) >= $minStaleSyms, 'syms' => $staleSyms];
    } catch (Throwable $e) {
        return ['stale' => false, 'syms' => []];
    }
}

$softAge = max(90, (int)(getenv('WS_PRICE_SOFT_STALE_SEC') ?: ($_ENV['WS_PRICE_SOFT_STALE_SEC'] ?? 120)));
$mass = ['stale' => false, 'syms' => []];
if ($alive && !$stale) {
    $mass = ws_price_running_symbols_mass_stale($dataPath, $softAge, 2);
    if (!empty($mass['stale'])) {
        $cooldownFile = $root . '/logs/cache/ws_price_soft_restart.ts';
        $last = is_readable($cooldownFile) ? (int)trim((string)file_get_contents($cooldownFile)) : 0;
        if ((time() - $last) >= 300) {
            @file_put_contents($cooldownFile, (string)time());
            $stale = true;
            echo date('c') . ' ws_price_cache mass_symbol_stale [' . implode(',', array_slice($mass['syms'], 0, 6)) . "] — soft restart\n";
        } else {
            echo date('c') . " ws_price_cache mass_symbol_stale cooldown skip\n";
        }
    }
}

if ($alive && !$stale) {
    require_once $root . '/www/api/_ws_price.php';
    $mirrored = ws_prices_mirror_to_redis();
    echo date('c') . " ws_price_cache ok pid={$pid} redis_mirror={$mirrored}\n";
    exit(0);
}

if ($alive && $stale) {
    echo date('c') . " ws_price_cache stale_data pid={$pid} — restarting\n";
    if (strim_supervisor_managed()) {
        // Supervisor сам рестартне після kill; cron не спавнить.
        if (function_exists('posix_kill')) {
            @posix_kill($pid, SIGTERM);
        } else {
            @exec('kill -TERM ' . (int) $pid);
        }
        echo date('c') . " ws_price_cache: asked Supervisor-managed process to exit\n";
        exit(0);
    }
    if (function_exists('posix_kill')) {
        @posix_kill($pid, SIGTERM);
    } else {
        @exec('kill -TERM ' . (int)$pid);
    }
    usleep(500000);
    if (ws_price_pid_alive($pid)) {
        @exec('kill -9 ' . (int)$pid);
        usleep(200000);
    }
    @unlink($pidPath);
}

if (!$alive && strim_supervisor_managed()) {
    echo date('c') . " ws_price_cache down — Supervisor managed, skip spawn\n";
    exit(0);
}

if (!is_executable($startSh)) {
    @chmod($startSh, 0755);
}
if (!is_executable($startSh)) {
    fwrite(STDERR, "start script missing: {$startSh}\n");
    exit(1);
}

@exec('bash ' . escapeshellarg($startSh) . ' 2>&1', $out, $code);
$line = date('c') . ' cron_ensure_ws_price: start code=' . $code . ' ' . trim(implode(' ', $out)) . PHP_EOL;
@file_put_contents($logPath, $line, FILE_APPEND);
echo $line;
exit($code === 0 ? 0 : 1);
