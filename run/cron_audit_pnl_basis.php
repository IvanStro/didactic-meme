<?php
declare(strict_types=1);
/**
 * Аудит фейкового MAIN basis / PnL по всіх юзерах і парах → Telegram адміну (TG_CHAT_ID).
 *
 * Ловить:
 *  1) LIVE: cycle-avg basis << L1–L4 lots (ризик фейкового MAIN TP / PnL)
 *  2) LIVE: positions.avg сильно нижче L1–L4 при відкритих L5+ (забруднений avg)
 *  3) RECENT SELL_MAIN: записаний basis/PnL vs L1–L4 закриті в момент продажу
 *
 * Cron (кожні 15–30 хв):
 *   /bin/bash /home/bot20/strim.in.ua/run/cron_audit_pnl_basis.sh
 *
 * Env:
 *   PNL_AUDIT_LOOKBACK_HOURS=48
 *   PNL_AUDIT_MIN_DELTA_USDT=0.50
 *   PNL_AUDIT_MIN_BASIS_PCT=2.0
 *   PNL_AUDIT_COOLDOWN_SEC=21600
 *   PNL_AUDIT_DRY=0   (1 = лише лог, без TG)
 */
$root = dirname(__DIR__);
require $root . '/www/vendor/autoload.php';
require_once __DIR__ . '/cron_singleton.php';
require_once __DIR__ . '/cron_host_guard.php';

cron_singleton_acquire('cron_audit_pnl_basis');
set_time_limit(180);

Dotenv\Dotenv::createImmutable($root)->safeLoad();
cron_host_guard_or_exit('cron_audit_pnl_basis');

require_once $root . '/engine/engine_helpers.php';
require_once $root . '/www/api/_tg_notify.php';

$lookbackH = max(1, (int)(getenv('PNL_AUDIT_LOOKBACK_HOURS') ?: ($_ENV['PNL_AUDIT_LOOKBACK_HOURS'] ?? 48)));
$minDelta = max(0.05, (float)(getenv('PNL_AUDIT_MIN_DELTA_USDT') ?: ($_ENV['PNL_AUDIT_MIN_DELTA_USDT'] ?? 0.50)));
$minBasisPct = max(0.5, (float)(getenv('PNL_AUDIT_MIN_BASIS_PCT') ?: ($_ENV['PNL_AUDIT_MIN_BASIS_PCT'] ?? 2.0)));
$cooldown = max(300, (int)(getenv('PNL_AUDIT_COOLDOWN_SEC') ?: ($_ENV['PNL_AUDIT_COOLDOWN_SEC'] ?? 21600)));
$dry = ((string)(getenv('PNL_AUDIT_DRY') ?: ($_ENV['PNL_AUDIT_DRY'] ?? '0')) === '1')
    || in_array('--dry', $argv ?? [], true);

$db = new mysqli(
    (string)($_ENV['DB_HOST'] ?? ''),
    (string)($_ENV['DB_USER'] ?? ''),
    (string)($_ENV['DB_PASS'] ?? ''),
    (string)($_ENV['DB_NAME'] ?? '')
);
if ($db->connect_errno) {
    fwrite(STDERR, date('c') . ' pnl_audit db: ' . $db->connect_error . "\n");
    exit(1);
}
$db->set_charset('utf8mb4');

function pnl_audit_should_alert(mysqli $db, string $fp, int $cooldownSec): bool
{
    $key = 'pnl_audit_fp_' . substr(hash('sha256', $fp), 0, 40);
    $esc = $db->real_escape_string($key);
    $res = $db->query("SELECT v FROM app_settings WHERE k='{$esc}' LIMIT 1");
    $last = 0;
    if ($res && ($row = $res->fetch_assoc())) {
        $last = (int)($row['v'] ?? 0);
    }
    if ($last > 0 && (time() - $last) < $cooldownSec) {
        return false;
    }
    $now = (string)time();
    $db->query("INSERT INTO app_settings (k,v) VALUES ('{$esc}','{$now}') ON DUPLICATE KEY UPDATE v=VALUES(v)");
    return true;
}

/**
 * @return array{qty:float,cost:float,basis:float,levels:list<array>}
 */
