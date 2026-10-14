<?php
declare(strict_types=1);
/**
 * Read-only перевірка однієї стратегії: positions, missed queue, sub_positions,
 * останні рядки trade_ledger / engine_journal, порівняння qty з Binance Spot (якщо є ключі користувача).
 *
 * Запуск на сервері з .env у корені проєкту:
 *   php run/reconcile_strategy_readonly.php <strategy_id>
 *   php run/reconcile_strategy_readonly.php --list
 *
 * Опції:
 *   STRATEGY_ID=123 php run/reconcile_strategy_readonly.php
 */

require __DIR__ . '/../www/vendor/autoload.php';
require_once __DIR__ . '/../www/api/_binance_utils.php';
require_once __DIR__ . '/../www/api/_user_keys.php';

Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

function out(string $s): void
{
    echo $s . "\n";
}

function base_asset_from_symbol(string $symbol): string
{
    $base = $symbol;
    foreach (['USDT', 'BUSD', 'USDC', 'BTC', 'ETH'] as $q) {
        $ql = strlen($q);
        if ($ql > 0 && strlen($base) > $ql && strcasecmp(substr($base, -$ql), $q) === 0) {
            return substr($base, 0, -$ql);
        }
    }
    return $base;
}

function bn_spot_asset_qty(string $apiKey, string $secret, string $asset): ?float
{
    $ts = bn_server_time();
    $q = bn_sign_query(['timestamp' => $ts, 'recvWindow' => 5000], $secret);
    [$h, $r, $e, $er] = http_json(bn_base() . '/api/v3/account?' . $q, ['X-MBX-APIKEY: ' . $apiKey], 'GET');
    if ($e || $h >= 400 || !is_string($r)) {
        return null;
    }
    $acc = json_decode($r, true);
    if (!is_array($acc)) {
        return null;
    }
    foreach (($acc['balances'] ?? []) as $b) {
        if (($b['asset'] ?? '') === $asset) {
            return (float)($b['free'] ?? 0) + (float)($b['locked'] ?? 0);
        }
    }
    return 0.0;
}

$listOnly = isset($argv[1]) && $argv[1] === '--list';

$sidArg = 0;
if (isset($argv[1]) && (int)$argv[1] > 0) {
    $sidArg = (int)$argv[1];
}
if ($sidArg <= 0) {
    $envSid = (int)(getenv('STRATEGY_ID') ?: ($_ENV['STRATEGY_ID'] ?? 0));
    if ($envSid > 0) {
        $sidArg = $envSid;
    }
}

$m = new mysqli($_ENV['DB_HOST'] ?? '', $_ENV['DB_USER'] ?? '', $_ENV['DB_PASS'] ?? '', $_ENV['DB_NAME'] ?? '');
if ($m->connect_errno) {
    fwrite(STDERR, "DB: " . $m->connect_error . "\n");
    exit(1);
}
$m->set_charset('utf8mb4');

if ($listOnly) {
    out('=== strategies (id, symbol, status, owner_id, user_id) ===');
    $lq = $m->query("SELECT id, symbol, status, owner_id, user_id, last_tick_at FROM strategies ORDER BY id DESC LIMIT 40");
    if ($lq) {
        while ($r = $lq->fetch_assoc()) {
            out(sprintf(
                '%d | %s | %s | owner=%s user=%s | tick=%s',
                (int)$r['id'],
                (string)$r['symbol'],
                (string)$r['status'],
                (string)$r['owner_id'],
                (string)$r['user_id'],
                (string)($r['last_tick_at'] ?? '')
            ));
        }
    }
    out('Usage: php run/reconcile_strategy_readonly.php <strategy_id>');
    exit(0);
}

if ($sidArg <= 0) {
    fwrite(STDERR, "Usage: php run/reconcile_strategy_readonly.php <strategy_id>\n   or: php run/reconcile_strategy_readonly.php --list\n");
    exit(1);
}

