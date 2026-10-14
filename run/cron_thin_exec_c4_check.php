<?php
declare(strict_types=1);

/**
 * C4 gate: щогодинна перевірка thin-exec canary → Telegram адміну (завжди).
 *
 *   php run/cron_thin_exec_c4_check.php
 *   php run/cron_thin_exec_c4_check.php --dry-run
 *
 * Cron:
 *   12 * * * * /bin/bash /home/bot20/strim.in.ua/run/cron_thin_exec_c4_check.sh
 *
 * Env:
 *   THIN_EXEC_C4_SINCE=2026-09-25 17:18:00   (старт canary C0)
 *   THIN_EXEC_C4_TARGET_SOLD=10              (Owner: gate по N thin_sold, не по годинах)
 *   THIN_EXEC_C4_WINDOW_SEC=3600
 *   THIN_EXEC_C4_NOTIFY=1                    (0 = лише лог)
 */

$root = dirname(__DIR__);
require $root . '/www/vendor/autoload.php';
require_once __DIR__ . '/cron_singleton.php';
require_once __DIR__ . '/cron_host_guard.php';
require_once $root . '/engine_v2/thin_exec_config.php';

cron_singleton_acquire('cron_thin_exec_c4_check');
set_time_limit(120);

Dotenv\Dotenv::createImmutable($root)->safeLoad();
cron_host_guard_or_exit('cron_thin_exec_c4_check');
require_once $root . '/www/api/_tg_notify.php';

if (function_exists('date_default_timezone_set')) {
    @date_default_timezone_set('Europe/Kyiv');
}

$dryRun = in_array('--dry-run', $argv ?? [], true);
$windowSec = max(300, (int)(getenv('THIN_EXEC_C4_WINDOW_SEC') ?: ($_ENV['THIN_EXEC_C4_WINDOW_SEC'] ?? 3600)));
$sinceC0Raw = trim((string)(getenv('THIN_EXEC_C4_SINCE') ?: ($_ENV['THIN_EXEC_C4_SINCE'] ?? '2026-09-25 17:18:00')));
$sinceC0Ts = strtotime($sinceC0Raw) ?: strtotime('2026-09-25 17:18:00');
$windowStartTs = time() - $windowSec;
$notify = ((string)(getenv('THIN_EXEC_C4_NOTIFY') ?: ($_ENV['THIN_EXEC_C4_NOTIFY'] ?? '1')) !== '0');
$targetSold = max(1, (int)(getenv('THIN_EXEC_C4_TARGET_SOLD') ?: ($_ENV['THIN_EXEC_C4_TARGET_SOLD'] ?? 10)));

$mode = thin_exec_mode();
$canaryIds = thin_exec_canary_sids();
$actions = thin_exec_action_prefixes();
$fallbackOn = thin_exec_fallback_full();

$db = new mysqli(
    (string)($_ENV['DB_HOST'] ?? ''),
    (string)($_ENV['DB_USER'] ?? ''),
    (string)($_ENV['DB_PASS'] ?? ''),
    (string)($_ENV['DB_NAME'] ?? '')
);
if ($db->connect_errno) {
    fwrite(STDERR, 'db: ' . $db->connect_error . PHP_EOL);
    exit(1);
}
$db->set_charset('utf8mb4');

/**
 * @return list<string>
 */
function c4_log_sources(string $root): array
{
    $out = [];
    $arch = glob($root . '/logs/archive/thin_exec.*.log.gz') ?: [];
    sort($arch);
    foreach ($arch as $f) {
        $out[] = $f;
    }
    $cur = $root . '/logs/thin_exec.log';
    if (is_file($cur)) {
        $out[] = $cur;
    }
    return $out;
}

/**
 * @return \Generator<int, string>
 */