function pnl_audit_open_l14(mysqli $db, int $pid): array
{
    $out = ['qty' => 0.0, 'cost' => 0.0, 'basis' => 0.0, 'levels' => []];
    if ($pid <= 0) {
        return $out;
    }
    $q = $db->query(
        "SELECT level_idx, qty, buy_price FROM sub_positions
         WHERE position_id={$pid} AND closed=0 AND level_idx BETWEEN 1 AND 4
           AND qty > 1e-12 AND buy_price > 0
         ORDER BY level_idx"
    );
    while ($q && ($r = $q->fetch_assoc())) {
        $qq = (float)$r['qty'];
        $bp = (float)$r['buy_price'];
        $out['qty'] += $qq;
        $out['cost'] += $qq * $bp;
        $out['levels'][] = $r;
    }
    if ($out['qty'] > 1e-12) {
        $out['basis'] = $out['cost'] / $out['qty'];
    }
    return $out;
}

/**
 * L1–L4 closed near MAIN sell timestamp.
 * @return array{qty:float,cost:float,basis:float}
 */
function pnl_audit_l14_at_sell(mysqli $db, int $sid, string $createdAt, float $sellQty): array
{
    $out = ['qty' => 0.0, 'cost' => 0.0, 'basis' => 0.0];
    $esc = $db->real_escape_string($createdAt);
    $q = $db->query(
        "SELECT sp.level_idx, sp.qty, sp.buy_price
         FROM sub_positions sp
         JOIN positions p ON p.id = sp.position_id
         WHERE p.strategy_id = {$sid}
           AND sp.closed = 1
           AND sp.level_idx BETWEEN 1 AND 4
           AND sp.qty > 1e-12 AND sp.buy_price > 0
           AND sp.updated_at BETWEEN DATE_SUB('{$esc}', INTERVAL 15 SECOND)
                                AND DATE_ADD('{$esc}', INTERVAL 15 SECOND)
         ORDER BY sp.level_idx, sp.id"
    );
    // Dedupe by level (keep last matching qty closest to sell)
    $byLvl = [];
    while ($q && ($r = $q->fetch_assoc())) {
        $lv = (int)$r['level_idx'];
        $byLvl[$lv] = $r; // last wins
    }
    foreach ($byLvl as $r) {
        $qq = (float)$r['qty'];
        $bp = (float)$r['buy_price'];
        $out['qty'] += $qq;
        $out['cost'] += $qq * $bp;
    }
    if ($out['qty'] > 1e-12) {
        $out['basis'] = $out['cost'] / $out['qty'];
    }
    // If qty far from sell — not reliable
    if ($sellQty > 1e-8 && $out['qty'] > 1e-8) {
        $rel = abs($out['qty'] - $sellQty) / max($sellQty, 1e-8);
        if ($rel > 0.05) {
            return ['qty' => 0.0, 'cost' => 0.0, 'basis' => 0.0];
        }
    }
    return $out;
}

$findings = [];
$checkedLive = 0;
$checkedSells = 0;

// ——— 1) LIVE open positions ———
$sqlLive = "
  SELECT u.id AS uid, u.nickname, u.email,
         s.id AS sid, s.symbol, s.status, COALESCE(s.tp_whole, 1.4) AS tp_whole,
         p.id AS pid, p.qty, p.avg_price, p.base_price, p.next_ptr
  FROM strategies s
  JOIN users u ON u.id = s.user_id
  JOIN positions p ON p.strategy_id = s.id
  WHERE p.qty > 1e-8
    AND s.status IN ('running','paused','armed')
  ORDER BY s.id
