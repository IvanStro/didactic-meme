<?php
declare(strict_types=1);
/**
 * Одноразовий прапорець конверсії «Реєстрація» після do_register (cookie strim_reg_conv).
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_google_ads_config.php';

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(dirname(__DIR__, 2));
$dotenv->safeLoad();

$out = [
    'ok' => true,
    'fire' => false,
    'send_to' => google_ads_registration_send_to(),
    'registration_value_uah' => google_ads_registration_value_uah(),
];

if ($out['send_to'] === '') {
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (empty($_COOKIE['strim_reg_conv']) || (string) $_COOKIE['strim_reg_conv'] !== '1') {
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

setcookie('strim_reg_conv', '', [
    'expires' => time() - 3600,
    'path' => '/',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Lax',
]);

$out['fire'] = true;
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
