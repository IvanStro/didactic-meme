<?php
declare(strict_types=1);

/**
 * Реалізований прибуток по днях (trade_ledger SELL, dedupe як у strategies_list_v2).
 */

require_once __DIR__ . '/../auth/_session_user.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

header('Content-Type: application/json; charset=utf-8');

$dotenv = Dotenv::createImmutable(dirname(__DIR__, 2));
$dotenv->safeLoad();

$mysqli = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
if ($mysqli->connect_errno) {
    echo json_encode(['ok' => false, 'error' => 'db'], JSON_UNESCAPED_UNICODE);
    exit;
}
$mysqli->set_charset('utf8mb4');

$uid = current_user_id($mysqli, 86400);
if ($uid <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

$uid = (int)$uid;
$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');
$strategyId = isset($_GET['strategy_id']) ? (int)$_GET['strategy_id'] : 0;

if ($year < 2020 || $year > 2100) {
    $year = (int)date('Y');
}
if ($month < 1 || $month > 12) {
    $month = (int)date('n');
}

$tzName = getenv('APP_TIMEZONE') ?: ($_ENV['APP_TIMEZONE'] ?? 'Europe/Kyiv');
try {
    $tz = new DateTimeZone($tzName);
} catch (Throwable $e) {
    $tz = new DateTimeZone('Europe/Kyiv');
    $tzName = 'Europe/Kyiv';
}

$monthStart = new DateTime(sprintf('%04d-%02d-01 00:00:00', $year, $month), $tz);
$monthEnd = (clone $monthStart)->modify('+1 month');
$daysInMonth = (int)$monthStart->format('t');

$scopeWhere = "tl.user_id=$uid AND tl.side='SELL'";
if ($strategyId > 0) {
    $chk = $mysqli->query("SELECT id FROM strategies WHERE id=$strategyId AND owner_id=$uid LIMIT 1");
    if (!$chk || !$chk->fetch_assoc()) {
        echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $scopeWhere .= " AND tl.strategy_id=$strategyId";
}

$startSql = $mysqli->real_escape_string($monthStart->format('Y-m-d H:i:s'));
$endSql = $mysqli->real_escape_string($monthEnd->format('Y-m-d H:i:s'));

/**
 * @return array{pnl: array<string, float>, trades: array<string, int>}
 */
function profit_fetch_daily_stats(mysqli $db, string $scopeWhere, string $startSql, string $endSql, DateTimeZone $tz): array
{
    $sql = "
        SELECT day_key, SUM(pnl) AS pnl_day, COUNT(*) AS trades_day
        FROM (
            SELECT
                DATE(tl.created_at) AS day_key,
                MAX(COALESCE(tl.pnl_usdt, 0)) AS pnl
            FROM trade_ledger tl
            WHERE $scopeWhere
              AND tl.created_at >= '$startSql'
              AND tl.created_at < '$endSql'
            GROUP BY
                tl.strategy_id,
                tl.event_type,
                COALESCE(tl.order_id, 0),
                tl.created_at,
                ROUND(COALESCE(tl.price, 0), 12),
                ROUND(COALESCE(tl.qty, 0), 12)
        ) x
        GROUP BY day_key
    ";
    // DB NOW() уже в Europe/Kyiv (SYSTEM=+3). Не робити CONVERT_TZ з UTC —
    // інакше вечірні угоди (22:xx) стрибають на наступний день у календарі.
    $parsed = profit_parse_daily_stats_result($db->query($sql));
    if ($parsed !== null) {
        return $parsed;
    }

    return profit_fetch_daily_stats_fallback($db, $scopeWhere, $startSql, $endSql);
}

/**
 * @return array{pnl: array<string, float>, trades: array<string, int>}
 */
function profit_fetch_daily_stats_fallback(mysqli $db, string $scopeWhere, string $startSql, string $endSql): array
{
    $sql = "
        SELECT day_key, SUM(pnl) AS pnl_day, COUNT(*) AS trades_day
        FROM (
            SELECT
                DATE(tl.created_at) AS day_key,
                MAX(COALESCE(tl.pnl_usdt, 0)) AS pnl
            FROM trade_ledger tl
            WHERE $scopeWhere
              AND tl.created_at >= '$startSql'
              AND tl.created_at < '$endSql'
            GROUP BY
                tl.strategy_id,
                tl.event_type,
                COALESCE(tl.order_id, 0),
                tl.created_at,
                ROUND(COALESCE(tl.price, 0), 12),
                ROUND(COALESCE(tl.qty, 0), 12)
        ) x
        GROUP BY day_key
    ";
    $parsed = profit_parse_daily_stats_result($db->query($sql));
    return $parsed !== null ? $parsed : ['pnl' => [], 'trades' => []];
}

/**
 * @param mysqli_result|false|null $q
 * @return array{pnl: array<string, float>, trades: array<string, int>}|null
 */
function profit_parse_daily_stats_result($q): ?array
{
    if (!$q) {
        return null;
    }
    $pnl = [];
    $trades = [];
    while ($row = $q->fetch_assoc()) {
        $d = (string)($row['day_key'] ?? '');
        if ($d === '') {
            continue;
        }
        $pnl[$d] = round((float)($row['pnl_day'] ?? 0), 8);
        $trades[$d] = (int)($row['trades_day'] ?? 0);
    }
    return ['pnl' => $pnl, 'trades' => $trades];
}

function profit_tz_offset_sql(DateTimeZone $tz): string
{
    $now = new DateTime('now', $tz);
    $off = $tz->getOffset($now);
    $sign = $off >= 0 ? '+' : '-';
    $off = abs($off);
    $h = (int)floor($off / 3600);
    $m = (int)floor(($off % 3600) / 60);
    return $sign . sprintf('%02d:%02d', $h, $m);
}

function profit_fetch_total(mysqli $db, string $scopeWhere): float
{
    $sql = "
        SELECT COALESCE(SUM(x.pnl), 0) AS s
        FROM (
            SELECT MAX(COALESCE(tl.pnl_usdt, 0)) AS pnl
            FROM trade_ledger tl
            WHERE $scopeWhere
            GROUP BY
                tl.strategy_id,
                tl.event_type,
                COALESCE(tl.order_id, 0),
                tl.created_at,
                ROUND(COALESCE(tl.price, 0), 12),
                ROUND(COALESCE(tl.qty, 0), 12)
        ) x
    ";
    $q = $db->query($sql);
    if ($q && ($row = $q->fetch_assoc())) {
        return round((float)($row['s'] ?? 0), 8);
    }
    return 0.0;
}

// MySQL CONVERT_TZ needs named zone or offset; if created_at stored as local, fallback without CONVERT_TZ
$stats = profit_fetch_daily_stats($mysqli, $scopeWhere, $startSql, $endSql, $tz);
$daily = $stats['pnl'];
$dailyTrades = $stats['trades'];

$days = [];
$trades = [];
$monthSum = 0.0;
$monthTrades = 0;
for ($d = 1; $d <= $daysInMonth; $d++) {
    $key = sprintf('%04d-%02d-%02d', $year, $month, $d);
    $pnl = isset($daily[$key]) ? (float)$daily[$key] : 0.0;
    $nTrades = isset($dailyTrades[$key]) ? (int)$dailyTrades[$key] : 0;
    $days[$key] = $pnl;
    $trades[$key] = $nTrades;
    $monthSum += $pnl;
    $monthTrades += $nTrades;
}

$rows = [];
foreach ($days as $key => $pnl) {
    $rows[] = [
        'date' => $key,
        'pnl_usdt' => $pnl,
        'trades' => (int)($trades[$key] ?? 0),
    ];
}

$total = profit_fetch_total($mysqli, $scopeWhere);

$firstYear = $year;
$fq = $mysqli->query(
    "SELECT YEAR(MIN(created_at)) AS y FROM trade_ledger WHERE user_id=$uid AND side='SELL'"
);
if ($fq && ($fr = $fq->fetch_assoc())) {
    $fy = (int)($fr['y'] ?? 0);
    if ($fy >= 2020 && $fy <= 2100) {
        $firstYear = $fy;
    }
}

echo json_encode([
    'ok' => true,
    'timezone' => $tzName,
    'year' => $year,
    'month' => $month,
    'days_in_month' => $daysInMonth,
    'strategy_id' => $strategyId > 0 ? $strategyId : null,
    'total_realized_usdt' => $total,
    'month_realized_usdt' => round($monthSum, 8),
    'month_trades' => $monthTrades,
    'days' => $days,
    'trades' => $trades,
    'rows' => $rows,
    'year_min' => $firstYear,
    'year_max' => (int)date('Y'),
    'ts' => date('Y-m-d H:i:s'),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
