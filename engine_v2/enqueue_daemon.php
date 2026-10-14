<?php
declare(strict_types=1);

/**
 * @deprecated Єдиний підтримуваний процес — run/trading_ops_daemon.php (enqueue + воркери, flock).
 * Цей файл лишено для сумісності зі старими інструкціями: просто делегує туди ж.
 */
require dirname(__DIR__) . '/run/trading_ops_daemon.php';
