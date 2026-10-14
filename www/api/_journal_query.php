<?php
declare(strict_types=1);

/** Події лише для адмін-перегляду (технічний/sync). */
function journal_internal_event_types(): array
{
    return [
        'SYNC_EXTERNAL_BUY',
        'TG_WARN',
        'TG',
        'BUY_L_SKIP',
        'SITE_AUDIT',
    ];
}

function journal_user_is_admin(mysqli $db, int $uid): bool
{
    if ($uid <= 0) {
        return false;
    }
    $res = $db->query('SELECT is_admin FROM users WHERE id=' . (int) $uid . ' LIMIT 1');
    $row = $res ? $res->fetch_assoc() : null;

    return $row && (int) ($row['is_admin'] ?? 0) === 1;
}

function journal_sql_owner_scope(int $ownerUid, string $alias = 's'): string
{
    $o = (int) $ownerUid;

    return '(' . $alias . '.owner_id=' . $o . ' OR (' . $alias . '.owner_id IS NULL AND ' . $alias . '.user_id=' . $o . '))';
}

function journal_sql_event_filter(bool $includeInternal, string $col): string
{
    if ($includeInternal) {
        return '';
    }
    $types = journal_internal_event_types();
    if ($types === []) {
        return '';
    }
    $quoted = array_map(static fn(string $t): string => "'" . $t . "'", $types);

    return ' AND ' . $col . ' NOT IN (' . implode(',', $quoted) . ') ';
}

/** Хибні рядки журналу (адмін-звіти, помилково записані як BUY_L). */
function journal_is_spam_row(array $row): bool
{
    $msg = (string) ($row['message'] ?? '');
    if ($msg === '') {
        return false;
    }
    if (stripos($msg, 'щогодинний звіт') !== false) {
        return true;
    }
    if (preg_match('/Частина\s+\d+\s*\/\s*\d+/u', $msg) && stripos($msg, 'StrIM') !== false) {
        return true;
    }

    return false;
}

/**
 * @return array<int, array<string, mixed>>
 */
