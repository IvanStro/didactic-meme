<?php
declare(strict_types=1);
/**
 * Вирівняти sub_positions.buy_price з фактичним BUY_L fill (trade_ledger).
 * Лікує наслідки UNFREEZE_BURST, де в buy_price писали уявний тригер.
 *
 *   php run/repair_sub_buy_price_from_ledger.php           # dry-run
 *   php run/repair_sub_buy_price_from_ledger.php --apply
 *   php run/repair_sub_buy_price_from_ledger.php --apply --sid=17
 */
$root = dirname(__DIR__);
require $root . '/www/vendor/autoload.php';
Dotenv\Dotenv::createImmutable($root)->safeLoad();

$apply = in_array('--apply', $argv ?? [], true);
$onlySid = 0;
foreach ($argv ?? [] as $a) {
    if (str_starts_with((string)$a, '--sid=')) {
        $onlySid = (int)substr((string)$a, 6);
    }
}

$db = new mysqli(
    (string)($_ENV['DB_HOST'] ?? ''),
    (string)($_ENV['DB_USER'] ?? ''),
    (string)($_ENV['DB_PASS'] ?? ''),
    (string)($_ENV['DB_NAME'] ?? '')
);
if ($db->connect_errno) {
    fwrite(STDERR, "db: {$db->connect_error}\n");
    exit(1);
}
$db->set_charset('utf8mb4');

$minPct = 0.5; // |stored-fill|/fill >= 0.5%
$sql = "
  SELECT sp.id AS sp_id, sp.level_idx, sp.buy_price AS bp_stored, sp.qty, sp.tp, sp.created_at,
         s.id AS sid, s.symbol, s.user_id, u.nickname
  FROM sub_positions sp
  JOIN positions p ON p.id = sp.position_id
  JOIN strategies s ON s.id = p.strategy_id
  JOIN users u ON u.id = s.user_id
  WHERE sp.closed = 0
    AND sp.level_idx >= 5
    AND sp.qty > 1e-12
    AND sp.buy_price > 0
";
if ($onlySid > 0) {
    $sql .= ' AND s.id = ' . (int)$onlySid;
}
$sql .= ' ORDER BY s.id, sp.level_idx';

$res = $db->query($sql);
$n = 0;
$fixed = 0;
while ($res && ($row = $res->fetch_assoc())) {
    $spId = (int)$row['sp_id'];
    $sid = (int)$row['sid'];
    $lvl = (int)$row['level_idx'];
    $stored = (float)$row['bp_stored'];
    $created = (string)$row['created_at'];
    $esc = $db->real_escape_string($created);

    $lq = $db->query(
        "SELECT price, qty, created_at FROM trade_ledger
         WHERE strategy_id = {$sid}
           AND event_type = 'BUY_L'
           AND level_idx = {$lvl}
           AND created_at BETWEEN DATE_SUB('{$esc}', INTERVAL 30 SECOND)
                              AND DATE_ADD('{$esc}', INTERVAL 30 SECOND)
         ORDER BY ABS(TIMESTAMPDIFF(SECOND, created_at, '{$esc}')) ASC
         LIMIT 1"
    );
    $fillRow = $lq ? $lq->fetch_assoc() : null;
    if (!$fillRow) {
        continue;
    }
    $fill = (float)$fillRow['price'];
    if ($fill <= 1e-12) {
        continue;
    }
    $pct = abs($stored - $fill) / $fill * 100.0;
    if ($pct < $minPct) {
        continue;
    }
    $n++;
    $tp = (float)$row['tp'];
    $oldTp = $stored * (1.0 + $tp / 100.0);
    $newTp = $fill * (1.0 + $tp / 100.0);
    echo sprintf(
        "%s sid=%d %s L%d sp#%d stored=%.8f fill=%.8f Δ%.2f%%  TP %.6f→%.6f%s\n",
        $apply ? 'FIX' : 'WOULD',
        $sid,
        (string)$row['symbol'],
        $lvl,
        $spId,
        $stored,
        $fill,
        $pct,
        $oldTp,
        $newTp,
        $apply ? '' : ' (dry)'
    );
    if ($apply) {
        $pSql = number_format($fill, 12, '.', '');
        $db->query("UPDATE sub_positions SET buy_price = {$pSql} WHERE id = {$spId} AND closed = 0 LIMIT 1");
        if ($db->affected_rows >= 0 && $db->errno === 0) {
            $fixed++;
        }
    }
}

echo ($apply ? "applied={$fixed}" : "would_fix={$n}") . " mismatches={$n}\n";
exit(0);
