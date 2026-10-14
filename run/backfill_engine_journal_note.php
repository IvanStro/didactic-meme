<?php
declare(strict_types=1);
/**
 * Одноразовий рядок у engine_journal (наприклад, коли ордер був, а запис не створився).
 *
 *   php run/backfill_engine_journal_note.php <strategy_id> "Текст повідомлення"
 *
 * Потрібні змінні середовища DB_* у .env. Без автоматичного запуску на проді — лише за потреби.
 */

if ($argc < 3) {
    fwrite(STDERR, "Usage: php run/backfill_engine_journal_note.php <strategy_id> \"message\"\n");
    exit(1);
}

$sid = (int)$argv[1];
$msg = (string)$argv[2];
if ($sid <= 0 || $msg === '') {
    fwrite(STDERR, "bad args\n");
    exit(1);
}

require __DIR__ . '/../www/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

$m = new mysqli($_ENV['DB_HOST'] ?? '', $_ENV['DB_USER'] ?? '', $_ENV['DB_PASS'] ?? '', $_ENV['DB_NAME'] ?? '');
if ($m->connect_errno) {
    fwrite(STDERR, $m->connect_error . "\n");
    exit(1);
}
$m->set_charset('utf8mb4');

$q = $m->query("SELECT id, symbol FROM strategies WHERE id=$sid LIMIT 1");
$row = $q ? $q->fetch_assoc() : null;
if (!$row) {
    fwrite(STDERR, "strategy not found\n");
    exit(1);
}
$sym = (string)$row['symbol'];
$symEsc = $m->real_escape_string($sym);
$msgEsc = $m->real_escape_string($msg);

$sql = "INSERT INTO engine_journal (created_at, strategy_id, symbol, event_type, level_idx, side, message)
        VALUES (NOW(), $sid, '$symEsc', 'INFO', NULL, NULL, '$msgEsc')";
if (!$m->query($sql)) {
    fwrite(STDERR, $m->error . "\n");
    exit(1);
}

echo "ok id=" . $m->insert_id . " strategy=$sid symbol=$sym\n";
