<?php
declare(strict_types=1);

/**
 * Перевірка DNS/пошти strim.in.ua через API хостингу (CLI).
 *   php run/adm_dns_audit.php
 */
require __DIR__ . '/../www/vendor/autoload.php';
require_once __DIR__ . '/../www/api/_whitepay_dotenv.php';
require_once __DIR__ . '/../www/api/_adm_tools_client.php';

Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
strim_whitepay_maybe_load_home_env();

$report = strim_adm_tools_hosting_report('strim.in.ua');
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
exit(($report['summary']['fail'] ?? 0) > 0 ? 1 : 0);
