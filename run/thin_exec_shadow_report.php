<?php
declare(strict_types=1);

/**
 * B1 gate: shadow WOULD_SELL vs trade_ledger SELL_SUB.
 *
 *   php run/thin_exec_shadow_report.php [since="2026-09-20 21:36:00"]
 *
 * Читає logs/thin_exec.log + logs/archive/thin_exec.*.log.gz
 */

$root = dirname(__DIR__);
require $root . '/www/vendor/autoload.php';
Dotenv\Dotenv::createImmutable($root)->safeLoad();

$since = isset($argv[1]) && $argv[1] !== ''
    ? (string)$argv[1]
    : '2026-09-20 21:36:00';
$sinceTs = strtotime($since);
if ($sinceTs === false) {
    fwrite(STDERR, "bad since: {$since}\n");
    exit(1);
}

$m = new mysqli(
    (string)($_ENV['DB_HOST'] ?? ''),
    (string)($_ENV['DB_USER'] ?? ''),
    (string)($_ENV['DB_PASS'] ?? ''),
    (string)($_ENV['DB_NAME'] ?? '')
);
if ($m->connect_errno) {
    fwrite(STDERR, 'db: ' . $m->connect_error . PHP_EOL);
    exit(1);
}
$m->set_charset('utf8mb4');

/**
 * @return list<string> absolute paths (newest last)
 */
function thin_exec_log_sources(string $root): array
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
function thin_exec_lines(string $path): \Generator
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

/** @var array<int, array{first_ts:string,last_ts:string,sid:int,symbol:string,lvl:int,qty:float,n:int,jobs:list<string>}> */
$wouldBySp = [];
/** @var array<string,int> */
$skipReasons = [];
$wouldLines = 0;
$skipLines = 0;
$doneLines = 0;
$candLines = 0;
$firstLog = null;
$lastLog = null;

foreach (thin_exec_log_sources($root) as $src) {
    foreach (thin_exec_lines($src) as $line) {
        if (!preg_match('/^\[(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2})\]/', $line, $tm)) {
            continue;
        }
        $tsRaw = str_replace('T', ' ', $tm[1]);
        $tsRaw = preg_replace('/\+\d{2}:\d{2}$/', '', $tsRaw) ?? $tsRaw;
        $ts = strtotime($tsRaw);
        if ($ts === false || $ts < $sinceTs) {
            continue;
        }
        $tsStr = date('Y-m-d H:i:s', $ts);
        $firstLog = $firstLog ?? $tsStr;
        $lastLog = $tsStr;

        if (str_contains($line, 'candidate=1') && str_contains($line, 'SUB_SELL')) {
            $candLines++;
        }

        if (preg_match(
            '/shadow WOULD_SELL sid=(\d+) (\S+) L(\d+) spId=(\d+) qty=([0-9.]+).*?job=(\S+)/',
            $line,
            $mSell
        )) {
            $wouldLines++;
            $spId = (int)$mSell[4];
            if (!isset($wouldBySp[$spId])) {
                $wouldBySp[$spId] = [
                    'first_ts' => $tsStr,
                    'last_ts' => $tsStr,
                    'sid' => (int)$mSell[1],
                    'symbol' => (string)$mSell[2],
                    'lvl' => (int)$mSell[3],
                    'qty' => (float)$mSell[5],
                    'n' => 0,
                    'jobs' => [],
                ];
            }
            $wouldBySp[$spId]['last_ts'] = $tsStr;
            $wouldBySp[$spId]['n']++;
            $job = (string)$mSell[6];
            if (count($wouldBySp[$spId]['jobs']) < 5) {
                $wouldBySp[$spId]['jobs'][] = $job;
            }
            continue;
        }

        if (str_contains($line, 'shadow WOULD_SKIP')) {
            $skipLines++;
            $reason = 'unknown';
            if (preg_match('/reason=(\S+)/', $line, $rm)) {
                $reason = $rm[1];
            }
            $skipReasons[$reason] = ($skipReasons[$reason] ?? 0) + 1;
            continue;
        }

        if (str_contains($line, 'shadow DONE')) {
            $doneLines++;
        }
    }
}

