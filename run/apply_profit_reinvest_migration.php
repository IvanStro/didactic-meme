<?php
declare(strict_types=1);

require __DIR__ . '/../www/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

$m = new mysqli(
    (string)($_ENV['DB_HOST'] ?? ''),
    (string)($_ENV['DB_USER'] ?? ''),
    (string)($_ENV['DB_PASS'] ?? ''),
    (string)($_ENV['DB_NAME'] ?? '')
);
if ($m->connect_errno) {
    fwrite(STDERR, "DB_CONNECT_FAIL: " . $m->connect_error . PHP_EOL);
    exit(2);
}
$m->set_charset('utf8mb4');
$dbName = (string)($_ENV['DB_NAME'] ?? '');

function col_exists(mysqli $m, string $db, string $table, string $col): bool
{
    $dbE = $m->real_escape_string($db);
    $tE = $m->real_escape_string($table);
    $cE = $m->real_escape_string($col);
    $q = $m->query(
        "SELECT COUNT(*) AS c FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA='{$dbE}' AND TABLE_NAME='{$tE}' AND COLUMN_NAME='{$cE}'"
    );
    $row = $q ? $q->fetch_assoc() : null;
    return $row ? ((int)$row['c'] > 0) : false;
}

$adds = [
    'profit_reinvest' => "TINYINT(1) NOT NULL DEFAULT 1",
    'profit_reserve_usdt' => "DECIMAL(18,8) NOT NULL DEFAULT 0",
    'profit_reserve_since' => "DATETIME NULL DEFAULT NULL",
];
foreach ($adds as $col => $def) {
    if (col_exists($m, $dbName, 'users', $col)) {
        echo "OK already: users.{$col}\n";
        continue;
    }
    if (!$m->query("ALTER TABLE users ADD COLUMN {$col} {$def}")) {
        fwrite(STDERR, "FAIL ADD {$col}: " . $m->error . PHP_EOL);
        exit(4);
    }
    echo "ADDED users.{$col}\n";
}
echo "DONE\n";
