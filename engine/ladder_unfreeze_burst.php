<?php
declare(strict_types=1);

/**
 * Burst докупок після SPOT_FUNDS_UNFROZEN (варіант A).
 *
 * Лише на тіку розморозки (pending per user): синтетична сітка від останнього відкритого
 * sub-рівня; купуємо пропущені рівні з spot < trigger < buy останнього рівня.
 *
 * sub_positions.buy_price = фактичний fill (як звичайна докупка). Уявний тригер лишається
 * лише для плану burst / журналу — НЕ для TP і НЕ для якоря наступних рівнів.
 * (Раніше писали imag trigger у buy_price → інверсія L7/L8/… і завищені SUB TP.)
 */

function ladder_unfreeze_burst_enabled(): bool
{
    $v = $_ENV['UNFREEZE_BURST_ENABLE'] ?? getenv('UNFREEZE_BURST_ENABLE');
    if ($v === false || $v === null || $v === '') {
        return true;
    }
    return (string)$v === '1';
}

function ladder_unfreeze_burst_pending_key(int $userId): string
{
    return 'unfreeze_burst_pending_u' . max(0, (int)$userId);
}

function ladder_unfreeze_burst_set_pending(mysqli $db, int $userId): void
{
    $userId = (int)$userId;
    if ($userId <= 0) {
        return;
    }
    $payload = json_encode([
        'active' => 1,
        'at' => date('Y-m-d H:i:s'),
        'done_sids' => [],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($payload)) {
        return;
    }
    $k = ladder_unfreeze_burst_pending_key($userId);
    $stmt = $db->prepare(
        'INSERT INTO app_settings (k, v) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE v = VALUES(v), updated_at = CURRENT_TIMESTAMP'
    );
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('ss', $k, $payload);
    $stmt->execute();
    $stmt->close();
}

/** @return array{active:bool,at:?string,done_sids:list<int>} */
function ladder_unfreeze_burst_pending_load(mysqli $db, int $userId): array
{
    $empty = ['active' => false, 'at' => null, 'done_sids' => []];
    $userId = (int)$userId;
    if ($userId <= 0) {
        return $empty;
    }
    $k = $db->real_escape_string(ladder_unfreeze_burst_pending_key($userId));
    $r = $db->query("SELECT v FROM app_settings WHERE k='{$k}' LIMIT 1");
    if (!$r || !($row = $r->fetch_assoc())) {
        return $empty;
    }
    $j = json_decode((string)($row['v'] ?? ''), true);
    if (!is_array($j) || empty($j['active'])) {
        return $empty;
    }
    $done = [];
    if (!empty($j['done_sids']) && is_array($j['done_sids'])) {
        foreach ($j['done_sids'] as $s) {
            $sid = (int)$s;
            if ($sid > 0) {
                $done[] = $sid;
            }
        }
    }
    return [
        'active' => true,
        'at' => isset($j['at']) ? (string)$j['at'] : null,
        'done_sids' => $done,
    ];
}

function ladder_unfreeze_burst_clear_pending(mysqli $db, int $userId): void
{
    $userId = (int)$userId;
    if ($userId <= 0) {
        return;
    }
    $k = $db->real_escape_string(ladder_unfreeze_burst_pending_key($userId));
    $db->query("DELETE FROM app_settings WHERE k='{$k}' LIMIT 1");
}

function ladder_unfreeze_burst_should_run(mysqli $db, int $userId, int $sid): bool
{
    if (!ladder_unfreeze_burst_enabled()) {
        return false;
    }
    $p = ladder_unfreeze_burst_pending_load($db, $userId);
    if (!$p['active']) {
        return false;
    }
    return !in_array((int)$sid, $p['done_sids'], true);
}

function ladder_unfreeze_burst_mark_strategy_done(mysqli $db, int $userId, int $sid): void
{
    $userId = (int)$userId;
    $sid = (int)$sid;
    if ($userId <= 0 || $sid <= 0) {
        return;
    }
    $p = ladder_unfreeze_burst_pending_load($db, $userId);
    if (!$p['active']) {
        return;
    }
    $done = $p['done_sids'];
    if (!in_array($sid, $done, true)) {
        $done[] = $sid;
    }
    $payload = json_encode([
        'active' => 1,
        'at' => $p['at'] ?? date('Y-m-d H:i:s'),
        'done_sids' => $done,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($payload)) {
        return;
    }
    $k = ladder_unfreeze_burst_pending_key($userId);
    $stmt = $db->prepare(
        'INSERT INTO app_settings (k, v) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE v = VALUES(v), updated_at = CURRENT_TIMESTAMP'
    );
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('ss', $k, $payload);
    $stmt->execute();
    $stmt->close();

    $rs = $db->query(
        "SELECT id FROM strategies
         WHERE (user_id={$userId} OR owner_id={$userId}) AND status='running'"
    );
    $running = [];
    if ($rs) {
        while ($row = $rs->fetch_assoc()) {
            $running[] = (int)($row['id'] ?? 0);
        }
    }
    $allDone = true;
    foreach ($running as $rid) {
        if ($rid > 0 && !in_array($rid, $done, true)) {
            $allDone = false;
            break;
        }
    }
    if ($allDone) {
        ladder_unfreeze_burst_clear_pending($db, $userId);
    }
}

/**
 * Синтетичні тригери від buy останнього відкритого рівня (каскад trigger→anchor).
 *
 * @return array<int,float> lvl_idx => trigger
 */
function ladder_burst_synthetic_triggers(float $anchorBuy, int $fromLvl, array $levels, int $maxLvl = 70): array
{
    $out = [];
    if ($anchorBuy <= 0.0 || $fromLvl < 1) {
        return $out;
    }
    $anchor = $anchorBuy;
    for ($lvl = $fromLvl; $lvl <= $maxLvl; $lvl++) {
        if (!isset($levels[$lvl]) || !is_array($levels[$lvl])) {
            break;
        }
        $drop = (float)($levels[$lvl]['drop'] ?? 0.0);
        $trg = $anchor * (1.0 - $drop / 100.0);
        if ($trg <= 0.0) {
            break;
        }
        $out[$lvl] = $trg;
        $anchor = $trg;
    }
    return $out;
}

/**
 * Рівні для burst: spot < trigger < anchorBuy, ще не відкриті.
 *
 * @param array<int,bool> $openLvls
 * @return list<array{lvl:int,trigger:float,mult:float,quote:float}>
 */
function ladder_burst_plan_levels(
    float $spot,
    float $anchorBuy,
    int $maxOpenLvl,
    array $levels,
    array $openLvls,
    float $fba
): array {
    $plan = [];
    if ($spot <= 0.0 || $anchorBuy <= 0.0 || $maxOpenLvl < 1 || $fba <= 0.0) {
        return $plan;
    }
    // Лише один tip за раз (maxOpen+1): без «пилкової» хвилі L7…L30 по одній ціні.
    $lvl = $maxOpenLvl + 1;
    if (isset($openLvls[$lvl])) {
        return $plan;
    }
    $triggers = ladder_burst_synthetic_triggers($anchorBuy, $lvl, $levels);
    $trg = isset($triggers[$lvl]) ? (float)$triggers[$lvl] : 0.0;
    if ($trg <= 0.0 || !($spot < $trg && $trg < $anchorBuy)) {
        return $plan;
    }
    $lv = $levels[$lvl] ?? null;
    if (!is_array($lv)) {
        return $plan;
    }
    $drop = (float)($lv['drop'] ?? 0.0);
    $gapPct = function_exists('ladder_tip_min_gap_pct') ? ladder_tip_min_gap_pct($drop) : 0.35;
    $needBelow = $anchorBuy * (1.0 - $gapPct / 100.0);
    if ($spot >= $needBelow) {
        return $plan;
    }
    $mult = (float)($lv['mult'] ?? 1.0);
    if ($mult <= 0.0) {
        $mult = 1.0;
    }
    $plan[] = [
        'lvl' => $lvl,
        'trigger' => $trg,
        'mult' => $mult,
        'quote' => $fba * $mult,
    ];
    return $plan;
}

/**
 * Dry-run план burst (readonly).
 *
 * @return array{ok:bool,reason:string,anchor_lvl:int,anchor_buy:float,spot:float,plan:list<array{lvl:int,trigger:float,quote:float,tp_target:float}>}
 */
function ladder_unfreeze_burst_plan_readonly(
    mysqli $db,
    int $sid,
    float $spot,
    float $fba
): array {
    $fail = static function (string $reason): array {
        return [
            'ok' => false,
            'reason' => $reason,
            'anchor_lvl' => 0,
            'anchor_buy' => 0.0,
            'spot' => 0.0,
            'plan' => [],
        ];
    };

    $sid = (int)$sid;
    if ($sid <= 0) {
        return $fail('bad sid');
    }
    $rs = $db->query("SELECT user_id, owner_id FROM strategies WHERE id={$sid} LIMIT 1");
    if (!$rs || !($st = $rs->fetch_assoc())) {
        return $fail('strategy not found');
    }
    $uid = (int)($st['user_id'] ?? 0);
    if ($uid <= 0) {
        $uid = (int)($st['owner_id'] ?? 0);
    }
    $pos = get_position($db, $sid, '', $uid);
    if (!$pos) {
        return $fail('no position');
    }
    $pid = (int)($pos['id'] ?? 0);
    $qty = (float)($pos['qty'] ?? 0);
    if ($pid <= 0 || $qty <= 0.0) {
        return $fail('flat position');
    }
    $maxLvl = position_max_open_level_idx($db, $pid);
    if ($maxLvl < 1) {
        return $fail('no open sub levels');
    }
    $q = $db->query(
        "SELECT buy_price FROM sub_positions
         WHERE position_id={$pid} AND level_idx={$maxLvl} AND closed=0
         ORDER BY id DESC LIMIT 1"
    );
    if (!$q || !($row = $q->fetch_assoc())) {
        return $fail('anchor sub missing');
    }
    $anchorBuy = (float)($row['buy_price'] ?? 0);
    if ($anchorBuy <= 0.0) {
        return $fail('anchor buy invalid');
    }
    $levels = load_strategy_levels($db, $sid);
    if (!$levels) {
        return $fail('no levels');
    }
    ksort($levels);
    $openSubs = open_subpos($db, $pid, null);
    $openLvls = [];
    if ($openSubs && is_array($openSubs)) {
        foreach ($openSubs as $sp) {
            if (!empty($sp['closed'])) {
                continue;
            }
            $li = (int)($sp['level_idx'] ?? 0);
            if ($li >= 1) {
                $openLvls[$li] = true;
            }
        }
    }
    $rawPlan = ladder_burst_plan_levels($spot, $anchorBuy, $maxLvl, $levels, $openLvls, $fba);
    $plan = [];
    foreach ($rawPlan as $it) {
        $lvl = (int)$it['lvl'];
        $trg = (float)$it['trigger'];
        $tpPct = (float)($levels[$lvl]['tp'] ?? 0);
        $plan[] = [
            'lvl' => $lvl,
            'trigger' => $trg,
            'quote' => (float)$it['quote'],
            'tp_target' => $trg * (1.0 + $tpPct / 100.0),
        ];
    }
    return [
        'ok' => true,
        'reason' => count($plan) ? 'ready' : 'no_burst_levels',
        'anchor_lvl' => $maxLvl,
        'anchor_buy' => $anchorBuy,
        'spot' => $spot,
        'plan' => $plan,
    ];
}

/**
 * Виконати burst (MARKET BUY по черзі). Повертає кількість успішних рівнів.
 */
function ladder_unfreeze_burst_execute(
    mysqli $db,
    int $sid,
    string $symbol,
    int $uid,
    array $pos,
    float $price,
    float $fba,
    float $tpw,
    float $cbw,
    string $apiKey,
    string $secret
): int {
    if (!ladder_unfreeze_burst_enabled()) {
        return 0;
    }
    $te = $_ENV['TRADING_ENABLED'] ?? getenv('TRADING_ENABLED') ?? '0';
    if ((string)$te !== '1') {
        logx("[STRAT-$sid][$symbol] UNFREEZE_BURST SKIP TRADING_ENABLED!=1");
        return 0;
    }
    if (!$pos) {
        return 0;
    }
    $pid = (int)($pos['id'] ?? 0);
    $qty = (float)($pos['qty'] ?? 0);
    $base = (float)($pos['base_price'] ?? 0);
    if ($pid <= 0 || $qty <= 0.0 || $base <= 0.0 || $fba <= 0.0 || $price <= 0.0) {
        logx("[STRAT-$sid][$symbol] UNFREEZE_BURST SKIP no pos/base/fba/price");
        return 0;
    }

    $maxLvl = position_max_open_level_idx($db, $pid);
    if ($maxLvl < 1) {
        logx("[STRAT-$sid][$symbol] UNFREEZE_BURST SKIP no open subs");
        return 0;
    }
    $q = $db->query(
        "SELECT buy_price FROM sub_positions
         WHERE position_id={$pid} AND level_idx={$maxLvl} AND closed=0
         ORDER BY id DESC LIMIT 1"
    );
    if (!$q || !($row = $q->fetch_assoc())) {
        logx("[STRAT-$sid][$symbol] UNFREEZE_BURST SKIP anchor L{$maxLvl} missing");
        return 0;
    }
    $anchorBuy = (float)($row['buy_price'] ?? 0);
    if ($anchorBuy <= 0.0) {
        return 0;
    }

    $levels = load_strategy_levels($db, $sid);
    if (!$levels) {
        return 0;
    }
    ksort($levels);

    $openSubs = open_subpos($db, $pid, null);
    $openLvls = [];
    if ($openSubs && is_array($openSubs)) {
        foreach ($openSubs as $sp) {
            if (!empty($sp['closed'])) {
                continue;
            }
            $li = (int)($sp['level_idx'] ?? 0);
            if ($li >= 1) {
                $openLvls[$li] = true;
            }
        }
    }

    $plan = ladder_burst_plan_levels($price, $anchorBuy, $maxLvl, $levels, $openLvls, $fba);
    if (!count($plan)) {
        logx(
            "[STRAT-$sid][$symbol] UNFREEZE_BURST none spot=%s anchorL%d buy=%s",
            (string)$price,
            $maxLvl,
            (string)$anchorBuy
        );
        $symEsc = $db->real_escape_string($symbol);
        $msg = sprintf(
            'Burst після розморозки: пропущених рівнів у діапазоні spot<trigger<buy L%d немає (spot≈%.6f, anchor≈%.6f).',
            $maxLvl,
            $price,
            $anchorBuy
        );
        $msgEsc = $db->real_escape_string(mb_substr($msg, 0, 2000));
        $db->query(
            "INSERT INTO engine_journal (created_at, strategy_id, symbol, event_type, level_idx, side, message)
             VALUES (NOW(), {$sid}, '{$symEsc}', 'UNFREEZE_BURST', NULL, NULL, '{$msgEsc}')"
        );
        return 0;
    }

    $lvlList = array_map(static fn($x) => 'L' . (int)$x['lvl'], $plan);
    logx(
        "[STRAT-$sid][$symbol] UNFREEZE_BURST start anchor=L{$maxLvl} buy={$anchorBuy} spot={$price} plan=" . implode(',', $lvlList)
    );

    $symEsc = $db->real_escape_string($symbol);
    $planTxt = implode(', ', $lvlList);
    $startMsg = sprintf(
        'Burst після розморозки: %d рівнів (%s). Якір L%d buy≈%.6f, spot≈%.6f. buy_price=fill, TP від fill.',
        count($plan),
        $planTxt,
        $maxLvl,
        $anchorBuy,
        $price
    );
    $startEsc = $db->real_escape_string(mb_substr($startMsg, 0, 2000));
    $db->query(
        "INSERT INTO engine_journal (created_at, strategy_id, symbol, event_type, level_idx, side, message)
         VALUES (NOW(), {$sid}, '{$symEsc}', 'UNFREEZE_BURST', NULL, NULL, '{$startEsc}')"
    );

    $bought = 0;
    foreach ($plan as $it) {
        $lvlIdx = (int)$it['lvl'];
        $imagTrg = (float)$it['trigger'];
        $mult = (float)$it['mult'];
        $quote = (float)$it['quote'];
        $qNorm = exi_normalize_quote_buy($symbol, $quote, $apiKey, $secret);
        if ($qNorm <= 0.0) {
            logx("[STRAT-$sid][$symbol] UNFREEZE_BURST L{$lvlIdx} SKIP quote norm=0");
            continue;
        }
        if (!function_exists('ladder_funds_snapshot')) {
            break;
        }
        [$freeU, $minN] = ladder_funds_snapshot($symbol, $apiKey, $secret);
        if (!function_exists('profit_reinvest_buyable_usdt')) {
            require_once __DIR__ . '/profit_reinvest.php';
        }
        $buyableU = function_exists('profit_reinvest_buyable_usdt')
            ? profit_reinvest_buyable_usdt($db, $uid, (float)$freeU)
            : (float)$freeU;
        $needU = spot_funds_min_usdt_to_trade($fba, $minN);
        if ($buyableU + 1e-6 < max($qNorm, $needU)) {
            logx(
                "[STRAT-$sid][$symbol] UNFREEZE_BURST L{$lvlIdx} STOP funds free=%s buyable=%s need>=%s quote=%s",
                (string)$freeU,
                (string)$buyableU,
                (string)max($qNorm, $needU),
                (string)$qNorm
            );
            break;
        }

        $qtyBefore = (float)($pos['qty'] ?? $qty);
        if (function_exists('ladder_tip_buy_price_ok')) {
            $gapChk = ladder_tip_buy_price_ok($db, $pid, $lvlIdx, (float)$price, $levels, $pos);
            if (empty($gapChk['ok'])) {
                logx(
                    "[STRAT-$sid][$symbol] UNFREEZE_BURST L{$lvlIdx} SKIP ladder_gap: "
                    . (string)($gapChk['reason'] ?? '')
                );
                break;
            }
        }
        [$h, $r, $e, $er] = place_quote_buy($symbol, $qNorm, $apiKey, $secret);
        if ($e || $h >= 400) {
            logx("[STRAT-$sid][$symbol] UNFREEZE_BURST L{$lvlIdx} ERR http=$h body=$r");
            if (function_exists('binance_response_likely_insufficient_funds')
                && binance_response_likely_insufficient_funds((string)$r)) {
                break;
            }
            continue;
        }

        // Fill з ордера; fallback — spot тіка. Не підставляти imagTrg у buy_price.
        $fillPx = (float)$price;
        $ordTmp = json_decode((string)$r, true);
        if (is_array($ordTmp)) {
            if (!empty($ordTmp['fills']) && is_array($ordTmp['fills'])) {
                $sum = 0.0;
                $w = 0.0;
                foreach ($ordTmp['fills'] as $f) {
                    $fp = (float)($f['price'] ?? 0);
                    $fq = (float)($f['qty'] ?? 0);
                    if ($fp > 0.0 && $fq > 0.0) {
                        $sum += $fp * $fq;
                        $w += $fq;
                    }
                }
                if ($w > 1e-12) {
                    $fillPx = $sum / $w;
                }
            } elseif (
                isset($ordTmp['cummulativeQuoteQty'], $ordTmp['executedQty'])
                && (float)$ordTmp['executedQty'] > 1e-12
            ) {
                $fillPx = (float)$ordTmp['cummulativeQuoteQty'] / (float)$ordTmp['executedQty'];
            }
        }
        if ($fillPx <= 1e-12) {
            $fillPx = (float)$price;
        }

        engine_ladder_apply_successful_buy(
            $db,
            $sid,
            $symbol,
            $uid,
            $lvlIdx,
            $fillPx,
            $qNorm,
            (string)$r,
            $qtyBefore,
            $base,
            $imagTrg,
            $levels,
            $tpw,
            $cbw,
            $fba,
            $mult,
            $apiKey,
            $secret,
            'UNFREEZE_BURST',
            null
        );

        $bought++;
        $pos = ensure_position($db, $sid, $uid) ?: $pos;
        $qty = (float)($pos['qty'] ?? $qty);

        $lv = $levels[$lvlIdx] ?? [];
        $tpPct = (float)($lv['tp'] ?? $tpw);
        $fillNote = sprintf(
            'Burst L%d: fill≈%.6f, план-тригер≈%.6f, TP від fill≈%.6f (+%.2f%%).',
            $lvlIdx,
            $fillPx,
            $imagTrg,
            $fillPx * (1.0 + $tpPct / 100.0),
            $tpPct
        );
        $fillEsc = $db->real_escape_string(mb_substr($fillNote, 0, 2000));
        $db->query(
            "INSERT INTO engine_journal (created_at, strategy_id, symbol, event_type, level_idx, side, message)
             VALUES (NOW(), {$sid}, '{$symEsc}', 'UNFREEZE_BURST', {$lvlIdx}, 'BUY', '{$fillEsc}')"
        );
    }

    logx("[STRAT-$sid][$symbol] UNFREEZE_BURST done bought={$bought}/" . count($plan));
    return $bought;
}

/**
 * Read-only звіт burst для всіх стратегій користувача (адмін / CLI).
 *
 * @return array<string,mixed>
 */
function ladder_user_unfreeze_burst_report(mysqli $db, int $uid, float $extraUsdt = 0.0): array
{
    $uid = (int)$uid;
    $extraUsdt = (float)$extraUsdt;
    if ($uid <= 0) {
        return ['ok' => false, 'error' => 'invalid_user_id'];
    }

    $u = $db->query("SELECT id, nickname, full_name, email FROM users WHERE id={$uid} LIMIT 1");
    if (!$u || !($user = $u->fetch_assoc())) {
        return ['ok' => false, 'error' => 'user_not_found'];
    }

    if (!function_exists('user_keys')) {
        $keysFile = dirname(__DIR__) . '/www/api/_user_keys.php';
        if (is_file($keysFile)) {
            require_once $keysFile;
        }
    }
    $apiKey = '';
    $secret = '';
    if (function_exists('user_keys')) {
        [$apiKey, $secret] = user_keys($db, $uid);
    }

    $freeze = spot_funds_freeze_state($db, $uid);

    $out = [
        'ok' => true,
        'generated_at' => date('Y-m-d H:i:s'),
        'user' => $user,
        'frozen' => $freeze,
        'extra_usdt_simulated' => $extraUsdt,
        'strategies' => [],
        'totals' => [
            'burst_levels' => 0,
            'burst_usdt_needed' => 0.0,
            'fits_in_extra_usdt' => null,
        ],
    ];

    $rs = $db->query(
        "SELECT id, symbol, status, fba, tp_whole, cb_whole
         FROM strategies
         WHERE user_id={$uid} AND UPPER(symbol) <> 'TEMPLATE'
         ORDER BY id ASC"
    );
    while ($rs && ($st = $rs->fetch_assoc())) {
        $sid = (int)($st['id'] ?? 0);
        $sym = strtoupper((string)($st['symbol'] ?? ''));
        $status = (string)($st['status'] ?? '');
        $fba = (float)($st['fba'] ?? 0);

        $row = [
            'sid' => $sid,
            'symbol' => $sym,
            'status' => $status,
            'fba' => $fba,
            'spot' => null,
            'position' => null,
            'burst' => null,
            'open_levels' => [],
            'missed_queue' => [],
            'note' => '',
        ];

        if ($status !== 'running') {
            $row['note'] = 'not_running';
            $out['strategies'][] = $row;
            continue;
        }

        $spot = 0.0;
        if ($sym !== '' && function_exists('ws_ticker_price')) {
            $spot = (float)ws_ticker_price($sym);
        }
        if ($spot <= 0.0 && $sym !== '' && $apiKey !== '' && $secret !== '' && function_exists('exi_get_price')) {
            $spot = (float)exi_get_price($sym, $apiKey, $secret);
        }
        $row['spot'] = $spot > 0 ? $spot : null;

        if (!function_exists('get_position')) {
            $logicFile = __DIR__ . '/engine_logic.php';
            if (is_file($logicFile)) {
                require_once $logicFile;
            }
        }

        $pos = function_exists('get_position') ? get_position($db, $sid, $sym, $uid) : null;
        if ($pos) {
            $pid = (int)($pos['id'] ?? 0);
            $row['position'] = [
                'qty' => (float)($pos['qty'] ?? 0),
                'avg_price' => (float)($pos['avg_price'] ?? 0),
                'base_price' => (float)($pos['base_price'] ?? 0),
                'max_open_lvl' => $pid > 0 ? position_max_open_level_idx($db, $pid) : 0,
            ];
            if ($pid > 0 && function_exists('ladder_missed_load')) {
                $row['missed_queue'] = ladder_missed_load($db, $pid);
            }
            if ($pid > 0 && function_exists('open_subpos')) {
                $subs = open_subpos($db, $pid, null);
                if ($subs && is_array($subs)) {
                    foreach ($subs as $sp) {
                        if (!empty($sp['closed'])) {
                            continue;
                        }
                        $li = (int)($sp['level_idx'] ?? 0);
                        if ($li >= 1) {
                            $row['open_levels'][] = [
                                'lvl' => $li,
                                'buy_price' => (float)($sp['buy_price'] ?? 0),
                                'qty' => (float)($sp['qty'] ?? 0),
                            ];
                        }
                    }
                }
            }
        }

        if ($spot > 0 && $fba > 0) {
            $row['burst'] = ladder_unfreeze_burst_plan_readonly($db, $sid, $spot, $fba);
            if (!empty($row['burst']['plan']) && is_array($row['burst']['plan'])) {
                foreach ($row['burst']['plan'] as $p) {
                    $out['totals']['burst_levels']++;
                    $out['totals']['burst_usdt_needed'] += (float)($p['quote'] ?? 0);
                }
            }
        } else {
            $row['burst'] = ['ok' => false, 'reason' => 'no_spot_or_fba', 'plan' => []];
        }

        $out['strategies'][] = $row;
    }

    $freeNow = null;
    if ($apiKey !== '' && $secret !== '' && function_exists('ladder_funds_snapshot')) {
        [$freeNow] = ladder_funds_snapshot('BTCUSDT', $apiKey, $secret);
    }
    $out['free_usdt_now_approx'] = $freeNow;
    $out['free_usdt_after_topup_sim'] = $freeNow !== null ? $freeNow + $extraUsdt : null;
    $need = (float)$out['totals']['burst_usdt_needed'];
    if ($extraUsdt > 0) {
        $out['totals']['fits_in_extra_usdt'] = $need <= $extraUsdt + 1e-6;
    } elseif ($freeNow !== null) {
        $out['totals']['fits_in_extra_usdt'] = $need <= $freeNow + 1e-6;
    }

    return $out;
}
