<?php
declare(strict_types=1);
/**
 * Щоденний аудит Binance SELL vs trade_ledger. Telegram адміну при orphan/dup.
 *   php run/cron_audit_orphan_sells.php
 *
 * Cron (раз на добу, напр. 06:00) — тільки абсолютні шляхи (ADM cwd=/home/bot20):
 *   /bin/bash /home/bot20/strim.in.ua/run/cron_audit_orphan_sells.sh
 * або:
 *   /usr/local/php84/bin/php /home/bot20/strim.in.ua/run/cron_audit_orphan_sells.php >> /home/bot20/strim.in.ua/logs/audit_orphan_sells.log 2>&1
 * НЕ використовуйте >> ../logs/... — дасть ERROR #1 у ADM.TOOLS.
 */
$root = dirname(__DIR__);
require $root . '/www/vendor/autoload.php';
require_once __DIR__ . '/cron_singleton.php';
require_once __DIR__ . '/cron_host_guard.php';

cron_singleton_acquire('cron_audit_orphan_sells');
set_time_limit(300);

Dotenv\Dotenv::createImmutable($root)->safeLoad();
cron_host_guard_or_exit('cron_audit_orphan_sells');

require_once __DIR__ . '/audit_orphan_sells.php';
require_once $root . '/www/api/_tg_notify.php';

try {
    $db = db();
} catch (Throwable $e) {
    fwrite(STDERR, date('c') . ' audit_orphan_sells db_connect_error: ' . $e->getMessage() . "\n");
    exit(1);
}

$cooldownSec = max(3600, (int)(getenv('AUDIT_ORPHAN_COOLDOWN_SEC') ?: ($_ENV['AUDIT_ORPHAN_COOLDOWN_SEC'] ?? 86400)));

$issues = [];
$strategyCount = 0;
try {
    $r = $db->query("SELECT s.id, s.user_id, s.symbol, u.nickname FROM strategies s JOIN users u ON u.id=s.user_id WHERE s.status='running' ORDER BY s.id");
    $strategyCount = $r ? $r->num_rows : 0;
    while ($r && ($st = $r->fetch_assoc())) {
        $sid = (int) $st['id'];
        $uid = (int) $st['user_id'];
        $sym = (string) $st['symbol'];
        try {
            $rep = audit_strategy($db, $uid, $sid, $sym);
        } catch (Throwable $e) {
            echo date('c') . " sid={$sid} audit_error: " . $e->getMessage() . "\n";
            continue;
        }
        if (!empty($rep['error'])) {
            echo date('c') . " sid={$sid} skip: " . (string)$rep['error'] . "\n";
            continue;
        }
        $orphN = count($rep['orphan_sells'] ?? []);
        $dupN = count($rep['dup_levels_ledger'] ?? []);
        if ($orphN > 0 || $dupN > 0) {
            $issues[] = [
                'nick' => (string) $st['nickname'],
                'sid' => $sid,
                'sym' => $sym,
                'orphans' => $orphN,
                'dups' => $dupN,
            ];
        }
    }
} catch (Throwable $e) {
    fwrite(STDERR, date('c') . ' audit_orphan_sells fatal: ' . $e->getMessage() . "\n");
    exit(1);
}

$line = date('c') . ' audit_orphan_sells strategies=' . $strategyCount . ' issues=' . count($issues);
echo $line . "\n";

if ($issues === []) {
    exit(0);
}

foreach ($issues as $i) {
    echo "  sid={$i['sid']} {$i['sym']} orphans={$i['orphans']} dup_levels={$i['dups']}\n";
}

$keyEsc = $db->real_escape_string('audit_orphan_last_alert_utc');
$last = '';
$res = $db->query("SELECT v FROM app_settings WHERE k='{$keyEsc}' LIMIT 1");
if ($res) {
    $row = $res->fetch_assoc();
    if (is_array($row)) {
        $last = (string)($row['v'] ?? '');
    }
}
if ($last !== '') {
    $age = time() - strtotime($last);
    if ($age >= 0 && $age < $cooldownSec) {
        echo date('c') . " skip TG (cooldown {$age}s < {$cooldownSec}s)\n";
        exit(0);
    }
}

$lines = ["⚠️ StrIM audit: orphan/dup sells (" . count($issues) . " strategies)"];
foreach (array_slice($issues, 0, 12) as $i) {
    $lines[] = sprintf('%s sid=%d %s orphan=%d dupL=%d', $i['nick'], $i['sid'], $i['sym'], $i['orphans'], $i['dups']);
}
if (count($issues) > 12) {
    $lines[] = '… +' . (count($issues) - 12) . ' more';
}
$lines[] = 'Run: php run/link_ledger_order_ids.php --all-running';
$lines[] = 'Run: php run/backfill_binance_sell.php SID ORDER_ID [level]';

[$ok, $msg] = tg_notify(implode("\n", $lines));
echo date('c') . ' tg_notify ok=' . ($ok ? '1' : '0') . ' ' . $msg . "\n";

if ($ok) {
    $now = gmdate('Y-m-d H:i:s');
    $nowEsc = $db->real_escape_string($now);
    $db->query("INSERT INTO app_settings (k, v) VALUES ('{$keyEsc}', '{$nowEsc}') ON DUPLICATE KEY UPDATE v='{$nowEsc}'");
}

exit(0);