function journal_fetch_items(mysqli $db, int $ownerUid, int $sid, int $limit, bool $includeInternal): array
{
    $limit = max(1, min(1000, $limit));
    $scope = journal_sql_owner_scope($ownerUid, 's');
    $scope2 = journal_sql_owner_scope($ownerUid, 's2');
    $ejFilter = journal_sql_event_filter($includeInternal, 'j.event_type');
    $tlFilter = journal_sql_event_filter($includeInternal, 'tl.event_type');

    $sidSql = $sid > 0 ? 'AND j.strategy_id=' . (int) $sid : '';
    $sidSql2 = $sid > 0 ? 'AND tl.strategy_id=' . (int) $sid : '';

    $coll = 'utf8mb4_0900_ai_ci';

    $sql = "
      SELECT * FROM (
        SELECT
          j.id, j.created_at, j.strategy_id,
          j.symbol COLLATE {$coll} AS symbol,
          j.event_type COLLATE {$coll} AS event_type,
          j.level_idx,
          j.side COLLATE {$coll} AS side,
          j.qty, j.price, j.pnl_usdt, j.pnl_pct,
          j.message COLLATE {$coll} AS message
        FROM engine_journal j
        INNER JOIN strategies s ON s.id = j.strategy_id
        WHERE {$scope}
        {$sidSql}
        {$ejFilter}
        AND NOT (
          j.event_type IN ('BUY_L', 'BUY0')
          AND EXISTS (
            SELECT 1 FROM trade_ledger tlb
            WHERE tlb.strategy_id = j.strategy_id
              AND tlb.symbol = j.symbol
              AND tlb.event_type = j.event_type
              AND (tlb.level_idx <=> j.level_idx)
              AND ABS(TIMESTAMPDIFF(SECOND, tlb.created_at, j.created_at)) <= 90
          )
        )

        UNION ALL

        SELECT
          (tl.id + 900000000000000) AS id,
          tl.created_at, tl.strategy_id,
          tl.symbol COLLATE {$coll} AS symbol,
          tl.event_type COLLATE {$coll} AS event_type,
          tl.level_idx,
          tl.side COLLATE {$coll} AS side,
          tl.qty, tl.price, tl.pnl_usdt, tl.pnl_pct,
          CONCAT(
            '[trade_ledger] ', tl.event_type, ' ', tl.symbol,
            ' qty=', CAST(tl.qty AS CHAR),
            ' price=', CAST(tl.price AS CHAR),
            IF(tl.quote_usdt IS NOT NULL AND tl.quote_usdt > 0, CONCAT(' quote≈', CAST(tl.quote_usdt AS CHAR), ' USDT'), ''),
            IF(tl.order_id IS NOT NULL, CONCAT(' orderId=', CAST(tl.order_id AS CHAR)), '')
          ) COLLATE {$coll} AS message
        FROM trade_ledger tl
        INNER JOIN strategies s2 ON s2.id = tl.strategy_id
        WHERE {$scope2}
        {$sidSql2}
        {$tlFilter}
        AND NOT EXISTS (
          SELECT 1 FROM engine_journal ej
          WHERE ej.strategy_id = tl.strategy_id
            AND ej.symbol = tl.symbol
            AND (ej.level_idx <=> tl.level_idx)
            AND ABS(TIMESTAMPDIFF(SECOND, ej.created_at, tl.created_at)) <= 90
            AND (
              (tl.event_type = 'SELL_SUB' AND ej.event_type IN ('SUB_SELL', 'SUB SELL'))
              OR (tl.event_type = 'SELL_MAIN' AND ej.event_type IN ('CORE_SELL', 'MAIN_SELL', 'MAIN SELL CORE', 'MAIN_SELL_ALL'))
              OR (
                tl.event_type NOT IN ('SELL_SUB', 'SELL_MAIN', 'BUY_L', 'BUY0')
                AND ej.event_type = tl.event_type
              )
            )
        )
      ) AS merged
      ORDER BY merged.created_at DESC, merged.id DESC
      LIMIT {$limit}
    ";

    try {
        $res = $db->query($sql);
    } catch (mysqli_sql_exception $e) {
        error_log('journal_fetch_items union: ' . $e->getMessage());
        $res = false;
    }
    if (!$res) {
        $fallback = "
          SELECT j.id, j.created_at, j.strategy_id, j.symbol, j.event_type, j.level_idx, j.side, j.qty, j.price, j.pnl_usdt, j.pnl_pct, j.message
          FROM engine_journal j
          INNER JOIN strategies s ON s.id = j.strategy_id
          WHERE {$scope}
          {$sidSql}
          {$ejFilter}
          ORDER BY j.id DESC
          LIMIT {$limit}
        ";
        $res = $db->query($fallback);
    }

    $items = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $items[] = [
                'id'          => (int) $r['id'],
                'created_at'  => $r['created_at'],
                'strategy_id' => (int) $r['strategy_id'],
                'symbol'      => (string) $r['symbol'],
                'event_type'  => (string) $r['event_type'],
                'level_idx'   => $r['level_idx'] === null ? null : (int) $r['level_idx'],
                'side'        => $r['side'] === null ? null : (string) $r['side'],
                'qty'         => $r['qty'] === null ? null : (float) $r['qty'],
                'price'       => $r['price'] === null ? null : (float) $r['price'],
                'pnl_usdt'    => $r['pnl_usdt'] === null ? null : (float) $r['pnl_usdt'],
                'pnl_pct'     => $r['pnl_pct'] === null ? null : (float) $r['pnl_pct'],
                'message'     => $r['message'] === null ? '' : (string) $r['message'],
            ];
        }
    }

    $items = array_values(array_filter($items, static function (array $row): bool {
        return !journal_is_spam_row($row);
    }));

    if (!$includeInternal) {
        $internal = array_flip(journal_internal_event_types());
        $items = array_values(array_filter($items, static function (array $row) use ($internal): bool {
            $type = (string) ($row['event_type'] ?? '');
            if (isset($internal[$type])) {
                return false;
            }
            $msg = (string) ($row['message'] ?? '');
            if (stripos($msg, 'SYNC_EXTERNAL') !== false) {
                return false;
            }
            if (stripos($msg, 'щогодинний звіт') !== false || preg_match('/Частина\s+\d+\s*\/\s*\d+/u', $msg)) {
                return false;
            }

            return true;
        }));
    }

    return $items;
}

/**
 * @return array<int, array{id:int,symbol:string,status:string}>
 */
function journal_list_user_strategies(mysqli $db, int $ownerUid): array
{
    $scope = journal_sql_owner_scope($ownerUid, 's');
    $res = $db->query(
        "SELECT id, symbol, status FROM strategies s WHERE {$scope} ORDER BY id DESC"
    );
    $out = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $out[] = [
                'id'     => (int) $r['id'],
                'symbol' => (string) $r['symbol'],
                'status' => (string) ($r['status'] ?? ''),
            ];
        }
    }

    return $out;
}