function c4_log_lines(string $path): \Generator
{
    if (str_ends_with($path, '.gz')) {
        $fh = @gzopen($path, 'rb');
        if (!$fh) {
            return;
        }
        while (!gzeof($fh)) {
            $line = gzgets($fh);
            if ($line === false) {
                break;
            }
            yield $line;
        }
        gzclose($fh);
        return;
    }
    $fh = @fopen($path, 'rb');
    if (!$fh) {
        return;
    }
    while (($line = fgets($fh)) !== false) {
        yield $line;
    }
    fclose($fh);
}

/**
 * @param list<int> $canaryIds
 * @return array<string,mixed>
 */
function c4_parse_thin_log(string $root, int $fromTs, array $canaryIds): array
{
    $canarySet = array_fill_keys($canaryIds, true);
    $stats = [
        'thin_sold' => 0,
        'thin_noop' => 0,
        'fallback' => 0,
        'thin_err' => 0,
        'sub_sell_jobs' => 0,
        'shadow_would' => 0,
        'paths' => [],
        'fallback_reasons' => [],
        'noop_reasons' => [],
        'first_ts' => null,
        'last_ts' => null,
    ];

    foreach (c4_log_sources($root) as $src) {
        foreach (c4_log_lines($src) as $line) {
            if (!preg_match('/^\[(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2})\]/', $line, $tm)) {
                continue;
            }
            $tsRaw = str_replace('T', ' ', $tm[1]);
            $ts = strtotime($tsRaw);
            if ($ts === false || $ts < $fromTs) {
                continue;
            }
            if (!preg_match('/\bsid=(\d+)\b/', $line, $sm)) {
                continue;
            }
            $sid = (int)$sm[1];
            if ($canarySet !== [] && !isset($canarySet[$sid])) {
                continue;
            }

            $tsStr = date('Y-m-d H:i:s', $ts);
            $stats['first_ts'] = $stats['first_ts'] ?? $tsStr;
            $stats['last_ts'] = $tsStr;

            if (preg_match('/\bsignal=(SUB_SELL\S*)\b/', $line)) {
                if (str_contains($line, 'exec path=')) {
                    $stats['sub_sell_jobs']++;
                }
            }

            if (preg_match('/exec path=(\S+)/', $line, $pm)) {
                $p = $pm[1];
                $stats['paths'][$p] = ($stats['paths'][$p] ?? 0) + 1;
            }

            if (str_contains($line, 'thin result') && preg_match('/\bstatus=(\w+)/', $line, $st)) {
                $status = $st[1];
                $reason = '';
                if (preg_match('/\breason=(\S+)/', $line, $rm)) {
                    $reason = $rm[1];
                }
                if ($status === 'sold') {
                    $stats['thin_sold']++;
                } elseif ($status === 'noop') {
                    $stats['thin_noop']++;
                    if ($reason !== '') {
                        $stats['noop_reasons'][$reason] = ($stats['noop_reasons'][$reason] ?? 0) + 1;
                    }
                } elseif ($status === 'fallback') {
                    $stats['fallback']++;
                    if ($reason !== '') {
                        $stats['fallback_reasons'][$reason] = ($stats['fallback_reasons'][$reason] ?? 0) + 1;
                    }
                }
            }

            if (str_contains($line, 'FALLBACK_FULL') || str_contains($line, 'path=full+fallback')) {
                // already counted via thin result status=fallback when present
                if (!str_contains($line, 'thin result')) {
                    $stats['fallback']++;
                }
            }

            if (str_contains($line, 'thin ERR') || str_contains($line, 'thin SUB_SELL ERR')) {
                $stats['thin_err']++;
            }

            if (str_contains($line, 'shadow WOULD_SELL')) {
                $stats['shadow_would']++;
            }
        }
    }

    return $stats;
}

/**
 * @param list<int> $canaryIds
 * @return array{sells:int,double_sp:int,doubles:list<string>,rows:list<array<string,mixed>>}
 */
