<?php
declare(strict_types=1);
/**
 * Remove false-positive SYNC_EXTERNAL_BUY rows from engine_journal.
 *
 * Verifies each affected strategy against Binance myTrades vs trade_ledger.
 * Deletes only when there are no untracked BUY orderIds (same rule as engine fix).
 *
 * Usage:
 *   php run/cleanup_false_sync_external_buy.php              # dry-run
 *   php run/cleanup_false_sync_external_buy.php --apply      # delete verified rows
 *   php run/cleanup_false_sync_external_buy.php --apply --all  # delete all SYNC rows (skip API verify)
 *   php run/cleanup_false_sync_external_buy.php --apply --force-sids=8  # also delete listed sids
 */

require __DIR__ . '/../www/vendor/autoload.php';
require __DIR__ . '/../www/api/_user_keys.php';
require __DIR__ . '/../www/api/_binance_utils.php';
require __DIR__ . '/../www/api/_filters_utils.php';
require __DIR__ . '/../engine/engine_helpers.php';

Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

$apply = in_array('--apply', $argv, true);
$forceAll = in_array('--all', $argv, true);
$forceSids = [];
foreach ($argv as $arg) {
    if (strpos($arg, '--force-sids=') === 0) {
        foreach (explode(',', substr($arg, 13)) as $part) {
            $part = (int) trim($part);
            if ($part > 0) {
                $forceSids[$part] = true;
            }
        }
    }
}

$m = new mysqli(
    (string) ($_ENV['DB_HOST'] ?? ''),
    (string) ($_ENV['DB_USER'] ?? ''),
    (string) ($_ENV['DB_PASS'] ?? ''),
    (string) ($_ENV['DB_NAME'] ?? '')
);
$m->set_charset('utf8mb4');

function base_asset_from_symbol(string $symbol): string
{
    $baseAsset = $symbol;
    foreach (['USDT', 'BUSD', 'USDC', 'BTC', 'ETH'] as $q) {
        $qlen = strlen($q);
        if ($qlen > 0 && strlen($baseAsset) > $qlen && strcasecmp(substr($baseAsset, -$qlen), $q) === 0) {
            return substr($baseAsset, 0, -$qlen);
        }
    }
    return $baseAsset;
}

function fetch_my_trades_since(string $symbol, int $startTs, string $apiKey, string $secret): array
{
    $all = [];
    $fromId = null;
    for ($page = 0; $page < 20; $page++) {
        $params = [
            'symbol' => $symbol,
            'limit' => 1000,
            'timestamp' => bn_server_time(),
            'recvWindow' => 5000,
        ];
        if ($fromId !== null) {
            $params['fromId'] = $fromId;
        }
        $q = bn_sign_query($params, $secret);
        [$h, $r, $e] = http_json(bn_base() . '/api/v3/myTrades?' . $q, ['X-MBX-APIKEY: ' . $apiKey], 'GET');
        if ($e || $h >= 400) {
            break;
        }
        $batch = json_decode((string) $r, true);
        if (!is_array($batch) || $batch === []) {
            break;
        }
        foreach ($batch as $t) {
            $tm = (int) ($t['time'] ?? 0);
            if ($startTs <= 0 || $tm >= $startTs) {
                $all[] = $t;
            }
        }
        if (count($batch) < 1000) {
            break;
        }
        $fromId = (int) ($batch[count($batch) - 1]['id'] ?? 0) + 1;
    }
    return $all;
}

$rows = [];
$res = $m->query(
    "SELECT strategy_id, COUNT(*) AS c FROM engine_journal " .
    "WHERE event_type='SYNC_EXTERNAL_BUY' GROUP BY strategy_id ORDER BY strategy_id ASC"
);
while ($res && ($row = $res->fetch_assoc())) {
    $rows[] = $row;
}

$total = 0;
foreach ($rows as $row) {
    $total += (int) ($row['c'] ?? 0);
}

echo ($apply ? 'APPLY' : 'DRY-RUN') . ": strategies=" . count($rows) . " rows={$total}\n";

$deleteSids = [];
$skipSids = [];

foreach ($rows as $row) {
    $sid = (int) ($row['strategy_id'] ?? 0);
    $count = (int) ($row['c'] ?? 0);
    if ($sid <= 0 || $count <= 0) {
        continue;
    }

    if ($forceAll) {
        $deleteSids[$sid] = $count;
        echo "sid={$sid} rows={$count} action=delete_all_flag\n";
        continue;
    }

    $st = $m->query("SELECT user_id, symbol, start_ts FROM strategies WHERE id={$sid} LIMIT 1");
    $stRow = $st ? $st->fetch_assoc() : null;
    if (!$stRow) {
        $skipSids[$sid] = 'strategy_missing';
        echo "sid={$sid} rows={$count} action=skip reason=strategy_missing\n";
        continue;
    }

    $uid = (int) ($stRow['user_id'] ?? 0);
    $symbol = (string) ($stRow['symbol'] ?? '');
    $startTs = (int) ($stRow['start_ts'] ?? 0);
    [$apiKey, $secret] = user_keys($m, $uid);

    if (!$apiKey || !$secret || $symbol === '') {
        $skipSids[$sid] = 'no_api_keys';
        echo "sid={$sid} rows={$count} action=skip reason=no_api_keys\n";
        continue;
    }

    $trades = fetch_my_trades_since($symbol, $startTs, $apiKey, $secret);
    $baseAsset = base_asset_from_symbol($symbol);
    $untracked = sync_untracked_buy_qty_from_trades($m, $sid, $trades, $baseAsset);

    if ($untracked > 1e-6 && !isset($forceSids[$sid])) {
        $skipSids[$sid] = 'untracked_buys_' . round($untracked, 8);
        echo "sid={$sid} symbol={$symbol} rows={$count} action=skip reason=untracked_qty={$untracked}\n";
        continue;
    }

    if ($untracked > 1e-6 && isset($forceSids[$sid])) {
        echo "sid={$sid} symbol={$symbol} rows={$count} action=delete force=1 untracked_qty={$untracked}\n";
    }

    $deleteSids[$sid] = $count;
    echo "sid={$sid} symbol={$symbol} rows={$count} action=delete verified=ok untracked=0\n";
}

$deleteTotal = array_sum($deleteSids);
echo "\nSummary: delete_rows={$deleteTotal} strategies=" . count($deleteSids) .
    " skipped_strategies=" . count($skipSids) . "\n";

if (!$apply) {
    echo "Dry-run only. Re-run with --apply to delete verified rows.\n";
    exit(0);
}

if ($deleteTotal <= 0) {
    echo "Nothing to delete.\n";
    exit(0);
}

$m->begin_transaction();
try {
    foreach ($deleteSids as $sid => $expected) {
        $sid = (int) $sid;
        $m->query("DELETE FROM engine_journal WHERE strategy_id={$sid} AND event_type='SYNC_EXTERNAL_BUY'");
        $aff = (int) $m->affected_rows;
        echo "deleted sid={$sid} rows={$aff} (expected={$expected})\n";
    }
    $m->commit();
} catch (Throwable $e) {
    $m->rollback();
    fwrite(STDERR, 'rollback: ' . $e->getMessage() . "\n");
    exit(1);
}

$left = (int) ($m->query("SELECT COUNT(*) c FROM engine_journal WHERE event_type='SYNC_EXTERNAL_BUY'")->fetch_assoc()['c'] ?? 0);
echo "Remaining SYNC_EXTERNAL_BUY rows: {$left}\n";
echo "Done.\n";
