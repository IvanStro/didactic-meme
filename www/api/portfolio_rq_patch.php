<?php
// RoyalQ-style блок для portfolio.php — використовує rq_overview.php
// вставляється в portfolio.php перед фінальним echo

$rq_json = file_get_contents(__DIR__.'/rq_overview.php');
$rq = json_decode($rq_json, true);

if (is_array($rq) && isset($rq['ok']) && $rq['ok']) {
    $portfolio_rq = $rq['items'];
} else {
    $portfolio_rq = [];
}

// тепер $portfolio_rq містить всі правильні позиції (qty, avg, profit)
