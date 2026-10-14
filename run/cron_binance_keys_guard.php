<?php
declare(strict_types=1);
/**
 * Auto-pause running strategies when Binance API keys fail (401/403).
 *   php run/cron_binance_keys_guard.php
 *
 * Cron (кожні 15 хв):
 *   /usr/local/php84/bin/php /home/bot20/strim.in.ua/run/cron_binance_keys_guard.php >> /home/bot20/strim.in.ua/logs/binance_keys_guard.log 2>&1
 */
$root = dirname(__DIR__);
require $root . '/www/vendor/autoload.php';
require_once __DIR__ . '/cron_singleton.php';
require_once __DIR__ . '/cron_host_guard.php';
require_once $root . '/engine_v2/bootstrap.php';
require_once $root . '/www/api/_user_keys.php';
require_once $root . '/www/api/_binance_utils.php';
require_once $root . '/www/api/_tg_notify.php';

cron_singleton_acquire('cron_binance_keys_guard');
set_time_limit(120);

Dotenv\Dotenv::createImmutable($root)->safeLoad();
cron_host_guard_or_exit('cron_binance_keys_guard');

$db = db();
$cooldownSec = max(3600, (int)(getenv('BINANCE_KEYS_GUARD_COOLDOWN_SEC') ?: ($_ENV['BINANCE_KEYS_GUARD_COOLDOWN_SEC'] ?? 86400)));

function keys_guard_should_alert(mysqli $db, int $sid, int $cooldownSec): bool
{
    $k = $db->real_escape_string('binance_keys_guard_last_' . $sid);
    $res = $db->query("SELECT v FROM app_settings WHERE k='{$k}' LIMIT 1");
    $last = $res ? (string)($res->fetch_assoc()['v'] ?? '') : '';
    if ($last === '') {
        return true;
    }
    $age = time() - strtotime($last);
    return $age < 0 || $age >= $cooldownSec;
}

function keys_guard_mark_alert(mysqli $db, int $sid): void
{
    $k = $db->real_escape_string('binance_keys_guard_last_' . $sid);
    $now = gmdate('Y-m-d H:i:s');
    $nowEsc = $db->real_escape_string($now);
    $db->query("INSERT INTO app_settings (k, v) VALUES ('{$k}', '{$nowEsc}') ON DUPLICATE KEY UPDATE v='{$nowEsc}'");
}

function keys_guard_ping_binance(string $apiKey, string $secret): array
{
    $params = [
        'timestamp' => bn_server_time(),
        'recvWindow' => 10000,
    ];
    $q = bn_sign_query($params, $secret);
    [$http, $body, $err] = http_json(
        bn_base() . '/api/v3/account?' . $q,
        ['X-MBX-APIKEY: ' . $apiKey],
        'GET'
    );
    return ['http' => $http, 'body' => (string)$body, 'err' => $err];
}

$paused = 0;
$checked = 0;

$res = $db->query("
    SELECT s.id, s.user_id, s.symbol, u.email, u.nickname
    FROM strategies s
    INNER JOIN users u ON u.id = s.user_id
    WHERE s.status = 'running'
    ORDER BY s.id
");

while ($res && ($st = $res->fetch_assoc())) {
    $sid = (int)$st['id'];
    $uid = (int)$st['user_id'];
    $symbol = (string)$st['symbol'];
    $checked++;

    [$apiKey, $secret] = user_keys($db, $uid);
    if (!$apiKey || !$secret) {
        echo date('c') . " sid={$sid} skip no_keys\n";
        continue;
    }

    $ping = keys_guard_ping_binance($apiKey, $secret);
    $http = (int)$ping['http'];
    if ($http !== 401 && $http !== 403) {
        continue;
    }

    echo date('c') . " sid={$sid} {$symbol} uid={$uid} http={$http} → pause\n";

    $db->query("UPDATE strategies SET status='paused' WHERE id={$sid} AND status='running' LIMIT 1");
    if ($db->affected_rows > 0) {
        $paused++;
        try {
            require_once $root . '/engine/signal/symbol_index.php';
            symbol_index_sync_strategy($db, $sid);
        } catch (Throwable $e) {
            // best-effort
        }
    }

    if (!keys_guard_should_alert($db, $sid, $cooldownSec)) {
        continue;
    }

    $nick = (string)($st['nickname'] ?? '');
    $txtUser = "⏸ <b>{$symbol}</b> — стратегію зупинено\n\n"
        . "Binance відхилив API ключі (помилка {$http}). "
        . "Перевірте:\n"
        . "• IP whitelist на Binance (додайте IP сервера StrIM)\n"
        . "• права Reading + Spot Trading\n"
        . "• термін дії ключа\n\n"
        . "Після виправлення — у кабінеті натисніть <b>Start</b>.\n"
        . "Інструкція: https://strim.in.ua/instruction.html#binance-api";

    tg_notify_for_strategy($db, $sid, $txtUser);

    tg_notify("⚠️ Binance keys guard\n"
        . "{$nick} ({$st['email']}) sid={$sid} {$symbol}\n"
        . "http={$http} → paused");

    keys_guard_mark_alert($db, $sid);
}

echo date('c') . " binance_keys_guard checked={$checked} paused={$paused}\n";
