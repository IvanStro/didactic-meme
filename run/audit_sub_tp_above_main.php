<?php
/**
 * Audit: open L5+ sell targets above MAIN sell (UI avg×tp_whole AND engine core basis).
 * Usage (prod): /usr/local/php84/bin/php run/audit_sub_tp_above_main.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/www/vendor/autoload.php';
Dotenv\Dotenv::createImmutable($root)->safeLoad();

$db = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
$db->set_charset('utf8mb4');
if ($db->connect_errno) {
    fwrite(STDERR, "DB connect fail\n");
    exit(1);
}

$sql = "
SELECT
  u.id AS uid,
  u.nickname,
  s.id AS sid,
  s.symbol,
  s.status,
  COALESCE(s.tp_whole, 1.4) AS tp_whole,
  p.id AS pid,
  p.qty AS pos_qty,
  p.avg_price,
  p.base_price,
  p.next_ptr
FROM strategies s
JOIN users u ON u.id = s.user_id
JOIN positions p ON p.strategy_id = s.id
WHERE p.qty > 1e-12
ORDER BY u.nickname, s.symbol
";
$q = $db->query($sql);
if (!$q) {
    fwrite(STDERR, $db->error . PHP_EOL);
    exit(1);
}

$defectsUi = [];
$defectsCore = [];
$checked = 0;
$withSubs = 0;

while ($row = $q->fetch_assoc()) {
    $checked++;
    $pid = (int)$row['pid'];
    $avg = (float)$row['avg_price'];
    $tpw = (float)$row['tp_whole'];
    $qty = (float)$row['pos_qty'];
    $basePrice = (float)($row['base_price'] ?? 0);
    $nextPtr = (int)($row['next_ptr'] ?? 0);

    $mainUi = ($avg > 0 && $tpw > 0) ? $avg * (1 + $tpw / 100) : 0.0;

    $sq = $db->query("
      SELECT sp.id, sp.level_idx, sp.buy_price, sp.qty, sp.tp, sp.cb
      FROM sub_positions sp
      INNER JOIN (
        SELECT level_idx, MAX(id) AS max_id
        FROM sub_positions
        WHERE position_id = {$pid} AND closed = 0 AND level_idx >= 5
        GROUP BY level_idx
      ) u ON sp.id = u.max_id
      ORDER BY sp.level_idx
    ");
    if (!$sq) {
        continue;
    }
    $subs = [];
    while ($s = $sq->fetch_assoc()) {
        $subs[] = $s;
    }
    if (!$subs) {
        continue;
    }
    $withSubs++;

    $hiQty = 0.0;
    $hiCost = 0.0;
    foreach ($subs as $s) {
        $sqty = (float)$s['qty'];
        $bp = (float)$s['buy_price'];
        $hiQty += $sqty;
        $hiCost += $sqty * $bp;
    }
    $coreQty = $qty - $hiQty;
    $coreCost = ($qty * $avg) - $hiCost;
    $basis = ($coreQty > 1e-12 && $coreCost > 0) ? ($coreCost / $coreQty) : 0.0;
    if ($nextPtr <= 1 && $basePrice > 0) {
        $basis = $basePrice;
    }
    $mainCore = ($basis > 0 && $tpw > 0) ? $basis * (1 + $tpw / 100) : 0.0;

    $badUi = [];
    $badCore = [];
    foreach ($subs as $s) {
        $bp = (float)$s['buy_price'];
        $tp = (float)$s['tp'];
        $st = ($bp > 0 && $tp > 0) ? $bp * (1 + $tp / 100) : 0.0;
        $item = [
            'sp_id' => (int)$s['id'],
            'L' => (int)$s['level_idx'],
            'buy' => $bp,
            'qty' => (float)$s['qty'],
            'tp_pct' => $tp,
            'sell_t' => $st,
        ];
        if ($mainUi > 0 && $st > $mainUi + 1e-12) {
            $item['above_pct'] = round((($st / $mainUi) - 1) * 100, 4);
            $badUi[] = $item;
        }
        if ($mainCore > 0 && $st > $mainCore + 1e-12) {
            $itemCore = $item;
            $itemCore['above_pct'] = round((($st / $mainCore) - 1) * 100, 4);
            $badCore[] = $itemCore;
        }
    }

    $base = [
        'uid' => (int)$row['uid'],
        'nick' => (string)$row['nickname'],
        'sid' => (int)$row['sid'],
        'symbol' => (string)$row['symbol'],
        'status' => (string)$row['status'],
        'avg' => $avg,
        'tp_whole' => $tpw,
        'main_ui' => $mainUi,
        'core_basis' => $basis,
        'main_core' => $mainCore,
        'hi_qty' => $hiQty,
        'core_qty' => $coreQty,
        'next_ptr' => $nextPtr,
        'open_subs' => count($subs),
    ];

    if ($badUi) {
        $defectsUi[] = $base + [
            'bad_count' => count($badUi),
            'bad' => $badUi,
            'worst_above_pct' => max(array_column($badUi, 'above_pct')),
        ];
    }
    if ($badCore) {
        $defectsCore[] = $base + [
            'bad_count' => count($badCore),
            'bad' => $badCore,
            'worst_above_pct' => max(array_column($badCore, 'above_pct')),
        ];
    }
}

$out = [
    'ts' => date('c'),
    'checked_positions' => $checked,
    'with_open_L5plus' => $withSubs,
    'defects_vs_ui_main' => count($defectsUi),
    'defects_vs_engine_core' => count($defectsCore),
    'ui' => $defectsUi,
    'core' => $defectsCore,
];

$jsonPath = '/tmp/sub_above_main_audit.json';
file_put_contents($jsonPath, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo "checked={$checked} with_L5plus={$withSubs}\n";
echo "defects_vs_UI_main(avg*tp)=" . count($defectsUi) . "\n";
echo "defects_vs_engine_core=" . count($defectsCore) . "\n";
echo "==== VS UI MAIN ====\n";
foreach ($defectsUi as $d) {
    echo sprintf(
        "%s sid=%d %s %s avg=%.8f mainUI=%.8f mainCore=%.8f bad=%d worst=+%.3f%%\n",
        $d['nick'],
        $d['sid'],
        $d['symbol'],
        $d['status'],
        $d['avg'],
        $d['main_ui'],
        $d['main_core'],
        $d['bad_count'],
        $d['worst_above_pct']
    );
    foreach ($d['bad'] as $b) {
        echo sprintf(
            "  L%d buy=%.8f tp=%.2f%% sell=%.8f (+%.3f%%)\n",
            $b['L'],
            $b['buy'],
            $b['tp_pct'],
            $b['sell_t'],
            $b['above_pct']
        );
    }
}
echo "==== VS ENGINE CORE ====\n";
foreach ($defectsCore as $d) {
    echo sprintf(
        "%s sid=%d %s %s coreBasis=%.8f mainCore=%.8f bad=%d worst=+%.3f%%\n",
        $d['nick'],
        $d['sid'],
        $d['symbol'],
        $d['status'],
        $d['core_basis'],
        $d['main_core'],
        $d['bad_count'],
        $d['worst_above_pct']
    );
    foreach ($d['bad'] as $b) {
        echo sprintf(
            "  L%d buy=%.8f tp=%.2f%% sell=%.8f (+%.3f%%)\n",
            $b['L'],
            $b['buy'],
            $b['tp_pct'],
            $b['sell_t'],
            $b['above_pct']
        );
    }
}
echo "json={$jsonPath}\n";
