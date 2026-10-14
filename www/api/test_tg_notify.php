<?php
declare(strict_types=1);
require __DIR__.'/_tg_notify.php';

list($ok, $msg) = tg_notify("✅ Тест нотифікації з бота.\n" .
                            "Час: " . date('Y-m-d H:i:s') . "\n" .
                            "Система: LuckyMartingale");
header('Content-Type: text/plain; charset=utf-8');
echo $ok ? "OK\n" : "ERR: $msg\n";
