<?php
declare(strict_types=1);
/** Множник для BUY: 1 + (BUY_EPS_PCT/100), де BUY_EPS_PCT у % (дефолт 0.01) */
function buy_eps_mul(mysqli $db): float {
  $epsPct = 0.01; // %
  $r = $db->query("SELECT v FROM app_settings WHERE k='BUY_EPS_PCT' LIMIT 1");
  if ($r && ($row = $r->fetch_assoc())) {
    $tmp = (float)$row['v'];
    if ($tmp >= 0) $epsPct = $tmp;
  }
  return 1.0 + ($epsPct / 100.0);
}
