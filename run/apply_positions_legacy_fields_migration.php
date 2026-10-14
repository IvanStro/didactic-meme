<?php
declare(strict_types=1);
/**
 * P2: legacy/hybrid fields on positions.
 * php run/apply_positions_legacy_fields_migration.php
 */
$root = dirname(__DIR__);
require $root . '/www/vendor/autoload.php';
Dotenv\Dotenv::createImmutable($root)->safeLoad();

$db = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
if ($db->connect_error) {
    fwrite(STDERR, "DB fail\n");
    exit(1);
}
$db->set_charset('utf8mb4');

$cols = [
    'origin' => "ENUM('strim','legacy','hybrid') NOT NULL DEFAULT 'strim'",
    'cost_avg' => 'DECIMAL(24,8) NULL DEFAULT NULL',
    'grid_anchor' => 'DECIMAL(24,8) NULL DEFAULT NULL',
    'legacy_qty' => 'DECIMAL(24,12) NULL DEFAULT NULL',
    'adopt_mode' => 'VARCHAR(32) NULL DEFAULT NULL',
    'adopted_at' => 'DATETIME NULL DEFAULT NULL',
];

foreach ($cols as $name => $def) {
    $r = $db->query("SHOW COLUMNS FROM positions LIKE '" . $db->real_escape_string($name) . "'");
    if ($r && $r->num_rows > 0) {
        echo "exists: $name\n";
        continue;
    }
    if (!$db->query("ALTER TABLE positions ADD COLUMN `$name` $def")) {
        fwrite(STDERR, "ADD $name failed: {$db->error}\n");
        exit(1);
    }
    echo "added: $name\n";
}
echo "OK positions legacy fields\n";