function c4_ledger_canary(mysqli $db, array $canaryIds, string $sinceSql): array
{
    $out = ['sells' => 0, 'double_sp' => 0, 'doubles' => [], 'rows' => []];
    if ($canaryIds === []) {
        return $out;
    }
    $ids = implode(',', array_map('intval', $canaryIds));
    $sinceEsc = $db->real_escape_string($sinceSql);
    $r = $db->query(
        "SELECT id, strategy_id, created_at, meta
         FROM trade_ledger
         WHERE strategy_id IN ({$ids})
           AND event_type='SELL_SUB'
           AND created_at >= '{$sinceEsc}'
         ORDER BY id ASC"
    );
    /** @var array<string,int> $bySp */
    $bySp = [];
    while ($r && ($row = $r->fetch_assoc())) {
        $out['sells']++;
        $out['rows'][] = $row;
        $meta = (string)($row['meta'] ?? '');
        $spId = 0;
        if (preg_match('/"sub_position_id"\s*:\s*(\d+)/', $meta, $m)) {
            $spId = (int)$m[1];
        }
        $key = ((int)$row['strategy_id']) . ':' . $spId;
        $bySp[$key] = ($bySp[$key] ?? 0) + 1;
    }
    foreach ($bySp as $key => $n) {
        if ($n > 1 && !str_ends_with($key, ':0')) {
            $out['double_sp']++;
            $out['doubles'][] = $key . '×' . $n;
        }
    }
    return $out;
}

$windowStats = c4_parse_thin_log($root, $windowStartTs, $canaryIds);
$sinceC0Stats = c4_parse_thin_log($root, $sinceC0Ts, $canaryIds);

$windowSql = date('Y-m-d H:i:s', $windowStartTs);
$sinceC0Sql = date('Y-m-d H:i:s', $sinceC0Ts);
$ledgerH = c4_ledger_canary($db, $canaryIds, $windowSql);
$ledgerC0 = c4_ledger_canary($db, $canaryIds, $sinceC0Sql);

/**
 * Count live PHP workers; ignore shells whose cmdline merely mentions the path.
 */
function c4_count_php_procs(string $scriptBasename): int
{
    $out = [];
    @exec('pgrep -af ' . escapeshellarg($scriptBasename) . ' 2>/dev/null', $out);
    $n = 0;
    foreach ($out as $line) {
        $line = trim((string)$line);
        if ($line === '' || str_contains($line, 'pgrep')) {
            continue;
        }
        if (preg_match('/\bphp(\d+)?\b.*' . preg_quote($scriptBasename, '/') . '/', $line)) {
            $n++;
        }
    }
    return $n;
}

$daemonN = c4_count_php_procs('engine_v2/signal_daemon.php');
$execN = c4_count_php_procs('engine_v2/exec_worker.php');

$tick = $db->query(
    "SELECT COUNT(*) n, COALESCE(MAX(TIMESTAMPDIFF(SECOND,last_tick_at,NOW())),0) mx
     FROM strategies WHERE status='running'"
)->fetch_assoc();
$queued = (int)($db->query("SELECT COUNT(*) c FROM signal_queue WHERE status='queued'")->fetch_assoc()['c'] ?? 0);
$failed1h = 0;
$fr = $db->query(
    "SELECT COUNT(*) c FROM signal_queue
     WHERE status='failed' AND updated_at >= '" . $db->real_escape_string($windowSql) . "'"
);
if ($fr && ($frow = $fr->fetch_assoc())) {
    $failed1h = (int)$frow['c'];
}

$canaryRows = [];
if ($canaryIds !== []) {
    $ids = implode(',', array_map('intval', $canaryIds));
    $cr = $db->query(
        "SELECT id, symbol, fba, status,
                TIMESTAMPDIFF(SECOND, last_tick_at, NOW()) lag_s
         FROM strategies WHERE id IN ({$ids})"
    );
    while ($cr && ($row = $cr->fetch_assoc())) {
        $canaryRows[] = $row;
    }
}