";
$live = $db->query($sqlLive);
while ($live && ($row = $live->fetch_assoc())) {
    $checkedLive++;
    $sid = (int)$row['sid'];
    $pid = (int)$row['pid'];
    $uid = (int)$row['uid'];
    $sym = (string)$row['symbol'];
    $nick = (string)($row['nickname'] ?: $row['email'] ?: ('uid' . $uid));
    $avg = (float)$row['avg_price'];
    $tpw = (float)$row['tp_whole'];
    $qty = (float)$row['qty'];

    $l14 = pnl_audit_open_l14($db, $pid);
    if ($l14['basis'] <= 0 || $l14['qty'] <= 1e-8) {
        continue;
    }

    $cycle = function_exists('sync_ledger_open_cycle_inventory')
        ? sync_ledger_open_cycle_inventory($db, $sid)
        : ['qty' => 0.0, 'basis' => 0.0, 'cost' => 0.0];
    $cycleBasis = (float)($cycle['basis'] ?? 0);

    $corePos = [
        'id' => $pid,
        'qty' => $qty,
        'avg_price' => $avg,
        'base_price' => (float)$row['base_price'],
        'next_ptr' => (int)$row['next_ptr'],
    ];
    $core = function_exists('position_core_basis')
        ? position_core_basis($db, $corePos)
        : ['basis' => $l14['basis'], 'core_qty' => $l14['qty'], 'hi_qty' => 0.0, 'source' => 'l14'];
    $coreBasis = (float)($core['basis'] ?? $l14['basis']);
    $coreQty = (float)($core['core_qty'] ?? $l14['qty']);

    // Core SoT уже = L1–4 → брудний avg/cycle лише шум для UI; MAIN sell/PnL не чіпає.
    $coreOk = false;
    if ($coreBasis > 1e-12 && $l14['basis'] > 1e-12) {
        $coreDriftPct = abs($l14['basis'] - $coreBasis) / $l14['basis'] * 100.0;
        $coreOk = ($coreDriftPct < $minBasisPct);
    }

    // A) cycle-avg understates L1–L4 — алертимо лише якщо core SoT теж «кривий»
    if (!$coreOk && $cycleBasis > 1e-12 && $l14['basis'] > 1e-12) {
        $underPct = (($l14['basis'] - $cycleBasis) / $l14['basis']) * 100.0;
        if ($underPct >= $minBasisPct) {
            $fakeTp = $cycleBasis * (1.0 + $tpw / 100.0);
            $trueTp = $l14['basis'] * (1.0 + $tpw / 100.0);
            $hypoInflate = ($l14['basis'] - $cycleBasis) * max($coreQty, $l14['qty']);
            if ($hypoInflate >= $minDelta) {
                $findings[] = [
                    'kind' => 'LIVE_CYCLE_VS_L14',
                    'fp' => "live_cycle:{$sid}",
                    'line' => sprintf(
                        "LIVE %s uid=%d %s sid=%d\n  L1–4 basis=%.8f → MAIN TP≈%.8f\n  cycle-avg=%.8f → fake TP≈%.8f (заниження %.1f%%)\n  core_basis=%.8f (SoT НЕ збігається з L1–4)\n  ризик фейк PnL ≈ +%.2f USDT на ядрі qty≈%.4f",
                        $sym,
                        $uid,
                        $nick,
                        $sid,
                        $l14['basis'],
                        $trueTp,
                        $cycleBasis,
                        $fakeTp,
                        $underPct,
                        $coreBasis,
                        $hypoInflate,
                        $coreQty
                    ),
                ];
            }
        }
    }

    // B) polluted avg — лише якщо core SoT теж відхиляється від L1–4
    if (!$coreOk && $avg > 1e-12 && $l14['basis'] > 1e-12) {
        $avgUnder = (($l14['basis'] - $avg) / $l14['basis']) * 100.0;
        $hiOpen = (float)($core['hi_qty'] ?? 0);
        if ($avgUnder >= $minBasisPct && $hiOpen > 1e-8) {
            $impact = ($l14['basis'] - $avg) * $coreQty;
            if ($impact >= $minDelta) {
                $findings[] = [
                    'kind' => 'LIVE_AVG_POLLUTED',
                    'fp' => "live_avg:{$sid}",
                    'line' => sprintf(
                        "LIVE %s uid=%d %s sid=%d\n  avg_price=%.8f << L1–4=%.8f (%.1f%%)\n  core_basis=%.8f · L5+ open qty≈%.4f\n  SoT відхилення — фейк ≈ +%.2f USDT",
                        $sym,
                        $uid,
                        $nick,
                        $sid,
                        $avg,
                        $l14['basis'],
                        $avgUnder,
                        $coreBasis,
                        $hiOpen,
                        $impact
                    ),
                ];
            }
        }
    }
}

// ——— 2) Recent SELL_MAIN ———
$since = date('Y-m-d H:i:s', time() - $lookbackH * 3600);
$escSince = $db->real_escape_string($since);
$sqlSell = "
  SELECT tl.id, tl.strategy_id, tl.symbol, tl.created_at, tl.qty, tl.price, tl.quote_usdt,
         tl.basis_price, tl.pnl_usdt, tl.pnl_pct,
         s.user_id, u.nickname, u.email
  FROM trade_ledger tl
  JOIN strategies s ON s.id = tl.strategy_id
  JOIN users u ON u.id = s.user_id
  WHERE tl.event_type = 'SELL_MAIN'
    AND tl.created_at >= '{$escSince}'
    AND tl.pnl_usdt IS NOT NULL
    AND tl.basis_price IS NOT NULL
    AND tl.qty > 1e-8
  ORDER BY tl.id DESC
