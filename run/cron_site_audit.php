<?php
declare(strict_types=1);
/**
 * Щогодинний аудит StrIM → Telegram адміну (TG_CHAT_ID), українською, повний звіт.
 *
 *   php run/cron_site_audit.php
 *   php run/cron_site_audit.php --dry-run
 *
 * Cron (щогодини):
 *   5 * * * * /bin/bash /home/bot20/strim.in.ua/run/cron_site_audit.sh
 */
$root = dirname(__DIR__);
require $root . '/www/vendor/autoload.php';
require_once __DIR__ . '/cron_singleton.php';
require_once __DIR__ . '/cron_host_guard.php';
require_once __DIR__ . '/site_audit.php';

cron_singleton_acquire('cron_site_audit');
set_time_limit(180);

Dotenv\Dotenv::createImmutable($root)->safeLoad();
cron_host_guard_or_exit('cron_site_audit');

require_once $root . '/www/api/_tg_notify.php';

$dryRun = in_array('--dry-run', $argv ?? [], true);
$alertOnly = ((string)(getenv('SITE_AUDIT_ALERT_ONLY') ?: ($_ENV['SITE_AUDIT_ALERT_ONLY'] ?? '0')) === '1');
$notifyAlways = !$alertOnly && ((string)(getenv('SITE_AUDIT_NOTIFY_ALWAYS') ?: ($_ENV['SITE_AUDIT_NOTIFY_ALWAYS'] ?? '1')) === '1');

$report = site_audit_run();
$ok = !empty($report['ok']);
$status = (string)($report['status'] ?? 'ok');
$issueN = count(is_array($report['issues'] ?? null) ? $report['issues'] : []);
$warnN = count(is_array($report['warnings'] ?? null) ? $report['warnings'] : []);

$summary = date('c') . " cron_site_audit status={$status} issues={$issueN} warnings={$warnN} http="
    . (int)($report['http']['ok'] ?? 0) . '/' . (int)($report['http']['total'] ?? 0);
echo $summary . "\n";

$messages = site_audit_telegram_messages($report);

if ($dryRun) {
    foreach ($messages as $i => $msg) {
        if ($i > 0) {
            echo "\n--- message " . ($i + 1) . '/' . count($messages) . " ---\n";
        }
        echo strip_tags(str_replace(['<b>', '</b>', '<i>', '</i>'], ['', '', '', ''], $msg)) . "\n";
    }
    exit(0);
}

if (!$notifyAlways && $ok && $warnN === 0) {
    echo date('c') . " skip TG (ok, alert_only mode)\n";
    exit(0);
}

$silent = ($status === 'ok' && $issueN === 0 && $warnN === 0 && empty($report['platform']['spot_frozen']));

$allOk = true;
foreach ($messages as $i => $text) {
    [$tgOk, $tgMsg] = tg_notify($text, ['silent' => $silent && $i === 0, 'skip_journal' => true]);
    echo date('c') . ' tg_notify part=' . ($i + 1) . '/' . count($messages) . ' ok=' . ($tgOk ? '1' : '0') . ' ' . $tgMsg . "\n";
    if (!$tgOk) {
        $allOk = false;
    }
    if ($i + 1 < count($messages)) {
        usleep(400000);
    }
}

exit($allOk ? 0 : 1);