$thinAttempts = $windowStats['thin_sold'] + $windowStats['thin_noop'] + $windowStats['fallback'];
$fallbackRate = $thinAttempts > 0
    ? round(100.0 * $windowStats['fallback'] / $thinAttempts, 1)
    : 0.0;

$issues = [];
$warnings = [];

if ($mode !== 'canary' && $mode !== 'on') {
    $issues[] = "THIN_EXEC_MODE={$mode} (очікували canary/on)";
}
if ($canaryIds === []) {
    $issues[] = 'CANARY_SIDS порожній';
}
if ($daemonN < 1) {
    $issues[] = "signal_daemon count={$daemonN} (очікуємо ≥1)";
} elseif ($daemonN > 1) {
    $warnings[] = "signal_daemon count={$daemonN} (бажано 1 — можливий дубль)";
}
if ($execN < 1) {
    $issues[] = "exec_worker count={$execN}";
} elseif ($execN > 4) {
    $warnings[] = "exec_worker count={$execN} (бажано 4 — можливий дубль)";
} elseif ($execN < 4) {
    $warnings[] = "exec_worker count={$execN} (очікуємо 4)";
}
if ($ledgerH['double_sp'] > 0 || $ledgerC0['double_sp'] > 0) {
    $issues[] = 'підозра double SELL_SUB: h=' . $ledgerH['double_sp']
        . ' c0=' . $ledgerC0['double_sp']
        . ' [' . implode(', ', array_slice(array_merge($ledgerH['doubles'], $ledgerC0['doubles']), 0, 5)) . ']';
}
if ($windowStats['thin_err'] > 0) {
    $warnings[] = 'thin ERR за годину: ' . $windowStats['thin_err'];
}
if ($fallbackRate > 5.0 && $thinAttempts >= 3) {
    $warnings[] = "fallback rate {$fallbackRate}% (>5%)";
}
if ((int)($tick['mx'] ?? 0) > 180) {
    $warnings[] = 'max tick lag ' . (int)$tick['mx'] . 's';
}
if ($queued > 50) {
    $warnings[] = "queued={$queued}";
}
if ($failed1h > 0) {
    $warnings[] = "signal_queue failed(1h)={$failed1h}";
}

$status = 'OK';
if ($issues !== []) {
    $status = 'CRIT';
} elseif ($warnings !== []) {
    $status = 'WARN';
}

$h = static function (string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};

$canaryLabel = $canaryIds === []
    ? '—'
    : implode(', ', array_map(static function ($row) {
        return '#' . $row['id'] . ' ' . $row['symbol'] . ' lag=' . $row['lag_s'] . 's';
    }, $canaryRows));

$icon = $status === 'OK' ? '✅' : ($status === 'WARN' ? '⚠️' : '🚨');
$kyiv = date('Y-m-d H:i');

$lines = [];
$lines[] = "{$icon} <b>StrIM Thin C0 · щогодинна перевірка</b>";
$lines[] = $h("Час: {$kyiv} (Kyiv)");
$lines[] = $h("Режим: {$mode} · actions=" . implode(',', $actions) . ' · fallback=' . ($fallbackOn ? '1' : '0'));
$lines[] = $h('Canary: ' . $canaryLabel);
$lines[] = '';
$lines[] = '<b>Вікно 1 год</b>';
$lines[] = $h(sprintf(
    'thin sold=%d noop=%d fallback=%d (rate %s%%) err=%d',
    $windowStats['thin_sold'],
    $windowStats['thin_noop'],
    $windowStats['fallback'],
    (string)$fallbackRate,
    $windowStats['thin_err']
));
$lines[] = $h(sprintf(
    'SUB_SELL jobs=%d · shadow WOULD=%d · ledger SELL_SUB=%d · double=%d',
    $windowStats['sub_sell_jobs'],
    $windowStats['shadow_would'],
    $ledgerH['sells'],
    $ledgerH['double_sp']
));
if ($windowStats['fallback_reasons'] !== []) {
    arsort($windowStats['fallback_reasons']);
    $frBits = [];
    foreach (array_slice($windowStats['fallback_reasons'], 0, 4, true) as $k => $v) {
        $frBits[] = "{$k}×{$v}";
    }
    $lines[] = $h('fallback reasons: ' . implode(', ', $frBits));
}
if ($windowStats['noop_reasons'] !== []) {
    arsort($windowStats['noop_reasons']);
    $nrBits = [];
    foreach (array_slice($windowStats['noop_reasons'], 0, 4, true) as $k => $v) {
        $nrBits[] = "{$k}×{$v}";
    }
    $lines[] = $h('noop reasons: ' . implode(', ', $nrBits));
}
$lines[] = '';
$soldC0 = (int)$sinceC0Stats['thin_sold'];
$gateReady = ($soldC0 >= $targetSold && $status === 'OK' && $ledgerC0['double_sp'] === 0);

