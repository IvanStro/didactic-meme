<?php
// FORCE_TP_CB_IN_HUMAN_V1
// ENGINE_VIEW_STRUCT_V1
declare(strict_types=1);

require __DIR__ . '/../auth/api_gate_json.php'; // $auth_user + $mysqli

header('Content-Type: application/json; charset=utf-8');

$uid = (int)($auth_user['id'] ?? 0);
if ($uid <= 0) {
  
// ENGINE_VIEW_STRUCT_V1: add structured fields for UI (keep legacy text intact)
$extra = [];

// 1) Take tp_whole / cb_whole from strategy row if present
if (isset($st) && is_array($st)) {
  if (isset($st['tp_whole'])) $extra['tp_whole'] = (float)$st['tp_whole'];
  if (isset($st['cb_whole'])) $extra['cb_whole'] = (float)$st['cb_whole'];
}

// 2) Fallback: query tp_whole/cb_whole from DB if not already present
if ((!isset($extra['tp_whole']) || !isset($extra['cb_whole'])) && isset($strategy_id)) {
  $sidQ = (int)$strategy_id;
  $q = $mysqli->query("SELECT tp_whole, cb_whole FROM strategies WHERE id=$sidQ LIMIT 1");
  if ($q && ($row = $q->fetch_assoc())) {
    if (!isset($extra['tp_whole']) && isset($row['tp_whole'])) $extra['tp_whole'] = (float)$row['tp_whole'];
    if (!isset($extra['cb_whole']) && isset($row['cb_whole'])) $extra['cb_whole'] = (float)$row['cb_whole'];
  }
}

// 3) Extract next_buy_price / next_sub_buy_price from the legacy text (best-effort)
$txt_src = '';
if (isset($txt)) $txt_src = (string)$txt;
elseif (isset($text)) $txt_src = (string)$text;

if ($txt_src !== '') {
  // next additional buy (generic)
  if (preg_match('~Наступна .*? буде при ціні не вище:\s*([0-9]+(?:[\\.,][0-9]+)?)~u', $txt_src, $mm)) {
    $v = str_replace(',', '.', $mm[1]);
    if (is_numeric($v)) $extra['next_buy_price'] = (float)$v;
  }
  // first additional position planned (lvl>=5)
  if (preg_match('~Перша додаткова позиція .*? не вище:\s*([0-9]+(?:[\\.,][0-9]+)?)~u', $txt_src, $mm2)) {
    $v2 = str_replace(',', '.', $mm2[1]);
    if (is_numeric($v2)) $extra['next_sub_buy_price'] = (float)$v2;
  }
}

// Build response (keep original keys)
$resp = [['ok'=>false,'error'=>'unauthorized']];
foreach($extra as $k=>$v){ $resp[$k] = $v; }

echo json_encode($resp, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
exit;

}

// admin check (надійно)
$adm = $mysqli->query("SELECT is_admin FROM users WHERE id=$uid LIMIT 1")->fetch_assoc();
if (empty($adm['is_admin'])) {
  echo json_encode(['ok'=>false,'error'=>'forbidden'], JSON_UNESCAPED_UNICODE);
  exit;
}

// strategy_id from REQUEST_URI query (бо QUERY_STRING інколи підміняється)
$q = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY);
$qs = [];
parse_str($q ?? '', $qs);

$strategy_id = isset($qs['strategy_id']) ? (int)$qs['strategy_id'] : 0;