";
$sells = $db->query($sqlSell);
while ($sells && ($row = $sells->fetch_assoc())) {
    $checkedSells++;
    $lid = (int)$row['id'];
    $sid = (int)$row['strategy_id'];
    $uid = (int)$row['user_id'];
    $sym = (string)$row['symbol'];
    $nick = (string)($row['nickname'] ?: $row['email'] ?: ('uid' . $uid));
    $sellQty = (float)$row['qty'];
    $sellPx = (float)$row['price'];
    $quote = (float)$row['quote_usdt'];
    if ($quote <= 0) {
        $quote = $sellQty * $sellPx;
    }
    $recBasis = (float)$row['basis_price'];
    $recPnl = (float)$row['pnl_usdt'];

    $l14 = pnl_audit_l14_at_sell($db, $sid, (string)$row['created_at'], $sellQty);
    if ($l14['basis'] <= 1e-12) {
        continue;
    }
    $truePnl = $quote - $l14['basis'] * $sellQty;
    $inflate = $recPnl - $truePnl;
    $basisUnderPct = $recBasis > 1e-12
        ? (($l14['basis'] - $recBasis) / $l14['basis']) * 100.0
        : 0.0;

    if ($inflate < $minDelta && $basisUnderPct < $minBasisPct) {
        continue;
    }
    if ($inflate < $minDelta) {
        continue; // require money impact
    }

    $fee = 0.0;
    $fr = $db->query(
        "SELECT amount_usdt FROM billing_ledger
         WHERE trade_ledger_id={$lid} AND entry_type='fee' LIMIT 1"
    );
    if ($fr && ($f = $fr->fetch_assoc())) {
        $fee = abs((float)$f['amount_usdt']);
    }

    $findings[] = [
        'kind' => 'SELL_MAIN_FALSE_PNL',
        'fp' => "sell:{$lid}",
        'line' => sprintf(
            "SELL_MAIN #%d %s uid=%d %s sid=%d @ %s\n  recorded basis=%.8f pnl=%+.4f\n  L1–4 truth basis=%.8f pnl=%+.4f\n  INFLATED %+.4f USDT%s",
            $lid,
            $sym,
            $uid,
            $nick,
            $sid,
            $row['created_at'],
            $recBasis,
            $recPnl,
            $l14['basis'],
            $truePnl,
            $inflate,
            $fee > 1e-8 ? sprintf(' · fee charged %.4f (перевірити refund)', $fee) : ''
        ),
    ];
}

echo date('c') . " pnl_audit live={$checkedLive} sells={$checkedSells} findings=" . count($findings) . ($dry ? " DRY\n" : "\n");

if (!$findings) {
    echo "ok: no basis/PnL anomalies\n";
    exit(0);
}

// Dedup + build TG
$alertLines = [];
foreach ($findings as $f) {
    $fp = (string)$f['fp'];
    if (!$dry && !pnl_audit_should_alert($db, $fp, $cooldown)) {
        echo "skip_cooldown {$fp}\n";
        continue;
    }
    $alertLines[] = $f['line'];
    echo ($dry ? 'WOULD_ALERT' : 'ALERT') . " {$f['kind']} {$fp}\n";
}

if (!$alertLines) {
    echo "all findings on cooldown\n";
    exit(0);
}

$max = 12;
$chunk = array_slice($alertLines, 0, $max);
$more = count($alertLines) - count($chunk);
$text = "⚠️ <b>StrIM PnL/basis audit</b>\n"
    . 'Перевірено: live=' . $checkedLive . ', SELL_MAIN(' . $lookbackH . 'h)=' . $checkedSells . "\n"
    . 'Аномалії: ' . count($alertLines) . "\n\n"
    . htmlspecialchars(implode("\n\n———\n\n", $chunk), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
if ($more > 0) {
    $text .= "\n\n… ще +{$more} (див. logs/audit_pnl_basis.log)";
}

if ($dry) {
    echo "DRY TG:\n{$text}\n";
    exit(0);
}

[$ok, $msg] = tg_notify($text, ['skip_journal' => true]);
echo ($ok ? 'tg_ok' : 'tg_fail') . " {$msg}\n";
exit($ok ? 0 : 1);