$lines[] = '<b>З старту C0</b> (' . $h($sinceC0Sql) . ')';
$lines[] = $h(sprintf(
    'thin sold=%d noop=%d fallback=%d · ledger SELL_SUB=%d · double=%d',
    $soldC0,
    $sinceC0Stats['thin_noop'],
    $sinceC0Stats['fallback'],
    $ledgerC0['sells'],
    $ledgerC0['double_sp']
));
$gap = max(0, (int)$ledgerC0['sells'] - $soldC0);
if ($gap > 0) {
    $lines[] = $h(sprintf(
        '⚠ gap: %d SELL_SUB у ledger без thin_sold (ймовірно RECONCILE/full) — gate рахує лише thin',
        $gap
    ));
}
$lines[] = $h(sprintf(
    'Прогрес gate: thin_sold %d/%d%s',
    $soldC0,
    $targetSold,
    $gateReady ? ' → READY' : ''
));
$lines[] = '';
$lines[] = $h(sprintf(
    'Workers: daemon=%d exec=%d · running=%s max_lag=%ss · queued=%d',
    $daemonN,
    $execN,
    (string)($tick['n'] ?? 0),
    (string)($tick['mx'] ?? 0),
    $queued
));
$lines[] = '';
$lines[] = '<b>Вердикт: ' . $h($status) . '</b>';
foreach ($issues as $i) {
    $lines[] = '❌ ' . $h($i);
}
foreach ($warnings as $w) {
    $lines[] = '⚠️ ' . $h($w);
}
if ($gateReady) {
    $lines[] = $h("🎯 Gate C4 READY: {$soldC0}/{$targetSold} thin_sold — можна GO на наступний крок.");
} elseif ($status === 'OK' && $thinAttempts === 0 && $ledgerH['sells'] === 0) {
    $lines[] = $h('Подій thin SUB_SELL за годину ще не було — очікуємо до TP (ціль ' . $targetSold . ' sold).');
}

$text = implode("\n", $lines);
echo date('c') . " status={$status} thin_attempts={$thinAttempts} fallback_rate={$fallbackRate} ledger_h={$ledgerH['sells']}\n";
echo strip_tags(str_replace(['<b>', '</b>'], ['', ''], $text)) . "\n";

if ($dryRun || !$notify) {
    echo date('c') . ' skip_tg dry=' . ($dryRun ? '1' : '0') . " notify=" . ($notify ? '1' : '0') . "\n";
    exit($status === 'CRIT' ? 2 : 0);
}

$silent = ($status === 'OK' && !$gateReady);
[$tgOk, $tgMsg] = tg_notify($text, ['silent' => $silent, 'skip_journal' => true]);
echo date('c') . ' tg_notify ok=' . ($tgOk ? '1' : '0') . ' ' . $tgMsg . "\n";

exit(($tgOk && $status !== 'CRIT') ? 0 : 1);
