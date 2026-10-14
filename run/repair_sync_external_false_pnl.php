<?php
declare(strict_types=1);
/**
 * Repair false MAIN SELL PnL / platform fee caused by ADOPT_EXT / SYNC bag pollution.
 *
 * Default: dry-run. Live: --apply
 *
 *   php run/repair_sync_external_false_pnl.php
 *   php run/repair_sync_external_false_pnl.php --apply
 *   php run/repair_sync_external_false_pnl.php --apply --ledger-id=2469
 */

$root = dirname(__DIR__);
require $root . '/www/vendor/autoload.php';
require_once $root . '/www/api/_billing_wallet.php';
require_once $root . '/engine/engine_helpers.php';

Dotenv\Dotenv::createImmutable($root)->safeLoad();

$apply = in_array('--apply', $argv ?? [], true);
$onlyLedger = 0;
foreach ($argv ?? [] as $a) {
    if (str_starts_with($a, '--ledger-id=')) {
        $onlyLedger = (int)substr($a, strlen('--ledger-id='));
    }
}

$db = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
if ($db->connect_errno) {
    fwrite(STDERR, $db->connect_error . PHP_EOL);
    exit(1);
}
$db->set_charset('utf8mb4');

echo $apply ? "LIVE\n" : "DRY-RUN\n";

/**
 * True cycle cost for a SELL_MAIN: walk BUY0/BUY_L backward from the sell until qty covered.
 * (Forward-from-last-MAIN can pull ancient inventory and corrupt basis.)
 */
function repair_true_cycle_cost(mysqli $db, int $sid, int $sellLedgerId, float $sellQty): array
{
    $buys = [];
    $q = $db->query(
        "SELECT id, qty, quote_usdt, price FROM trade_ledger
         WHERE strategy_id={$sid} AND id < {$sellLedgerId}
           AND side='BUY' AND event_type IN ('BUY0','BUY_L','BUY_LADDER')
         ORDER BY id DESC
         LIMIT 40"
    );
    $cost = 0.0;
    $left = $sellQty;
    while ($q && ($b = $q->fetch_assoc()) && $left > 1e-12) {
        $bq = (float)$b['qty'];
        $quote = (float)$b['quote_usdt'];
        if ($quote <= 0 && $bq > 0 && (float)$b['price'] > 0) {
            $quote = $bq * (float)$b['price'];
        }
        if ($bq <= 1e-12 || $quote <= 0) {
            continue;
        }
        $take = min($left, $bq);
        $cost += $quote * ($take / $bq);
        $left -= $take;
        $buys[] = (int)$b['id'];
    }
    $basis = $sellQty > 1e-12 ? ($cost / $sellQty) : 0.0;
    return ['cost' => $cost, 'basis' => $basis, 'buy_ids' => $buys, 'uncovered_qty' => max(0.0, $left)];
}

$sql = "
  SELECT tl.id, tl.strategy_id, tl.symbol, tl.created_at, tl.qty, tl.price, tl.quote_usdt,
         tl.basis_price, tl.pnl_usdt, tl.pnl_pct, s.owner_id, s.user_id
  FROM trade_ledger tl
  JOIN strategies s ON s.id = tl.strategy_id
  WHERE tl.event_type='SELL_MAIN'
    AND tl.pnl_usdt IS NOT NULL
    AND tl.created_at >= '2026-09-01'
";
if ($onlyLedger > 0) {
    $sql .= " AND tl.id=" . $onlyLedger;
}
$sql .= " ORDER BY tl.id ASC";

$candidates = $db->query($sql);
$fixed = 0;
$skipped = 0;

