<?php
declare(strict_types=1);
/**
 * One-off: видалити порожні дублікати positions (strategy_id з >1 рядком).
 * Залишає canonical рядок (position_pick_row). Dry-run: DRY_RUN=1
 *
 *   php run/fix_duplicate_positions.php
 *   DRY_RUN=1 php run/fix_duplicate_positions.php
 */

require __DIR__ . '/../www/vendor/autoload.php';
require_once __DIR__ . '/../engine/engine_helpers.php';

Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

$dry = (getenv('DRY_RUN') ?: '') === '1';

$m = new mysqli(
    (string)($_ENV['DB_HOST'] ?? ''),
    (string)($_ENV['DB_USER'] ?? ''),
    (string)($_ENV['DB_PASS'] ?? ''),
    (string)($_ENV['DB_NAME'] ?? '')
);
$m->set_charset('utf8mb4');

$dup = $m->query("
    SELECT strategy_id, COUNT(*) c, GROUP_CONCAT(id ORDER BY id) ids
    FROM positions
    GROUP BY strategy_id
    HAVING c > 1
");
if (!$dup) {
    fwrite(STDERR, "query failed\n");
    exit(1);
}

$deleted = 0;
while ($row = $dup->fetch_assoc()) {
    $sid = (int)$row['strategy_id'];
    $st = $m->query("SELECT user_id FROM strategies WHERE id=$sid LIMIT 1");
    $uid = ($st && ($sr = $st->fetch_assoc())) ? (int)($sr['user_id'] ?? 0) : 0;
    $keep = position_pick_row($m, $sid, $uid);
    $keepId = $keep ? (int)($keep['id'] ?? 0) : 0;
    echo "strategy_id=$sid keep_id=$keepId ids={$row['ids']}\n";

    $all = $m->query("SELECT id, qty, state FROM positions WHERE strategy_id=$sid ORDER BY id");
    while ($all && ($p = $all->fetch_assoc())) {
        $pid = (int)$p['id'];
        if ($pid === $keepId || $pid <= 0) {
            continue;
        }
        $hasSubs = position_has_open_subs($m, $pid);
        $qty = (float)($p['qty'] ?? 0);
        if ($hasSubs) {
            echo "  skip id=$pid (has open subs)\n";
            continue;
        }
        if ($qty > 0.00000001) {
            echo "  skip id=$pid (qty>0)\n";
            continue;
        }
        echo "  " . ($dry ? 'would delete' : 'delete') . " id=$pid\n";
        if (!$dry) {
            $m->query("DELETE FROM positions WHERE id=$pid LIMIT 1");
            $deleted++;
        }
    }
}

echo $dry ? "dry-run done\n" : "deleted=$deleted\n";