// FORCE_TP_CB_IN_HUMAN_V1: always load tp_whole/cb_whole for this strategy_id
$tp_whole = null; $cb_whole = null;
try {
  $sidQ = (int)$strategy_id;
  $q_tc = $mysqli->query("SELECT tp_whole, cb_whole FROM strategies WHERE id=$sidQ LIMIT 1");
  if ($q_tc && ($r_tc = $q_tc->fetch_assoc())) {
    if (isset($r_tc['tp_whole'])) $tp_whole = (float)$r_tc['tp_whole'];
    if (isset($r_tc['cb_whole'])) $cb_whole = (float)$r_tc['cb_whole'];
  }
} catch (Throwable $e) {}
if ($strategy_id <= 0) {
  // fallback: allow sid=
  $strategy_id = isset($qs['sid']) ? (int)$qs['sid'] : 0;
}
if ($strategy_id <= 0) {
  echo json_encode(['ok'=>false,'error'=>'strategy_id required','qs'=>$qs], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  exit;
}

// стратегія
$st = $mysqli->query("
  SELECT id, user_id, symbol, status, last_tick_at, fba
  FROM strategies
  WHERE id = $strategy_id
  LIMIT 1
")->fetch_assoc() ?: null;

if (!$st) {
  echo json_encode(['ok'=>false,'error'=>'strategy not found'], JSON_UNESCAPED_UNICODE);
  exit;
}

// safety: admin may view any, but still keep data consistent
// позиція
$pos = $mysqli->query("
  SELECT id, strategy_id, qty, avg_price, entry_price, base_price, invested_usdt,
         last_buy, next_ptr, state, updated_at
  FROM positions
  WHERE strategy_id = $strategy_id
  ORDER BY id DESC
  LIMIT 1
")->fetch_assoc() ?: null;

// кількість відкритих SUB
$cnt = 0;

// HUMAN_TEXT_V2
// core_cnt: кількість докупок основної позиції (L1..L4), sub_cnt: кількість додаткових позицій (L5+)
$core_cnt = 0;
$sub_cnt  = 0;
if ($pos && !empty($pos['id'])) {
  $pid0 = (int)$pos['id'];

  $core_cnt = (int)($mysqli->query("
    SELECT COUNT(*) c
    FROM sub_positions
    WHERE position_id = $pid0 AND closed = 0 AND level_idx BETWEEN 1 AND 4
  ")->fetch_assoc()['c'] ?? 0);

  $sub_cnt = (int)($mysqli->query("
    SELECT COUNT(*) c
    FROM sub_positions
    WHERE position_id = $pid0 AND closed = 0 AND level_idx >= 5
  ")->fetch_assoc()['c'] ?? 0);

  // back-compat: open_sub_positions показуємо тільки як кількість додаткових (L5+)
  $cnt = $sub_cnt;
}


if ($pos && !empty($pos['id'])) {
  $cnt = (int)($mysqli->query("
    SELECT COUNT(*) c
    FROM sub_positions
    WHERE position_id = " . (int)$pos['id'] . " AND closed = 0
  ")->fetch_assoc()['c'] ?? 0);
}

// Розрахунок наступної покупки за “якірною” логікою:
//  - L1..L4: від base_price (BUY0 якір)
//  - L5: від avg_price ядра
//  - L6+: від buy_price попередньої SUB (ланцюжок)
$next = null;
$next_anchor = null;
$next_lvl = null;
$sub5_plan = null;

if ($pos && isset($pos['next_ptr'])) {
  $lvl = (int)$pos['next_ptr'];
  $next_lvl = $lvl;

  // drop% для next level
  $drop = null;
  $lr = $mysqli->query("
    SELECT drop_pct
    FROM strategy_levels
    WHERE strategy_id = $strategy_id AND lvl_idx = $lvl
    LIMIT 1
  ")->fetch_assoc();
  if ($lr && isset($lr['drop_pct'])) $drop = (float)$lr['drop_pct'];

  // план L5 від avg ядра
  $lr5 = $mysqli->query("
    SELECT drop_pct
    FROM strategy_levels
    WHERE strategy_id = $strategy_id AND lvl_idx = 5
    LIMIT 1
  ")->fetch_assoc();
  if ($lr5 && isset($lr5['drop_pct']) && (float)$pos['avg_price'] > 0) {
    $sub5_plan = round((float)$pos['avg_price'] * (1 - ((float)$lr5['drop_pct']/100)), 8);
  }

  if ($drop !== null) {
    $anchor = 0.0;

    if ($lvl <= 4) {
      $anchor = (float)($pos['base_price'] ?? 0);
      $next_anchor = 'base_price';
    } elseif ($lvl == 5) {
      $anchor = (float)($pos['avg_price'] ?? 0);
      $next_anchor = 'avg_price';
    } else {
      $prevLvl = $lvl - 1;
      $rr = $mysqli->query("
        SELECT buy_price
        FROM sub_positions
        WHERE position_id = " . (int)$pos['id'] . " AND level_idx = $prevLvl
        ORDER BY id DESC
        LIMIT 1
      ")->fetch_assoc();
      if ($rr && isset($rr['buy_price'])) {
        $anchor = (float)$rr['buy_price'];
        $next_anchor = 'prev_sub_buy_price';
      }
    }

    if ($anchor > 0) {
      $next = round($anchor * (1 - ($drop/100)), 8);
    }
  }
}

// Людський блок (без скорочень)
$human = null;
if ($pos) {
  $sym = (string)($st['symbol'] ?? '');
  $lastTick = $st['last_tick_at'] ?? null;

  $qty = (float)($pos['qty'] ?? 0);
  $avg = (float)($pos['avg_price'] ?? 0);
  $entry = (float)($pos['entry_price'] ?? 0);
  $base = (float)($pos['base_price'] ?? 0);

  // Скільки рівнів основної позиції відкрито (BUY0 + L1..L4) — грубо по next_ptr
  $core_open = 0;
  if (isset($pos['next_ptr'])) {
    $core_open = max(0, min((int)$pos['next_ptr'], 5));
  }

  $human = [
    // FIX_TP_CB_TO_HUMAN_V1
    'tp_whole' => $extra['tp_whole'] ?? null,
    'cb_whole' => $extra['cb_whole'] ?? null,

    'pair' => $sym,
    'status' => ($st['status'] ?? ''),
    'tp_whole' => ($extra['tp_whole'] ?? null),
'cb_whole' => ($extra['cb_whole'] ?? null),
'last_update' => $lastTick,
    'position_open' => ($qty > 0),
    'qty' => $qty,
    'avg_price_logic' => $avg,
    'entry_price_binance' => $entry,
    'base_price_anchor' => $base,
    'open_core_levels' => $core_open,
    'open_core_levels_total' => 5,
    'open_sub_positions' => (int)$cnt,
    'next_level' => $next_lvl,
    'next_buy_price' => $next,
    'next_buy_anchor' => $next_anchor,
    'plan_first_sub_price_L5' => $sub5_plan,
    'fba' => ($st['fba'] ?? null),
    'ui_text' => [
      'title'   => "Торгова пара: {$sym}",
      'updated' => $lastTick ? "Останнє оновлення: {$lastTick}" : "Останнє оновлення: —",
      'qty'     => "Придбано активу: " . ($qty > 0 ? number_format($qty, 6, '.', '') : '0'),
      'avg'     => "Середня ціна входу (для логіки): " . ($avg > 0 ? number_format($avg, 6, '.', '') . " USDT" : '—'),
      'entry'   => "Вхідна ціна (як на біржі): " . ($entry > 0 ? number_format($entry, 6, '.', '') . " USDT" : '—'),
      'levels'  => "Основна позиція: {$core_open} з 5 рівнів. Докупок основної позиції виконано: {$core_cnt} з 4. Додаткових позицій (рівень 5 і вище): {$sub_cnt}.",
      'next_buy'=> $next ? ( ((int)$next_lvl <= 4) ? ("Наступна докупка основної позиції (рівень {$next_lvl}) буде при ціні не вище: " . number_format($next, 6, '.', '') . " USDT") : ("Наступна додаткова позиція (рівень {$next_lvl}) буде при ціні не вище: " . number_format($next, 6, '.', '') . " USDT") ) : "Наступна покупка: —",
      'sub5'    => ($sub5_plan && (int)$next_lvl < 5) ? ("Перша додаткова позиція (рівень 5) запланована при ціні не вище: " . number_format($sub5_plan, 6, '.', '') . " USDT") : "",
    ],
  ];

// FORCE_TP_CB_IN_HUMAN_V1: ensure human has tp/cb whole (override any nulls)
$human['tp_whole'] = $tp_whole;
$human['cb_whole'] = $cb_whole;

}

echo json_encode([
  'ok' => true,
  'strategy' => $st,
  'position' => $pos,
  'human' => $human,
  'open_sub_positions' => (int)$cnt,
  'next_buy_price_est' => $next,
  'next_buy_anchor' => $next_anchor,
  'plan_first_sub_price_L5' => $sub5_plan,
  'ts' => date('Y-m-d H:i:s')
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
