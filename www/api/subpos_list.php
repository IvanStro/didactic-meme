<?php
declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/_binance_utils.php';
require_once __DIR__.'/_filters_utils.php';
require_once __DIR__.'/../auth/_session_user.php';
$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__, 2)); $dotenv->safeLoad();

header('Content-Type: application/json; charset=utf-8');

$mysqli = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
if ($mysqli->connect_errno) { echo json_encode(['ok'=>false,'error'=>'db']); exit; }
$mysqli->set_charset('utf8mb4');

$uid = current_user_id($mysqli);
if ($uid <= 0) {
  echo json_encode(['ok'=>false,'error'=>'unauthorized'], JSON_UNESCAPED_UNICODE);
  exit;
}

$symbol = isset($_GET['symbol']) ? trim($_GET['symbol']) : '';
$where  = '';
if ($symbol !== '') {
  $where = "AND s.symbol='".$mysqli->real_escape_string($symbol)."'";
}

/*
  Відкриті підпозиції:
  - по всіх running-стратегіях (або по конкретному symbol, якщо ?symbol=...)
  - closed=0
*/
$sql = "
SELECT
  s.symbol,
  p.id         AS position_id,
  sp.id        AS subpos_id,
  sp.level_idx,
  sp.qty,
  sp.buy_price,
  sp.tp,
  sp.cb,
  sp.closed,
  sp.created_at
FROM strategies s
JOIN positions  p  ON p.strategy_id = s.id
JOIN sub_positions sp ON sp.position_id = p.id
WHERE s.status='running' AND sp.closed=0  AND s.owner_id=".(int)$uid."
ORDER BY s.symbol ASC, sp.level_idx ASC, sp.id ASC
";

$res = $mysqli->query($sql);
$items = [];
if ($res) {
  while ($row = $res->fetch_assoc()) {
    $items[] = [
      'symbol'     => $row['symbol'],
      'position_id'=> (int)$row['position_id'],
      'subpos_id'  => (int)$row['subpos_id'],
      'level_idx'  => (int)$row['level_idx'],
      'qty'        => (string)number_format((float)$row['qty'], 8, '.', ''),
      'buy_price'  => (string)number_format((float)$row['buy_price'], 8, '.', ''),
      'tp'         => (string)number_format((float)$row['tp'], 2, '.', ''),
      'cb'         => (string)number_format((float)$row['cb'], 2, '.', ''),
      'created_at' => $row['created_at'],
    ];
  }
}

echo json_encode(['ok'=>true,'items'=>$items,'count'=>count($items)], JSON_UNESCAPED_UNICODE);
