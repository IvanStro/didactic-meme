<?php
declare(strict_types=1);

/**
 * Symbol → running strategy_ids (фаза 3 V2).
 * JSON cache; оновлення на start/pause/set. Торгівлю не змінює.
 */

function symbol_index_file_path(): string
{
    return dirname(__DIR__, 2) . '/logs/cache/symbol_index.json';
}

/** @return array{v:int,built_at:int,symbols:array<string,list<int>>,by_sid:array<string,string>} */
function symbol_index_empty(): array
{
    return [
        'v' => 1,
        'built_at' => time(),
        'symbols' => [],
        'by_sid' => [],
    ];
}

/** @return array{v:int,built_at:int,symbols:array<string,list<int>>,by_sid:array<string,string>} */
function symbol_index_load(): array
{
    $path = symbol_index_file_path();
    if (!is_readable($path)) {
        return symbol_index_empty();
    }
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return symbol_index_empty();
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['symbols']) || !is_array($data['symbols'])) {
        return symbol_index_empty();
    }
    $data['v'] = 1;
    $data['built_at'] = (int)($data['built_at'] ?? 0);
    $data['symbols'] = is_array($data['symbols']) ? $data['symbols'] : [];
    $data['by_sid'] = is_array($data['by_sid'] ?? null) ? $data['by_sid'] : [];
    return $data;
}

/** @param array{v:int,built_at:int,symbols:array<string,list<int>>,by_sid:array<string,string>} $data */
function symbol_index_save(array $data): bool
{
    $dir = dirname(symbol_index_file_path());
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return false;
    }
    $data['v'] = 1;
    $data['built_at'] = time();
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }
    return @file_put_contents(symbol_index_file_path(), $json, LOCK_EX) !== false;
}

function symbol_index_normalize_symbol(string $symbol): string
{
    $symbol = strtoupper(trim($symbol));
    if ($symbol === '' || $symbol === 'TEMPLATE') {
        return '';
    }
    return $symbol;
}

/** @return list<int> */
function symbol_index_get_strategy_ids(string $symbol): array
{
    $symbol = symbol_index_normalize_symbol($symbol);
    if ($symbol === '') {
        return [];
    }
    $data = symbol_index_load();
    $list = $data['symbols'][$symbol] ?? [];
    if (!is_array($list)) {
        return [];
    }
    $out = [];
    foreach ($list as $sid) {
        $sid = (int)$sid;
        if ($sid > 0) {
            $out[] = $sid;
        }
    }
    return array_values(array_unique($out));
}

function symbol_index_unregister_strategy(int $strategyId): void
{
    $strategyId = (int)$strategyId;
    if ($strategyId <= 0) {
        return;
    }
    $data = symbol_index_load();
    $sidKey = (string)$strategyId;
    $oldSym = (string)($data['by_sid'][$sidKey] ?? '');
    if ($oldSym !== '' && isset($data['symbols'][$oldSym])) {
        $data['symbols'][$oldSym] = array_values(array_filter(
            $data['symbols'][$oldSym],
            static fn($id) => (int)$id !== $strategyId
        ));
        if ($data['symbols'][$oldSym] === []) {
            unset($data['symbols'][$oldSym]);
        }
    }
    unset($data['by_sid'][$sidKey]);
    symbol_index_save($data);
}

function symbol_index_register_running(int $strategyId, string $symbol): void
{
    $strategyId = (int)$strategyId;
    $symbol = symbol_index_normalize_symbol($symbol);
    if ($strategyId <= 0 || $symbol === '') {
        return;
    }
    symbol_index_unregister_strategy($strategyId);
    $data = symbol_index_load();
    $sidKey = (string)$strategyId;
    if (!isset($data['symbols'][$symbol])) {
        $data['symbols'][$symbol] = [];
    }
    if (!in_array($strategyId, $data['symbols'][$symbol], true)) {
        $data['symbols'][$symbol][] = $strategyId;
    }
    $data['by_sid'][$sidKey] = $symbol;
    symbol_index_save($data);
}

/** Після зміни status/symbol у БД. */
function symbol_index_sync_strategy(mysqli $db, int $strategyId): void
{
    $strategyId = (int)$strategyId;
    if ($strategyId <= 0) {
        return;
    }
    $r = $db->query("SELECT id, symbol, status FROM strategies WHERE id=$strategyId LIMIT 1");
    if (!$r || !($row = $r->fetch_assoc())) {
        symbol_index_unregister_strategy($strategyId);
        return;
    }
    $symbol = symbol_index_normalize_symbol((string)($row['symbol'] ?? ''));
    $status = (string)($row['status'] ?? '');
    // running + paused: signal_daemon має бачити sid (на паузі — лише виходи).
    if (($status === 'running' || $status === 'paused') && $symbol !== '') {
        symbol_index_register_running($strategyId, $symbol);
    } else {
        symbol_index_unregister_strategy($strategyId);
    }
}

/** Повна перебудова з БД (cron / deploy verify). */
function symbol_index_rebuild(mysqli $db): array
{
    $data = symbol_index_empty();
    $res = $db->query(
        "SELECT id, symbol FROM strategies
         WHERE status IN ('running','paused')
           AND symbol IS NOT NULL
           AND symbol <> ''
           AND symbol <> 'TEMPLATE'
         ORDER BY id ASC"
    );
    while ($res && ($row = $res->fetch_assoc())) {
        $sid = (int)($row['id'] ?? 0);
        $symbol = symbol_index_normalize_symbol((string)($row['symbol'] ?? ''));
        if ($sid <= 0 || $symbol === '') {
            continue;
        }
        $sidKey = (string)$sid;
        if (!isset($data['symbols'][$symbol])) {
            $data['symbols'][$symbol] = [];
        }
        $data['symbols'][$symbol][] = $sid;
        $data['by_sid'][$sidKey] = $symbol;
    }
    foreach ($data['symbols'] as $sym => $ids) {
        $data['symbols'][$sym] = array_values(array_unique(array_map('intval', $ids)));
    }
    symbol_index_save($data);
    return $data;
}

/** @return array{built_at:int,symbol_count:int,running_count:int,symbols:array<string,int>} */
function symbol_index_stats(): array
{
    $data = symbol_index_load();
    $running = 0;
    $perSymbol = [];
    foreach ($data['symbols'] as $sym => $ids) {
        $n = is_array($ids) ? count($ids) : 0;
        $perSymbol[$sym] = $n;
        $running += $n;
    }
    return [
        'built_at' => (int)($data['built_at'] ?? 0),
        'built_at_iso' => ($data['built_at'] ?? 0) > 0
            ? date('Y-m-d H:i:s', (int)$data['built_at'])
            : null,
        'symbol_count' => count($perSymbol),
        'running_count' => $running,
        'symbols' => $perSymbol,
    ];
}
