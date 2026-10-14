<?php
declare(strict_types=1);
/**
 * Видалити хибні рядки engine_journal від щогодинного site_audit (парсер TG).
 *   php run/cleanup_journal_site_audit_spam.php [--dry-run] [strategy_id]
 */
require __DIR__ . '/../www/vendor/autoload.php';

Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

$dryRun = in_array('--dry-run', $argv, true);
$sid = 0;
foreach ($argv as $a) {
    if (ctype_digit((string) $a)) {
        $sid = (int) $a;
    }
}

$db = new mysqli(
    (string) ($_ENV['DB_HOST'] ?? ''),
    (string) ($_ENV['DB_USER'] ?? ''),
    (string) ($_ENV['DB_PASS'] ?? ''),
    (string) ($_ENV['DB_NAME'] ?? '')
);
$db->set_charset('utf8mb4');

$sidFilter = $sid > 0 ? "AND strategy_id = {$sid}" : '';
$res = $db->query("
    SELECT id, strategy_id, symbol, event_type, level_idx, created_at
    FROM engine_journal
    WHERE message LIKE '%щогодинний звіт%'
    {$sidFilter}
    ORDER BY id
");

$ids = [];
while ($res && ($row = $res->fetch_assoc())) {
    $ids[] = (int) $row['id'];
    echo json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";
}

echo 'spam_rows=' . count($ids) . ' dry_run=' . ($dryRun ? '1' : '0') . "\n";

if ($ids === [] || $dryRun) {
    exit(0);
}

$idList = implode(',', $ids);
$db->query("DELETE FROM engine_journal WHERE id IN ({$idList})");
echo 'deleted=' . $db->affected_rows . "\n";
