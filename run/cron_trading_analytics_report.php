<?php
declare(strict_types=1);
/**
 * Періодичні звіти: тиждень + місяць (MTD) + рік (YTD) → TG адміну.
 * Розклад: неділя 12:00 Kyiv.
 *
 *   php run/cron_trading_analytics_report.php
 *   php run/cron_trading_analytics_report.php --dry-run
 */
$root = dirname(__DIR__);
require $root . '/www/vendor/autoload.php';
require_once __DIR__ . '/cron_singleton.php';
require_once __DIR__ . '/cron_host_guard.php';
require_once $root . '/www/api/_trading_analytics.php';
require_once $root . '/www/api/_tg_notify.php';

cron_singleton_acquire('cron_trading_analytics_report');
set_time_limit(300);
Dotenv\Dotenv::createImmutable($root)->safeLoad();
cron_host_guard_or_exit('cron_trading_analytics_report');
@date_default_timezone_set('Europe/Kyiv');

$dry = in_array('--dry-run', $argv ?? [], true);
$db = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
$db->set_charset('utf8mb4');

try {
    if (!analytics_tables_ready($db)) {
        throw new RuntimeException('analytics tables missing');
    }
    // Ensure today snapshot exists before report (best-effort)
    try {
        analytics_collect_daily($db, date('Y-m-d'));
    } catch (Throwable $e) {
        echo date('c') . ' snapshot_warn ' . $e->getMessage() . PHP_EOL;
    }

    $reports = analytics_run_scheduled_reports($db);
    foreach ($reports as $rep) {
        echo date('c') . ' report id=' . $rep['id'] . ' ' . $rep['title'] . PHP_EOL;
        echo $rep['summary_uk'] . PHP_EOL;
    }

    if ($dry) {
        exit(0);
    }

    $parts = ["📈 <b>StrIM · періодична аналітика</b>", date('Y-m-d H:i') . ' (Kyiv)', ''];
    foreach ($reports as $rep) {
        $parts[] = '<b>' . htmlspecialchars($rep['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</b>';
        $parts[] = htmlspecialchars($rep['summary_uk'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $parts[] = 'Адмінка: /admin/analytics.php?report=' . (int)$rep['id'];
        $parts[] = '';
    }
    $parts[] = 'Повний текст і рейтинг — у адмін-панелі.';
    $text = implode("\n", $parts);
    // Telegram 4096 limit — truncate
    if (mb_strlen($text) > 3500) {
        $text = mb_substr($text, 0, 3400) . "\n…";
    }
    [$ok, $msg] = tg_notify($text, ['skip_journal' => true]);
    echo date('c') . ' tg ok=' . ($ok ? '1' : '0') . ' ' . $msg . PHP_EOL;
    exit($ok ? 0 : 1);
} catch (Throwable $e) {
    fwrite(STDERR, date('c') . ' FAIL ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