// Ledger SELL_SUB in window
$sinceEsc = $m->real_escape_string($since);
$ledRows = [];
$q = $m->query(
    "SELECT id, strategy_id, symbol, level_idx, qty, price, created_at, meta
     FROM trade_ledger
     WHERE event_type='SELL_SUB' AND created_at >= '{$sinceEsc}'
     ORDER BY id ASC"
);
if ($q) {
    while ($row = $q->fetch_assoc()) {
        $meta = (string)($row['meta'] ?? '');
        $spId = 0;
        if (preg_match('/"sub_position_id"\s*:\s*(\d+)/', $meta, $mm)
            || preg_match('/sub_position_id[=:](\d+)/', $meta, $mm)
        ) {
            $spId = (int)$mm[1];
        }
        $ledRows[] = [
            'id' => (int)$row['id'],
            'sid' => (int)$row['strategy_id'],
            'symbol' => (string)$row['symbol'],
            'lvl' => (int)$row['level_idx'],
            'qty' => (float)$row['qty'],
            'price' => (float)$row['price'],
            'created_at' => (string)$row['created_at'],
            'spId' => $spId,
        ];
    }
}

/** @var array<int, list<array<string,mixed>>> */
$ledgerBySp = [];
foreach ($ledRows as $lr) {
    if ($lr['spId'] <= 0) {
        continue;
    }
    $ledgerBySp[$lr['spId']][] = $lr;
}

$matched = [];
$fp = []; // WOULD_SELL without ledger sell after first observe
foreach ($wouldBySp as $spId => $w) {
    $sells = $ledgerBySp[$spId] ?? [];
    $hit = null;
    $firstW = strtotime($w['first_ts']) ?: 0;
    foreach ($sells as $s) {
        $st = strtotime((string)$s['created_at']) ?: 0;
        // allow ledger slightly before shadow (race) up to 120s
        if ($st + 120 >= $firstW) {
            $hit = $s;
            break;
        }
    }
    if ($hit) {
        $matched[$spId] = ['would' => $w, 'ledger' => $hit];
    } else {
        $fp[$spId] = $w;
    }
}

// False negatives: ledger SELL_SUB with spId, no WOULD_SELL for that spId
// within [sell-30m, sell+2m]. Also flag missing spId in meta.
$fn = [];
$noMeta = [];
foreach ($ledRows as $lr) {
    $spId = (int)$lr['spId'];
    if ($spId <= 0) {
        $noMeta[] = $lr;
        continue;
    }
    if (isset($wouldBySp[$spId])) {
        // had at least one WOULD_SELL sometime — count as covered if sell after/near first
        $firstW = strtotime($wouldBySp[$spId]['first_ts']) ?: 0;
        $lastW = strtotime($wouldBySp[$spId]['last_ts']) ?: 0;
        $st = strtotime((string)$lr['created_at']) ?: 0;
        if ($st + 120 >= $firstW && $st - 1800 <= $lastW + 120) {
            continue; // covered
        }
        // sold but shadow window far away — soft FN
        $fn[$spId] = [
            'kind' => 'timing_gap',
            'ledger' => $lr,
            'would' => $wouldBySp[$spId],
        ];
        continue;
    }
    $fn[$spId] = [
        'kind' => 'no_would_sell',
        'ledger' => $lr,
        'would' => null,
    ];
}

// Orphan / dup quick (same window)
$dup = [];
$dq = $m->query(
    "SELECT strategy_id, level_idx, COUNT(*) c
     FROM trade_ledger
     WHERE event_type='SELL_SUB' AND created_at >= '{$sinceEsc}'
     GROUP BY strategy_id, level_idx, DATE_FORMAT(created_at, '%Y-%m-%d %H:%i')
     HAVING c > 1
     LIMIT 20"
);
if ($dq) {
    while ($r = $dq->fetch_assoc()) {
        $dup[] = $r;
    }
}

$uniqueWould = count($wouldBySp);
$matchN = count($matched);
$fpN = count($fp);
$fnHard = 0;
$fnSoft = 0;
foreach ($fn as $item) {
    if (($item['kind'] ?? '') === 'no_would_sell') {
        $fnHard++;
    } else {
        $fnSoft++;
    }
}
$matchRate = $uniqueWould > 0 ? round(100.0 * $matchN / $uniqueWould, 3) : 0.0;
$hours = max(0.01, ((strtotime((string)$lastLog) ?: time()) - $sinceTs) / 3600.0);