$sid = $sidArg;
$q = $m->query("
  SELECT id, user_id, owner_id, symbol, status, start_ts, last_tick_at, fba, tp_whole, cb_whole
  FROM strategies WHERE id=" . (int)$sid . " LIMIT 1
");
$st = $q ? $q->fetch_assoc() : null;
if (!$st) {
    fwrite(STDERR, "strategy_id=$sid not found\n");
    exit(1);
}

$uid = (int)($st['user_id'] ?? 0);
$symbol = (string)($st['symbol'] ?? '');
out('=== Strategy ===');
out('id: ' . (int)$st['id']);
out('symbol: ' . $symbol);
out('status: ' . (string)$st['status']);
out('user_id (keys): ' . $uid);
out('owner_id: ' . (int)($st['owner_id'] ?? 0));
out('start_ts: ' . (string)($st['start_ts'] ?? ''));
out('last_tick_at: ' . (string)($st['last_tick_at'] ?? ''));
out('fba / tp_whole / cb_whole: ' . ($st['fba'] ?? '') . ' / ' . ($st['tp_whole'] ?? '') . ' / ' . ($st['cb_whole'] ?? ''));

$pq = $m->query("SELECT * FROM positions WHERE strategy_id=" . (int)$sid . " AND user_id=" . (int)$uid . " ORDER BY id DESC LIMIT 1");
$pos = $pq ? $pq->fetch_assoc() : null;

out('');
out('=== positions (DB) ===');
if (!$pos) {
    out('(no row)');
} else {
    foreach ($pos as $k => $v) {
        if ($k === 'ladder_missed_json' && is_string($v) && strlen($v) > 200) {
            out($k . ': ' . substr($v, 0, 200) . '…');
        } else {
            out($k . ': ' . (string)$v);
        }
    }
}

$pid = $pos ? (int)$pos['id'] : 0;
if ($pid > 0) {
    out('');
    out('=== sub_positions (open) ===');
    $sq = $m->query("SELECT level_idx, qty, buy_price, tp, cb, closed FROM sub_positions WHERE position_id=$pid AND closed=0 ORDER BY level_idx ASC");
    if ($sq && $sq->num_rows > 0) {
        while ($r = $sq->fetch_assoc()) {
            out(sprintf(
                'L%d qty=%s buy=%s tp=%s cb=%s',
                (int)$r['level_idx'],
                (string)$r['qty'],
                (string)$r['buy_price'],
                (string)$r['tp'],
                (string)$r['cb']
            ));
        }
    } else {
        out('(none)');
    }
}

out('');
out('=== ladder_missed_json (parsed) ===');
$raw = $pos['ladder_missed_json'] ?? '';
if ($raw === '' || $raw === null) {
    out('(empty)');
} else {
    $jm = json_decode((string)$raw, true);
    if (!is_array($jm)) {
        out('invalid JSON');
    } else {
        out(json_encode($jm, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
}

out('');
out('=== trade_ledger (last 12, strategy) ===');
$tq = $m->query("SELECT id, created_at, event_type, level_idx, side, price, qty, quote_usdt, order_id FROM trade_ledger WHERE strategy_id=" . (int)$sid . " ORDER BY id DESC LIMIT 12");
if ($tq && $tq->num_rows > 0) {
    while ($r = $tq->fetch_assoc()) {
        out(sprintf(
            '%s | %s L%s %s | qty=%s price=%s quote=%s orderId=%s',
            (string)$r['created_at'],
            (string)$r['event_type'],
            $r['level_idx'] === null ? '-' : (string)$r['level_idx'],
            (string)$r['side'],
            (string)$r['qty'],
            (string)$r['price'],
            (string)$r['quote_usdt'],
            $r['order_id'] === null ? '-' : (string)$r['order_id']
        ));
    }
} else {
    out('(none)');
}

out('');
out('=== engine_journal (last 12, strategy) ===');
$jq = $m->query("SELECT id, created_at, event_type, level_idx, side, qty, price, LEFT(message,120) AS msg FROM engine_journal WHERE strategy_id=" . (int)$sid . " ORDER BY id DESC LIMIT 12");
if ($jq && $jq->num_rows > 0) {
    while ($r = $jq->fetch_assoc()) {
        out(sprintf(
            '%s | %s L%s | qty=%s price=%s | %s',
            (string)$r['created_at'],
            (string)$r['event_type'],
            $r['level_idx'] === null ? '-' : (string)$r['level_idx'],
            $r['qty'] === null ? '-' : (string)$r['qty'],
            $r['price'] === null ? '-' : (string)$r['price'],
            (string)$r['msg']
        ));
    }
} else {
    out('(none)');
}

out('');
out('=== job_queue (last 8, strategy) ===');
$jq2 = @$m->query("SELECT id, job_type, status, attempts, created_at, updated_at, LEFT(last_error,80) AS err FROM job_queue WHERE strategy_id=" . (int)$sid . " ORDER BY id DESC LIMIT 8");
if (!$jq2) {
    out('(skip: job_queue not available)');
} elseif ($jq2->num_rows > 0) {
    while ($r = $jq2->fetch_assoc()) {
        out(sprintf(
            '%s | %s | %s attempts=%s | %s | err=%s',
            (string)$r['created_at'],
            (string)$r['job_type'],
            (string)$r['status'],
            (string)$r['attempts'],
            (string)$r['updated_at'],
            (string)$r['err']
        ));
    }
} else {
    out('(none or table missing)');
}

out('');
out('=== Binance Spot vs positions.qty ===');
[$apiKey, $sec] = user_keys($m, $uid);
if ($apiKey === null || $sec === null || $apiKey === '' || $symbol === '') {
    out('Skip: no API keys for user or empty symbol.');
} else {
    $asset = base_asset_from_symbol($symbol);
    $spot = bn_spot_asset_qty($apiKey, $sec, $asset);
    $dbQty = $pos ? (float)($pos['qty'] ?? 0) : 0.0;
    if ($spot === null) {
        out('Binance account request failed.');
    } else {
        out('Asset: ' . $asset);
        out('Spot (free+locked): ' . number_format($spot, 8, '.', ''));
        out('positions.qty:     ' . number_format($dbQty, 8, '.', ''));
        $diff = abs($spot - $dbQty);
        $rel = max($spot, $dbQty, 1e-12);
        $pct = $rel > 0 ? ($diff / $rel) * 100.0 : 0.0;
        out('abs diff: ' . number_format($diff, 8, '.', '') . ' (~' . number_format($pct, 2, '.', '') . '%)');
        if ($diff > max(1e-6, $rel * 0.02)) {
            out('WARNING: DB and spot differ beyond ~2% — sync_from_trades / adoption may be needed.');
        } else {
            out('OK: roughly aligned.');
        }
    }
}

out('');
out('=== done (read-only) ===');
