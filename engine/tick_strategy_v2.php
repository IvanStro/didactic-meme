<?php

/**
 * НОВИЙ ДВИГУН V2
 * Переписана архітектура: чисті файли, нормальна структуризація, зрозумілий код.
 * Поки що тут лише виклик engine, логіка переноситься поступово.
 */

require __DIR__.'/engine_boot.php';
require __DIR__.'/engine_helpers.php';
require __DIR__.'/engine_logic.php';

// Запускаємо всі стратегії
@$mysqli->query("UPDATE strategies SET last_tick_at = NOW()");
run_all_strategies($mysqli, $apiKey, $secret);

