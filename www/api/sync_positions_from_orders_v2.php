<?php
require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/_binance_utils.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__, 2));
$dotenv->safeLoad();

header('Content-Type: text/plain; charset=utf-8');

$mysqli = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
if ($mysqli->connect_errno) { http_response_code(500); echo "DB ERR\n"; exit; }
$mysqli->set_charset('utf8mb4');

function q($db,$s){ return "'".$db->real_escape_string($s)."'"; }

list($apiKey,$secret) = bn_keys();
$base = bn_base();

function my_trades($symbol,$apiKey,$secret){
  $ts = bn_server_time();
  $params = array('symbol'=>$symbol,'timestamp'=>$ts,'limit'=>1000,'recvWindow'=>5000);
  $query  = bn_sign_query($params,$secret);
  list($h,$r,$e,$er) = http_json(bn_base().'/api/v3/myTrades?'.$query, array('X-MBX-APIKEY: '.$apiKey), 'GET');
  if ($e || $h>=400) return array();
  $data = json_decode($r,true);
  return is_array($data)?$data:array();
}

$res = $mysqli->query("SELECT * FROM strategies WHERE status='running'");
$cnt = 0;
while ($st = $res->fetch_assoc()){
  $sid = intval($st['id']);
  $symbol = $st['symbol'] ?: 'BTCUSDT';

  $tr = my_trades($symbol,$apiKey,$secret);
  $bq=0.0; $sq=0.0; $bc=0.0; $sc=0.0; $lb=0.0; $lbt=0;
  foreach($tr as $t){
    $price = floatval(isset($t['price'])?$t['price']:0);
    $qty   = floatval(isset($t['qty'])?$t['qty']:0);
    $isBuy = !empty($t['isBuyer']);
    $time  = intval(isset($t['time'])?$t['time']:0);
    if ($isBuy){ $bq += $qty; $bc += $price*$qty; if ($time>=$lbt){ $lbt=$time; $lb=$price; } }
    else { $sq += $qty; $sc += $price*$qty; }
  }
  $net_q = $bq - $sq;
  $net_c = $bc - $sc;
  $avg   = ($net_q>0)?($net_c/$net_q):0.0;

  // ensure position row
  $r2 = $mysqli->query("SELECT * FROM positions WHERE strategy_id=$sid LIMIT 1");
  if (!($r2 && ($pos=$r2->fetch_assoc()))){
    $mysqli->query("INSERT INTO positions(strategy_id)VALUES($sid)");
    $r2 = $mysqli->query("SELECT * FROM positions WHERE strategy_id=$sid LIMIT 1");
    $pos = ($r2 && ($p=$r2->fetch_assoc())) ? $p : null;
  }
  if ($pos){
    $id=intval($pos['id']);
    $sql = "UPDATE positions SET qty=".number_format($net_q,18,'.','').",
      invested_usdt=".number_format($net_c,18,'.','').",
      avg_price=".number_format($avg,18,'.','').",
      last_buy=".number_format($lb,18,'.','').",
      updated_at=NOW() WHERE id=$id AND strategy_id=$sid";
    $mysqli->query($sql);
    $cnt++;
  }
}
echo "synced=$cnt\n";
