<?php
declare(strict_types=1);
/**
 * Repair SELL_MAIN PnL/basis from L1–L4 lots closed at sell time (lot SoT).
 * Refunds excess platform fee (10% of inflated positive PnL).
 *
 *   php run/repair_main_pnl_from_l14_lots.php
 *   php run/repair_main_pnl_from_l14_lots.php --apply
 *   php run/repair_main_pnl_from_l14_lots.php --apply --since=2026-08-01
 *   php run/repair_main_pnl_from_l14_lots.php --apply --ledger-id=2488
 */
$root = dirname(__DIR__);
require $root . '/www/vendor/autoload.php';
require_once $root . '/www/api/_billing_wallet.php';

Dotenv\Dotenv::createImmutable($root)->safeLoad();

$apply = in_array('--apply', $argv ?? [], true);
$onlyLedger = 0;
$since = '2026-08-01';
$minDelta = 0.50;
foreach ($argv ?? [] as $a) {
    if (str_starts_with($a, '--ledger-id=')) {
        $onlyLedger = (int)substr($a, strlen('--ledger-id='));
    }
    if (str_starts_with($a, '--since=')) {
        $since = substr($a, strlen('--since='));
    }
    if (str_starts_with($a, '--min-delta=')) {
        $minDelta = (float)substr($a, strlen('--min-delta='));
    }
}

$db = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
if ($db->connect_errno) {
    fwrite(STDERR, $db->connect_error . PHP_EOL);
    exit(1);
}
$db->set_charset('utf8mb4');

echo ($apply ? "LIVE APPLY" : "DRY-RUN") . " since={$since} minDelta={$minDelta}\n";

/**
 * L1–L4 closed near MAIN sell — true core cost.
 * @return array{ok:bool,qty:float,cost:float,basis:float,reason?:string}
 */
function repair_l14_at_sell(mysqli $db, int $sid, string $createdAt, float $sellQty): array
{
    $esc = $db->real_escape_string($createdAt);
    $q = $db->query(
        "SELECT sp.level_idx, sp.qty, sp.buy_price, sp.id
         FROM sub_positions sp
         JOIN positions p ON p.id = sp.position_id
         WHERE p.strategy_id = {$sid}
           AND sp.closed = 1
           AND sp.level_idx BETWEEN 1 AND 4
           AND sp.qty > 1e-12 AND sp.buy_price > 0
           AND sp.updated_at BETWEEN DATE_SUB('{$esc}', INTERVAL 20 SECOND)
                                AND DATE_ADD('{$esc}', INTERVAL 20 SECOND)
         ORDER BY sp.level_idx, sp.id"
    );
    $byLvl = [];
    while ($q && ($r = $q->fetch_assoc())) {
        $byLvl[(int)$r['level_idx']] = $r;
    }
    if (count($byLvl) < 1) {
        return ['ok' => false, 'qty' => 0.0, 'cost' => 0.0, 'basis' => 0.0, 'reason' => 'no_l14'];
    }
    $qty = 0.0;
    $cost = 0.0;
    foreach ($byLvl as $r) {
        $qq = (float)$r['qty'];
        $bp = (float)$r['buy_price'];
        $qty += $qq;
        $cost += $qq * $bp;
    }
    if ($qty <= 1e-12) {
        return ['ok' => false, 'qty' => 0.0, 'cost' => 0.0, 'basis' => 0.0, 'reason' => 'zero_qty'];
    }
    $rel = abs($qty - $sellQty) / max($sellQty, 1e-8);
    if ($rel > 0.05) {
        return [
            'ok' => false,
            'qty' => $qty,
            'cost' => $cost,
            'basis' => $cost / $qty,
            'reason' => sprintf('qty_mismatch sell=%.6f l14=%.6f rel=%.3f', $sellQty, $qty, $rel),
        ];
    }
    return ['ok' => true, 'qty' => $qty, 'cost' => $cost, 'basis' => $cost / $qty];
}

$escSince = $db->real_escape_string($since);
$sql = "
  SELECT tl.id, tl.strategy_id, tl.symbol, tl.created_at, tl.qty, tl.price, tl.quote_usdt,
         tl.basis_price, tl.pnl_usdt, tl.pnl_pct, s.user_id, u.nickname, u.email
  FROM trade_ledger tl
  JOIN strategies s ON s.id = tl.strategy_id
  JOIN users u ON u.id = s.user_id
  WHERE tl.event_type = 'SELL_MAIN'
    AND tl.pnl_usdt IS NOT NULL
    AND tl.basis_price IS NOT NULL
    AND tl.qty > 1e-8
    AND tl.created_at >= '{$escSince}'
";
if ($onlyLedger > 0) {
    $sql .= ' AND tl.id=' . $onlyLedger;
}
$sql .= ' ORDER BY tl.id ASC';

$q = $db->query($sql);
$fixed = 0;
$skipped = 0;
$refundedTotal = 0.0;
$candidates = 0;