$gateTimeOk = $hours >= 72.0; // informational; owner may waive
$gateMatchOk = $matchRate >= 99.5;
$gateFnOk = $fnHard === 0;
$gateFpReview = $fpN; // each must be reviewed
$verdict = ($gateMatchOk && $gateFnOk && $fpN === 0) ? 'GO_TECH' : 'HOLD';
if ($fpN > 0 && $gateMatchOk && $gateFnOk) {
    $verdict = 'HOLD_FP_REVIEW';
}
if (!$gateMatchOk || !$gateFnOk) {
    $verdict = 'HOLD';
}

echo "=== Thin Exec B1 shadow gate report ===\n";
echo "since={$since}\n";
echo "log_span_first={$firstLog} last={$lastLog} hours≈" . round($hours, 2) . "\n";
echo "sources=" . implode(', ', array_map('basename', thin_exec_log_sources($root))) . "\n";
echo "\n--- Log counts ---\n";
echo "WOULD_SELL_lines={$wouldLines} unique_spId={$uniqueWould}\n";
echo "WOULD_SKIP_lines={$skipLines} DONE={$doneLines} SUB_SELL_candidate_lines≈{$candLines}\n";
echo "skip_reasons:\n";
arsort($skipReasons);
foreach ($skipReasons as $r => $c) {
    echo "  {$r}: {$c}\n";
}

echo "\n--- Ledger ---\n";
$ledN = count($ledRows);
$ledWithSp = count(array_filter($ledRows, static fn($r) => $r['spId'] > 0));
echo "SELL_SUB rows={$ledN} with_spId={$ledWithSp} no_meta=" . count($noMeta) . "\n";

echo "\n--- Match ---\n";
echo "matched_spId={$matchN} / {$uniqueWould} = {$matchRate}%\n";
echo "false_positive_spId (WOULD_SELL, no ledger after)={$fpN}\n";
echo "false_negative hard (ledger, never WOULD_SELL)={$fnHard}\n";
echo "false_negative soft (timing_gap)={$fnSoft}\n";
echo "dup_minute_buckets=" . count($dup) . "\n";

echo "\n--- Gate thresholds ---\n";
echo "time_72h=" . ($gateTimeOk ? 'OK' : 'WAIVED_BY_OWNER') . " (hours=" . round($hours, 2) . ")\n";
echo "match>=99.5%=" . ($gateMatchOk ? 'OK' : 'FAIL') . "\n";
echo "FN_hard=0=" . ($gateFnOk ? 'OK' : 'FAIL') . "\n";
echo "FP_to_review={$fpN}\n";
echo "VERDICT={$verdict}\n";

if ($fpN > 0) {
    echo "\n--- FP sample (max 25) ---\n";
    $i = 0;
    foreach ($fp as $spId => $w) {
        echo sprintf(
            "spId=%d sid=%d %s L%d qty=%s first=%s last=%s n=%d\n",
            $spId,
            $w['sid'],
            $w['symbol'],
            $w['lvl'],
            $w['qty'],
            $w['first_ts'],
            $w['last_ts'],
            $w['n']
        );
        if (++$i >= 25) {
            break;
        }
    }
}

if ($fnHard > 0 || $fnSoft > 0) {
    echo "\n--- FN sample (max 25) ---\n";
    $i = 0;
    foreach ($fn as $spId => $item) {
        $lr = $item['ledger'];
        echo sprintf(
            "kind=%s spId=%d sid=%d %s L%d ledger_at=%s led_id=%d\n",
            $item['kind'],
            $spId,
            $lr['sid'],
            $lr['symbol'],
            $lr['lvl'],
            $lr['created_at'],
            $lr['id']
        );
        if (++$i >= 25) {
            break;
        }
    }
}

if (count($noMeta) > 0) {
    echo "\n--- SELL_SUB without sub_position_id in meta (max 10) ---\n";
    foreach (array_slice($noMeta, 0, 10) as $lr) {
        echo "led_id={$lr['id']} sid={$lr['sid']} {$lr['symbol']} L{$lr['lvl']} at={$lr['created_at']}\n";
    }
}

echo "\nDone.\n";
