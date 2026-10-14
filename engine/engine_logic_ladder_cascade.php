<?php
declare(strict_types=1);

/**
 * Каскадна геометрія докупок (ENGINE_LADDER_MODE=cascade).
 *
 * Купівля (якір = попередній рівень):
 *   L1: тригер від BUY0 (base_price), drop/mult з strategy_levels (як у старій таблиці 1..70).
 *   Ln (n>=2): якір = остання фактична buy_price рівня L(n-1), якщо ще не купували — «віртуальна»
 *   сходинка = synthetic chain L1..L(n-1) від base (послідовне застосування drop L1..L(n-1)).
 *   Тригер Ln = якір * (1 - drop_Ln/100).
 *
 * Купівлі FBA*mult — без змін: place_quote_buy / strategy_levels.mult як у legacy.
 *
 * Продаж (без змін у цьому файлі — залишаються handle_sub_sells + handle_main_sell_core):
 *   L5+ — по tp% кожного рядка sub_positions (як зараз).
 *   L1–L4 + ядро — MAIN SELL по core basis + tp_whole (+ CB trailing), закриття sub L<=4 як зараз.
 *
 * Перемикач (пріоритет):
 *   1) app_settings.k = engine_ladder_mode, v = cascade | parallel (кнопка в адмінці)
 *   2) інакше .env / getenv ENGINE_LADDER_MODE
 */

const LADDER_APP_SETTING_KEY = 'engine_ladder_mode';

/**
 * @return array{mode:string,source:string} mode: cascade|parallel, source: database|environment
 */
function ladder_cascade_resolve_mode(?mysqli $db): array {
    $envRaw = (string)(getenv('ENGINE_LADDER_MODE') ?: ($_ENV['ENGINE_LADDER_MODE'] ?? 'parallel'));
    $envM = strtolower(trim($envRaw));
    $envEff = $envM === 'cascade' ? 'cascade' : 'parallel';
    if (!($db instanceof mysqli)) {
        return ['mode' => $envEff, 'source' => 'environment'];
    }
    $k = $db->real_escape_string(LADDER_APP_SETTING_KEY);
    $r = $db->query("SELECT v FROM app_settings WHERE k='$k' LIMIT 1");
    if ($r && ($row = $r->fetch_assoc())) {
        $v = strtolower(trim((string)($row['v'] ?? '')));
        if ($v === 'cascade' || $v === 'parallel') {
            return ['mode' => $v, 'source' => 'database'];
        }
    }
    return ['mode' => $envEff, 'source' => 'environment'];
}

function ladder_cascade_effective_mode(?mysqli $db = null): string {
    return ladder_cascade_resolve_mode($db)['mode'];
}

function ladder_cascade_mode_active(?mysqli $db = null): bool {
    return ladder_cascade_effective_mode($db) === 'cascade';
}

/**
 * Ті самі рівні, що load_strategy_levels() у engine_logic.php (strategy_id потім fallback 0).
 *
 * @return array<int, array{drop:float,mult:float,tp:float,cb:float}>
 */
function ladder_cascade_load_strategy_levels(mysqli $db, int $sid): array {
    $sid = (int)$sid;
    $levels = [];
    $q1 = "SELECT lvl_idx, drop_pct, mult, tp_pct, cb_pct
           FROM strategy_levels
           WHERE strategy_id=$sid
           ORDER BY lvl_idx ASC";
    $r1 = $db->query($q1);
    if ($r1 && $r1->num_rows > 0) {
        while ($row = $r1->fetch_assoc()) {
            $idx = (int)$row['lvl_idx'];
            $levels[$idx] = [
                'drop' => (float)$row['drop_pct'],
                'mult' => (float)$row['mult'],
                'tp'   => (float)$row['tp_pct'],
                'cb'   => (float)$row['cb_pct'],
            ];
        }
        return $levels;
    }
    $r2 = $db->query("SELECT lvl_idx, drop_pct, mult, tp_pct, cb_pct
           FROM strategy_levels
           WHERE strategy_id=0
           ORDER BY lvl_idx ASC");
    if ($r2) {
        while ($row = $r2->fetch_assoc()) {
            $idx = (int)$row['lvl_idx'];
            $levels[$idx] = [
                'drop' => (float)$row['drop_pct'],
                'mult' => (float)$row['mult'],
                'tp'   => (float)$row['tp_pct'],
                'cb'   => (float)$row['cb_pct'],
            ];
        }
    }
    return $levels;
}

function ladder_cascade_last_buy_price(mysqli $db, int $pid, int $lvl): float {
    $pid = (int)$pid;
    $lvl = (int)$lvl;
    if ($pid <= 0 || $lvl < 1) {
        return 0.0;
    }
    $q = $db->query(
        "SELECT buy_price FROM sub_positions WHERE position_id=$pid AND level_idx=$lvl AND closed=0 ORDER BY id DESC LIMIT 1"
    );
    if ($q && ($row = $q->fetch_assoc())) {
        return (float)($row['buy_price'] ?? 0.0);
    }
    return 0.0;
}

/**
 * Віртуальна ціна «на сходинці» L..$throughLevel, якщо всі попередні купівлі відбулися рівно на тригерах від base.
 */
function ladder_cascade_synthetic_through(float $base, array $levels, int $throughLevel): float {
    if ($base <= 0.0 || $throughLevel < 1) {
        return 0.0;
    }
    $p = $base;
    for ($i = 1; $i <= $throughLevel; $i++) {
        $lv = $levels[$i] ?? null;
        if (!is_array($lv)) {
            return 0.0;
        }
        $drop = isset($lv['drop']) ? (float)$lv['drop'] : 0.0;
        $p *= (1.0 - $drop / 100.0);
    }
    return $p;
}

/**
 * Якір для рівня $prevLvl (для тригера L(prev+1)).
 */
function ladder_cascade_anchor_prev(mysqli $db, int $pid, float $base, array $levels, int $prevLvl): float {
    if ($prevLvl < 1) {
        return 0.0;
    }
    if ($prevLvl === 1) {
        $bp1 = ladder_cascade_last_buy_price($db, $pid, 1);
        if ($bp1 > 0.0) {
            return $bp1;
        }
        return ladder_cascade_synthetic_through($base, $levels, 1);
    }
    $bp = ladder_cascade_last_buy_price($db, $pid, $prevLvl);
    if ($bp > 0.0) {
        return $bp;
    }
    return ladder_cascade_synthetic_through($base, $levels, $prevLvl);
}

/**
 * @return array<int,float> lvl_idx => trigger_price
 */
function ladder_cascade_build_triggers(mysqli $db, int $pid, float $base, array $levels): array {
    $trigger = [];
    if ($base <= 0.0 || !$levels) {
        return $trigger;
    }
    ksort($levels);
    foreach ($levels as $idx => $lv) {
        if ($idx < 1) {
            continue;
        }
        if (!is_array($lv)) {
            continue;
        }
        $drop = isset($lv['drop']) ? (float)$lv['drop'] : 0.0;
        $anchor = 0.0;
        if ($idx === 1) {
            $anchor = $base;
        } else {
            $anchor = ladder_cascade_anchor_prev($db, $pid, $base, $levels, $idx - 1);
        }
        if ($anchor <= 0.0) {
            continue;
        }
        $trigger[$idx] = $anchor * (1.0 - $drop / 100.0);
    }
    return $trigger;
}
