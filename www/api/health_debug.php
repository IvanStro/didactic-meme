<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: text/plain; charset=UTF-8');

echo "PHP: " . PHP_VERSION . "\n";

$autoload = __DIR__ . '/../vendor/autoload.php';
echo "autoload: $autoload exists=" . (file_exists($autoload) ? 'yes' : 'no') . "\n";
if (!file_exists($autoload)) { exit("NO AUTOLOAD\n"); }

require $autoload;
echo "autoload included\n";

if (!class_exists(\Dotenv\Dotenv::class)) {
    exit("Dotenv class NOT found\n");
}

$dotenv = \Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

echo "dotenv loaded\n";
echo "OK\n";
