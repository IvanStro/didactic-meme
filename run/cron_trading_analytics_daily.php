<?php
declare(strict_types=1);
/**
 * Щоденний знімок торгової ефективності → analytics_* (08:00 Kyiv).
 *
 *   php run/cron_trading_analytics_daily.php
 *   php run/cron_trading_analytics_daily.php --date=2026-09-27
 */
$root = dirname(__DIR__);
require $root . '/www/vendor/autoload.php';
require_once __DIR__ . '/cron_singleton.php';
require_once __DIR__ . '/cron_host_guard.php';
require_once $root . '/www/api/_trading_analytics.php';
require_once $root . '/www/api/_tg_notify.php';

cron_singleton_acquire('cron_trading_analytics_daily');
set_time_limit(600);
Dotenv\Dotenv::createImmutable($root)->safeLoad();
cron_host_guard_or_exit('cron_trading_analytics_daily');
@date_default_timezone_set('Europe/Kyiv');

$date = null;
foreach ($argv ?? [] as $a) {
    if (str_starts_with($a, '--date=')) {
        $date = substr($a, 7);
    }
}

$db = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
$db->set_charset('utf8mb4');

try {
    $res = analytics_collect_daily($db, $date);
    echo date('c') . ' users=' . $res['users'] . ' pairs=' . $res['pairs']
        . ' errors=' . count($res['errors']) . PHP_EOL;
    foreach (array_slice($res['errors'], 0, 10) as $e) {
        echo "  err: {$e}\n";
    }
    $notify = ((string)(getenv('ANALYTICS_DAILY_TG') ?: ($_ENV['ANALYTICS_DAILY_TG'] ?? '0')) === '1');
    if ($notify) {
        $txt = sprintf(
            "📊 <b>StrIM analytics daily</b>\nДата: %s\nЮзерів: %d · пар: %d · помилок BN: %d",
            $date ?: date('Y-m-d'),
            $res['users'],
            $res['pairs'],
            count($res['errors'])
        );
        tg_notify($txt, ['silent' => true, 'skip_journal' => true]);
    }
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, date('c') . ' FAIL ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
