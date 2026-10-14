<?php
declare(strict_types=1);

require __DIR__ . '/../www/vendor/autoload.php';
require_once __DIR__ . '/../www/api/_privat_accounting_api.php';

Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

echo "probe:\n";
$probe = privat_accounting_probe();
echo json_encode($probe, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

echo "\nbalance:\n";
$bal = privat_accounting_balances();
echo json_encode($bal, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
