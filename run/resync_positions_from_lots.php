<?php
declare(strict_types=1);
$root = dirname(__DIR__);
require $root . '/www/vendor/autoload.php';
require_once $root . '/engine/engine_helpers.php';
Dotenv\Dotenv::createImmutable($root)->safeLoad();
$db = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
$db->set_charset('utf8mb4');
$q = $db->query(
    "SELECT p.id, s.symbol, s.id AS sid, u.nickname
     FROM positions p
     JOIN strategies s ON s.id = p.strategy_id
     JOIN users u ON u.id = s.user_id
     WHERE p.qty > 1e-8 AND s.status IN ('running','paused','armed')"
);
$n = 0;
while ($q && ($r = $q->fetch_assoc())) {
    if (position_resync_from_open_lots($db, (int)$r['id'])) {
        $n++;
        echo "resync sid={$r['sid']} {$r['symbol']} {$r['nickname']} pid={$r['id']}\n";
    }
}
echo "resynced=$n\n";
$p = $db->query('SELECT * FROM positions WHERE strategy_id=17 ORDER BY id DESC LIMIT 1')->fetch_assoc();
if ($p) {
    $snap = position_lots_snapshot($db, $p);
    $core = position_core_basis($db, $p);
    echo sprintf(
        "ADA core_basis=%.8f bag_basis=%.8f MAIN_TP=%.8f source=%s\n",
        $snap['core_basis'],
        $snap['bag_basis'],
        $core['basis'] * 1.014,
        $core['source']
    );
}