while ($q && ($row = $q->fetch_assoc())) {
    $lid = (int)$row['id'];
    $sid = (int)$row['strategy_id'];
    $uid = (int)$row['user_id'];
    $sym = (string)$row['symbol'];
    $nick = (string)($row['nickname'] ?: $row['email'] ?: ('uid' . $uid));
    $sellQty = (float)$row['qty'];
    $quote = (float)$row['quote_usdt'];
    if ($quote <= 0) {
        $quote = $sellQty * (float)$row['price'];
    }
    $recBasis = (float)$row['basis_price'];
    $recPnl = (float)$row['pnl_usdt'];

    // Already repaired?
    $dup = $db->query(
        "SELECT id FROM billing_ledger
         WHERE trade_ledger_id={$lid}
           AND entry_type='admin_credit'
           AND (payment_ref LIKE 'l14_main_repair_%' OR payment_ref LIKE 'etc_main_basis_repair_%'
                OR payment_ref LIKE 'fee_repair_sync_ext_tl%')
         LIMIT 1"
    );
    $alreadyCredited = $dup && $dup->fetch_assoc();

    $l14 = repair_l14_at_sell($db, $sid, (string)$row['created_at'], $sellQty);
    if (!$l14['ok']) {
        echo "SKIP #{$lid} {$sym} sid={$sid} {$nick}: {$l14['reason']}\n";
        $skipped++;
        continue;
    }

    $trueBasis = (float)$l14['basis'];
    $truePnl = round($quote - $trueBasis * $sellQty, 8);
    $inflate = $recPnl - $truePnl;
    if ($inflate < $minDelta) {
        $skipped++;
        continue;
    }

    $candidates++;
    $truePct = ($trueBasis * $sellQty) > 1e-12
        ? round(($truePnl / ($trueBasis * $sellQty)) * 100.0, 6)
        : 0.0;

    $feeRow = $db->query(
        "SELECT id, amount_usdt FROM billing_ledger
         WHERE trade_ledger_id={$lid} AND entry_type='fee' LIMIT 1"
    )->fetch_assoc();
    $charged = $feeRow ? abs((float)$feeRow['amount_usdt']) : 0.0;
    $correctFee = $truePnl > 1e-12 ? round($truePnl * 0.10, 8) : 0.0;
    $refund = round($charged - $correctFee, 8);
    if ($refund < 0) {
        $refund = 0.0; // не донараховуємо ретро — лише повертаємо зайве
    }
    if ($alreadyCredited) {
        $refund = 0.0;
    }

    echo sprintf(
        "FIX #%d %s uid=%d %s sid=%d @ %s\n  recorded basis=%.8f pnl=%+.4f\n  L1–4     basis=%.8f pnl=%+.4f\n  INFLATED %+.4f | fee_charged=%.4f → should=%.4f refund=%.4f%s\n",
        $lid,
        $sym,
        $uid,
        $nick,
        $sid,
        $row['created_at'],
        $recBasis,
        $recPnl,
        $trueBasis,
        $truePnl,
        $inflate,
        $charged,
        $correctFee,
        $refund,
        $alreadyCredited ? ' (fee already refunded)' : ''
    );

    if (!$apply) {
        $fixed++;
        continue;
    }

    // Update ledger if still inflated
    if (abs($recPnl - $truePnl) > 1e-6 || abs($recBasis - $trueBasis) > 1e-8) {
        $db->query(
            'UPDATE trade_ledger SET basis_price=' . number_format($trueBasis, 12, '.', '') .
            ', pnl_usdt=' . number_format($truePnl, 12, '.', '') .
            ', pnl_pct=' . number_format($truePct, 6, '.', '') .
            ' WHERE id=' . $lid . ' LIMIT 1'
        );
    }

    if ($refund > 1e-8 && $uid > 0) {
        $ref = 'l14_main_repair_' . $lid;
        $cred = billing_wallet_credit(
            $db,
            $uid,
            'admin_credit',
            $refund,
            null,
            null,
            $ref,
            $lid,
            null,
            [
                'reason' => 'l14_main_pnl_repair',
                'old_pnl' => $recPnl,
                'new_pnl' => $truePnl,
                'old_basis' => $recBasis,
                'new_basis' => $trueBasis,
                'fee_was' => $charged,
                'fee_should' => $correctFee,
                'fee_refunded' => $refund,
            ]
        );
        echo '  credit: ' . json_encode($cred) . "\n";
        if (!empty($cred['ok'])) {
            $refundedTotal += $refund;
        }
    }

    // Journal note
    $msg = sprintf(
        'REPAIR L14-SoT: SELL_MAIN #%d PnL %+.4f → %+.4f (basis %.8f → %.8f). Fee adjusted.',
        $lid,
        $recPnl,
        $truePnl,
        $recBasis,
        $trueBasis
    );
    $db->query(
        "INSERT INTO engine_journal (created_at, strategy_id, symbol, event_type, message)
         VALUES (NOW(), {$sid}, '" . $db->real_escape_string($sym) . "', 'BILLING',
         '" . $db->real_escape_string($msg) . "')"
    );

    // Patch CORE_SELL journal near sell if exact match
    $escAt = $db->real_escape_string((string)$row['created_at']);
    $jMsg = sprintf(
        "🔔 %s MAIN SELL CORE\nQty: %.8f\nPrice: %.8f\nBasis: %.8f\nPnL: %.2f (%.2f%%)\n[repaired L14-SoT: was %+0.2f]",
        $sym,
        $sellQty,
        (float)$row['price'],
        $trueBasis,
        $truePnl,
        $truePct,
        $recPnl
    );
    $db->query(
        "UPDATE engine_journal SET message='" . $db->real_escape_string($jMsg) . "'
         WHERE strategy_id={$sid} AND event_type='CORE_SELL'
           AND created_at BETWEEN DATE_SUB('{$escAt}', INTERVAL 5 SECOND) AND DATE_ADD('{$escAt}', INTERVAL 5 SECOND)
         LIMIT 1"
    );

    $fixed++;
}

echo "done candidates={$candidates} fixed={$fixed} skipped={$skipped} refunded_usdt=" .
    number_format($refundedTotal, 8, '.', '') . "\n";
