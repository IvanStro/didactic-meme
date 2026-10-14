<?php
declare(strict_types=1);

/**
 * Опитування Privat Autoclient API → bank_incoming_payments → (опційно) +30 днів.
 *
 *   php run/privat_poll_transactions.php [lookback_days]
 *
 * .env:
 *   PRIVAT_ACP_TOKEN=...
 *   PRIVAT_ACP_CLIENT_ID=...     (якщо API в режимі групи ПП)
 *   PRIVAT_ACP_IBAN=UA34...      (за замовч. IBAN StrIM)
 *   PRIVAT_ACP_AUTO_EXTEND=1     (0 = лише запис у БД, без +30)
 *   PRIVAT_ACP_DRY_RUN=1         (матч без UPDATE users)
 */

$root = dirname(__DIR__);
require $root . '/www/vendor/autoload.php';
require_once $root . '/www/api/_privat_bank_billing.php';

Dotenv\Dotenv::createImmutable($root)->safeLoad();

$lookback = isset($argv[1]) ? max(1, min(14, (int) $argv[1])) : 3;

$m = new mysqli(
    (string)($_ENV['DB_HOST'] ?? ''),
    (string)($_ENV['DB_USER'] ?? ''),
    (string)($_ENV['DB_PASS'] ?? ''),
    (string)($_ENV['DB_NAME'] ?? '')
);
if ($m->connect_errno) {
    fwrite(STDERR, 'db: ' . $m->connect_error . PHP_EOL);
    exit(1);
}
$m->set_charset('utf8mb4');

echo '=== Privat bank poll (lookback=' . $lookback . ' days) ===' . PHP_EOL;
echo 'auto_extend=' . (privat_bank_auto_extend_enabled() ? '1' : '0');
echo ' dry_run=' . (privat_bank_dry_run() ? '1' : '0') . PHP_EOL;

$result = privat_bank_poll_and_process($m, $lookback);
if (!($result['ok'] ?? false)) {
    fwrite(STDERR, 'ERROR: ' . ($result['error'] ?? 'unknown') . PHP_EOL);
    exit(1);
}

$st = $result['stats'] ?? [];
foreach ($st as $k => $v) {
    echo $k . '=' . (is_bool($v) ? ($v ? '1' : '0') : $v) . PHP_EOL;
}

$r = $m->query(
    "SELECT id, privat_ref, amount_uah, status, matched_user_id, LEFT(osnd, 80) osnd_snip, match_note
     FROM bank_incoming_payments ORDER BY id DESC LIMIT 8"
);
echo PHP_EOL . '--- last rows ---' . PHP_EOL;
while ($r && ($row = $r->fetch_assoc())) {
    echo json_encode($row, JSON_UNESCAPED_UNICODE) . PHP_EOL;
}

echo PHP_EOL . 'done' . PHP_EOL;
