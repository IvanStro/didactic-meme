<?php
declare(strict_types=1);
/**
 * Злиття orphan qty у верхній open sub + опційно перекласифікація зайвих BUY0 → BUY_L L4.
 *
 *   php run/repair_orphan_qty_to_ladder.php <strategy_id> [--relabel-journal]
 */

require __DIR__ . '/../www/vendor/autoload.php';
require_once __DIR__ . '/../engine/engine_helpers.php';

Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

$sid = (int) ($argv[1] ?? 0);
$relabel = in_array('--relabel-journal', $argv, true) || in_array('--relabel', $argv, true);

if ($sid <= 0) {
    fwrite(STDERR, "Usage: php run/repair_orphan_qty_to_ladder.php <strategy_id> [--relabel-journal]\n");
    exit(1);
}

$m = new mysqli(
    (string) ($_ENV['DB_HOST'] ?? ''),
    (string) ($_ENV['DB_USER'] ?? ''),
    (string) ($_ENV['DB_PASS'] ?? ''),
    (string) ($_ENV['DB_NAME'] ?? '')
);
$m->set_charset('utf8mb4');

$st = $m->query("SELECT user_id, symbol, tp_whole, cb_whole FROM strategies WHERE id=$sid LIMIT 1")->fetch_assoc();
if (!$st) {
    fwrite(STDERR, "strategy not found\n");
    exit(1);
}
$uid = (int) ($st['user_id'] ?? 0);
$symbol = (string) ($st['symbol'] ?? '');
$tpw = (float) ($st['tp_whole'] ?? 1.4);
$cbw = (float) ($st['cb_whole'] ?? 0.1);

$posBefore = position_pick_row($m, $sid, $uid);
if (!$posBefore) {
    fwrite(STDERR, "position not found\n");
    exit(1);
}
$pid = (int) ($posBefore['id'] ?? 0);
$openBefore = position_open_sub_qty_sum($m, $pid);
$totalBefore = (float) ($posBefore['qty'] ?? 0);
echo "Before: total_qty=$totalBefore open_sub_sum=$openBefore orphan=" . ($totalBefore - $openBefore) . "\n";

$ok = position_reconcile_orphan_core_to_ladder($m, $sid, $uid, $tpw, $cbw, 0.0);
echo $ok ? "Merged orphan into L" . position_max_open_level_idx($m, $pid) . "\n" : "Nothing to merge\n";

$posAfter = position_pick_row($m, $sid, $uid);
$openAfter = position_open_sub_qty_sum($m, $pid);
$totalAfter = (float) ($posAfter['qty'] ?? 0);
echo "After: total_qty=$totalAfter open_sub_sum=$openAfter orphan=" . ($totalAfter - $openAfter) . "\n";

if ($relabel) {
    $symEsc = $m->real_escape_string($symbol);
    $lastL4 = $m->query(
        "SELECT created_at FROM engine_journal
         WHERE strategy_id=$sid AND symbol='$symEsc' AND event_type='BUY_L' AND level_idx=4
         ORDER BY id DESC LIMIT 1"
    );
    $cutoff = '1970-01-01';
    if ($lastL4 && ($lr = $lastL4->fetch_assoc())) {
        $cutoff = (string) ($lr['created_at'] ?? $cutoff);
    }
    echo "Relabel BUY0 -> BUY_L L4 after last L4 at $cutoff\n";

    $j = $m->query(
        "SELECT id, created_at, qty, price FROM engine_journal
         WHERE strategy_id=$sid AND symbol='$symEsc' AND event_type='BUY0'
           AND created_at > '$cutoff'
         ORDER BY id ASC"
    );
    $n = 0;
    while ($j && ($row = $j->fetch_assoc())) {
        $jid = (int) $row['id'];
        $msg = sprintf(
            '✅ %s BUY L4 (докупка злиття orphan / колишній BUY0) — qty≈%.8f @ ~%.8f',
            $symbol,
            (float) $row['qty'],
            (float) $row['price']
        );
        $msgEsc = $m->real_escape_string($msg);
        $m->query(
            "UPDATE engine_journal SET event_type='BUY_L', level_idx=4, message='$msgEsc' WHERE id=$jid LIMIT 1"
        );
        $n++;
        echo "  journal id=$jid {$row['created_at']}\n";
    }

    $t = $m->query(
        "UPDATE trade_ledger SET event_type='BUY_L', level_idx=4
         WHERE strategy_id=$sid AND symbol='$symEsc' AND event_type='BUY0'
           AND created_at > '$cutoff'"
    );
    echo "trade_ledger relabeled rows: " . (int) $m->affected_rows . "\n";
    echo "engine_journal relabeled: $n\n";
}

echo "done\n";