while ($candidates && ($row = $candidates->fetch_assoc())) {
    $lid = (int)$row['id'];
    $sid = (int)$row['strategy_id'];
    $uid = (int)($row['owner_id'] ?? 0);
    if ($uid <= 0) {
        $uid = (int)($row['user_id'] ?? 0);
    }
    $sellQty = (float)$row['qty'];
    $quote = (float)$row['quote_usdt'];
    $recordedPnl = (float)$row['pnl_usdt'];
    $recordedBasis = (float)($row['basis_price'] ?? 0);

    $true = repair_true_cycle_cost($db, $sid, $lid, $sellQty);
    if ($true['uncovered_qty'] > $sellQty * 0.05) {
        echo "SKIP {$lid} sid={$sid} uncovered={$true['uncovered_qty']}\n";
        $skipped++;
        continue;
    }
    $truePnl = $quote - $true['cost'];
    // Also subtract sell fee if in ledger fee column in USDT — optional; quote is usually net of? Binance cummulativeQuoteQty is before commission in USDT for sells.
    // Fee was 0.0417 USDT on the ZEC sell — deduct for honesty.
    $feeRow = $db->query("SELECT fee, fee_asset FROM trade_ledger WHERE id={$lid} LIMIT 1")->fetch_assoc();
    $fee = (float)($feeRow['fee'] ?? 0);
    $feeAst = (string)($feeRow['fee_asset'] ?? '');
    if ($fee > 0 && (strtoupper($feeAst) === 'USDT' || $feeAst === '')) {
        // If fee_asset empty but fee ~1% of quote, treat as USDT
        if ($feeAst === '' && $fee > $quote * 0.0005 && $fee < $quote * 0.02) {
            $truePnl -= $fee;
        } elseif (strtoupper($feeAst) === 'USDT') {
            $truePnl -= $fee;
        }
    }

    $delta = $recordedPnl - $truePnl;
    // Only repair inflated profit (never rewrite upward).
    if ($delta < 0.5) {
        $skipped++;
        continue;
    }
    $suspicious = ($recordedPnl > 0.5 && $delta > 0.5);
    $basisWeird = ($recordedBasis > 0 && $true['basis'] > 0 && $recordedBasis < $true['basis'] * 0.85);

    // Always include explicit ledger if --ledger-id
    if ($onlyLedger <= 0 && !$suspicious && !$basisWeird) {
        continue;
    }

    $syncNear = $db->query(
        "SELECT id FROM engine_journal
         WHERE strategy_id={$sid} AND event_type='SYNC_EXTERNAL_BUY'
           AND created_at BETWEEN DATE_SUB('{$row['created_at']}', INTERVAL 6 HOUR) AND '{$row['created_at']}'
         LIMIT 1"
    );
    $hadSync = $syncNear && $syncNear->fetch_assoc();
    $buyExt = $db->query(
        "SELECT id FROM trade_ledger WHERE strategy_id={$sid} AND event_type='BUY_EXT'
           AND created_at BETWEEN DATE_SUB('{$row['created_at']}', INTERVAL 6 HOUR) AND '{$row['created_at']}'
         LIMIT 1"
    );
    $hadExt = $buyExt && $buyExt->fetch_assoc();

    if ($onlyLedger <= 0 && !$hadSync && !$hadExt && !$basisWeird) {
        continue;
    }

    echo sprintf(
        "FIX ledger=%d sid=%d %s recorded_pnl=%.6f true_pnl=%.6f delta=%.6f basis_rec=%.4f basis_true=%.4f sync=%s\n",
        $lid,
        $sid,
        $row['symbol'],
        $recordedPnl,
        $truePnl,
        $delta,
        $recordedBasis,
        $true['basis'],
        $hadSync ? 'Y' : 'n'
    );

    if (!$apply) {
        $fixed++;
        continue;
    }

    $truePnlSql = number_format($truePnl, 12, '.', '');
    $basisSql = number_format($true['basis'], 12, '.', '');
    $pct = $true['basis'] > 0 ? (($quote / $sellQty) / $true['basis'] - 1.0) * 100.0 : 0.0;
    $pctSql = number_format($pct, 8, '.', '');
    $db->query(
        "UPDATE trade_ledger SET pnl_usdt={$truePnlSql}, basis_price={$basisSql}, pnl_pct={$pctSql}
         WHERE id={$lid} LIMIT 1"
    );

    // Refund false fee if we charged on inflated positive pnl and true pnl is lower
    $feeQ = $db->query(
        "SELECT id, amount_usdt FROM billing_ledger
         WHERE trade_ledger_id={$lid} AND entry_type='fee' LIMIT 1"
    );
    $feeEntry = $feeQ ? $feeQ->fetch_assoc() : null;
    if ($feeEntry && $uid > 0) {
        $charged = abs((float)$feeEntry['amount_usdt']);
        $correctFee = $truePnl > 1e-12 ? round($truePnl * 0.10, 8) : 0.0;
        $refund = round($charged - $correctFee, 8);
        if ($refund > 1e-8) {
            $ref = 'fee_repair_sync_ext_tl' . $lid;
            $dup = $db->query(
                "SELECT id FROM billing_ledger WHERE user_id={$uid} AND payment_ref='" .
                $db->real_escape_string($ref) . "' LIMIT 1"
            );
            if (!$dup || !$dup->fetch_assoc()) {
                $cred = billing_wallet_credit(
                    $db,
                    $uid,
                    'admin_credit',
                    $refund,
                    null,
                    null,
                    $ref,
                    null,
                    null,
                    [
                        'reason' => 'repair_false_pnl_sync_external',
                        'trade_ledger_id' => $lid,
                        'fee_was' => $charged,
                        'fee_should' => $correctFee,
                        'pnl_was' => $recordedPnl,
                        'pnl_true' => $truePnl,
                    ]
                );
                echo "  refund fee +{$refund} USDT ok=" . (!empty($cred['ok']) ? '1' : '0') . "\n";
            } else {
                echo "  refund already done\n";
            }
        }
    }

    // Annotate journal CORE_SELL if present nearby
    $db->query(
        "INSERT INTO engine_journal (created_at, strategy_id, symbol, event_type, level_idx, side, message)
         VALUES (NOW(), {$sid}, '" . $db->real_escape_string((string)$row['symbol']) . "',
         'BILLING', NULL, NULL,
         '" . $db->real_escape_string(sprintf(
             'REPAIR: SELL_MAIN ledger #%d PnL %.4f → %.4f USDT (sync/external bag). Комісію скориговано.',
             $lid,
             $recordedPnl,
             $truePnl
         )) . "')"
    );

    $fixed++;
}

echo "done fixed={$fixed} skipped={$skipped}\n";
