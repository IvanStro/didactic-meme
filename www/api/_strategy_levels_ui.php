<?php
declare(strict_types=1);

/**
 * Exact copy of engine load_strategy_levels() row source (engine_logic.php): only strategy_levels
 * for this strategy, else global strategy_levels strategy_id=0. Never martingale.
 *
 * @return list<array{idx:int,drop:float,mult:float,tp:?float,cb:?float}>
 */
function strategy_levels_rows_like_engine(mysqli $mysqli, int $strategy_id): array
{
    $sid = (int)$strategy_id;
    $out = [];
    $r1 = $mysqli->query(
        'SELECT lvl_idx, drop_pct, mult, tp_pct, cb_pct FROM strategy_levels WHERE strategy_id=' .
        $sid .
        ' ORDER BY lvl_idx ASC'
    );
    if ($r1 && (int)($r1->num_rows ?? 0) > 0) {
        while ($row = $r1->fetch_assoc()) {
            $out[] = [
                'idx' => (int)$row['lvl_idx'],
                'drop' => (float)$row['drop_pct'],
                'mult' => (float)$row['mult'],
                'tp' => isset($row['tp_pct']) ? (float)$row['tp_pct'] : null,
                'cb' => isset($row['cb_pct']) ? (float)$row['cb_pct'] : null,
            ];
        }

        return $out;
    }
    $r2 = $mysqli->query(
        'SELECT lvl_idx, drop_pct, mult, tp_pct, cb_pct FROM strategy_levels WHERE strategy_id=0 ORDER BY lvl_idx ASC'
    );
    if ($r2) {
        while ($row = $r2->fetch_assoc()) {
            $out[] = [
                'idx' => (int)$row['lvl_idx'],
                'drop' => (float)$row['drop_pct'],
                'mult' => (float)$row['mult'],
                'tp' => isset($row['tp_pct']) ? (float)$row['tp_pct'] : null,
                'cb' => isset($row['cb_pct']) ? (float)$row['cb_pct'] : null,
            ];
        }
    }

    return $out;
}

/**
 * Level definitions for UI/API — same resolution order as engine load_strategy_levels()
 * (engine/engine_logic.php), extended with martingale + master fallbacks used elsewhere in SaaS.
 *
 * @return list<array{idx:int,drop:float,mult:float,tp:?float,cb:?float}>
 */
function strategy_levels_ui_rows(mysqli $mysqli, int $strategy_id, int $master_id): array
{
    $strategy_id = (int)$strategy_id;
    $master_id = (int)$master_id;
    $rows = [];

    $engineRows = strategy_levels_rows_like_engine($mysqli, $strategy_id);
    if (count($engineRows) > 0) {
        return $engineRows;
    }

    $r3 = $mysqli->query(
        'SELECT idx, drop_pct, mult, tp_sub, cb_sub FROM martingale_levels WHERE strategy_id=' .
        $strategy_id .
        ' ORDER BY idx ASC'
    );
    if ($r3) {
        while ($row = $r3->fetch_assoc()) {
            $rows[] = [
                'idx' => (int)$row['idx'],
                'drop' => (float)$row['drop_pct'],
                'mult' => (float)$row['mult'],
                'tp' => isset($row['tp_sub']) ? (float)$row['tp_sub'] : null,
                'cb' => isset($row['cb_sub']) ? (float)$row['cb_sub'] : null,
            ];
        }
    }
    if (count($rows) > 0) {
        return $rows;
    }

    if ($master_id > 0) {
        $r4 = $mysqli->query(
            'SELECT lvl_idx, drop_pct, mult, tp_pct, cb_pct FROM strategy_levels WHERE strategy_id=' .
            $master_id .
            ' ORDER BY lvl_idx ASC'
        );
        if ($r4) {
            while ($row = $r4->fetch_assoc()) {
                $rows[] = [
                    'idx' => (int)$row['lvl_idx'],
                    'drop' => (float)$row['drop_pct'],
                    'mult' => (float)$row['mult'],
                    'tp' => isset($row['tp_pct']) ? (float)$row['tp_pct'] : null,
                    'cb' => isset($row['cb_pct']) ? (float)$row['cb_pct'] : null,
                ];
            }
        }
    }

    return $rows;
}
