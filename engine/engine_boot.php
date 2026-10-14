<?php

require __DIR__ . '/../www/vendor/autoload.php';

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(dirname(__DIR__, 2));
$dotenv->safeLoad();


// ENV_EXPORT_AFTER_DOTENV
// Ensure values from .env are available via getenv() in CLI/cron,
// and normalize TRADING_ENABLED if it becomes non-scalar for any reason.
foreach ($_ENV as $k => $v) {
  if (is_array($v)) {
    // keep arrays in $_ENV, but do not export them to process env
    continue;
  }
  if ($v === null) continue;
  putenv($k . '=' . (string)$v);
}

// Normalize TRADING_ENABLED
if (isset($_ENV['TRADING_ENABLED'])) {
  $te = $_ENV['TRADING_ENABLED'];
  if (is_array($te)) {
    $te = end($te);
  }
  $te = (string)$te;
  $_ENV['TRADING_ENABLED'] = $te;
  putenv('TRADING_ENABLED=' . $te);
}



date_default_timezone_set('UTC');

function logx($msg) {
  $ts = date('Y-m-d H:i:s');
  $line = "[$ts] ".$msg."\n";
  $logDir = __DIR__.'/../logs';
  if (!is_dir($logDir)) {
    @mkdir($logDir, 0777, true);
  }
  file_put_contents($logDir.'/tick.multi.log', $line, FILE_APPEND);
}

// Підключення до БД
$mysqli = new mysqli(
  $_ENV['DB_HOST'],
  $_ENV['DB_USER'],
  $_ENV['DB_PASS'],
  $_ENV['DB_NAME']
);
$mysqli->set_charset('utf8mb4');

// API ключі можна брати з users або .env — це ми визначимо
$apiKey   = $_ENV['BINANCE_API_KEY'] ?? '';
$secret   = $_ENV['BINANCE_API_SECRET'] ?? '';


/**
 * Write a structured event to DB journal.
 * $data keys: strategy_id, symbol, event_type, level_idx, side, qty, price, pnl_usdt, pnl_pct, message
 */
function journal_log(mysqli $db, array $data): void {
    $strategy_id = (int)($data['strategy_id'] ?? 0);
    $symbol      = (string)($data['symbol'] ?? '');
    $event_type  = (string)($data['event_type'] ?? '');
    $level_idx   = isset($data['level_idx']) ? (int)$data['level_idx'] : null;
    $side        = isset($data['side']) ? (string)$data['side'] : null;

    $qty     = isset($data['qty']) ? (float)$data['qty'] : null;
    $price   = isset($data['price']) ? (float)$data['price'] : null;
    $pnl_usdt= isset($data['pnl_usdt']) ? (float)$data['pnl_usdt'] : null;
    $pnl_pct = isset($data['pnl_pct']) ? (float)$data['pnl_pct'] : null;

    $message = isset($data['message']) ? (string)$data['message'] : null;

    // escape strings
    $symbol_esc     = $db->real_escape_string($symbol);
    $event_type_esc = $db->real_escape_string($event_type);
    $side_esc       = $side === null ? "NULL" : ("'".$db->real_escape_string($side)."'");
    $msg_esc        = $message === null ? "NULL" : ("'".$db->real_escape_string($message)."'");

    $level_sql = $level_idx === null ? "NULL" : (string)$level_idx;
    $qty_sql   = $qty === null ? "NULL" : number_format($qty, 8, '.', '');
    $price_sql = $price === null ? "NULL" : number_format($price, 8, '.', '');
    $pnl_sql   = $pnl_usdt === null ? "NULL" : number_format($pnl_usdt, 8, '.', '');
    $pnlp_sql  = $pnl_pct === null ? "NULL" : number_format($pnl_pct, 4, '.', '');

    $sql = "
        INSERT INTO engine_journal
        (strategy_id, symbol, event_type, level_idx, side, qty, price, pnl_usdt, pnl_pct, message)
        VALUES
        ($strategy_id, '$symbol_esc', '$event_type_esc', $level_sql, $side_esc, $qty_sql, $price_sql, $pnl_sql, $pnlp_sql, $msg_esc)
    ";

    // не валимо двигун, якщо журнал не пишеться
    @$db->query($sql);
}
