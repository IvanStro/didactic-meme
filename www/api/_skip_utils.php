<?php
declare(strict_types=1);

/**
 * Пояснює, чому BUY пропущено (qNorm<=0).
 * Повертає короткий ASCII-текст: freeUSDT, desired, minNotional, price, і список причин.
 */
function explain_buy_skip(mysqli $db, string $symbol, float $desiredQuoteUSDT, string $apiKey, string $secret): string {
  try {
    $acc      = exi_get_account($apiKey, $secret);
    $freeUSDT = exi_get_free_only($acc, 'USDT');   // тільки вільний
    $filters  = exi_symbol_filters($symbol);
    $minNot   = (float)($filters['minNotional'] ?? 0.0);
    $price    = exi_get_price($symbol);

    $reasons = [];
    if ($freeUSDT <= 1e-8)                       $reasons[] = 'USDT=0';
    if ($desiredQuoteUSDT > $freeUSDT + 1e-8)    $reasons[] = 'desired>freeUSDT';
    if ($minNot > 0 && $desiredQuoteUSDT < $minNot) $reasons[] = 'desired<minNotional';
    if ($minNot > 0 && $freeUSDT < $minNot)         $reasons[] = 'freeUSDT<minNotional';

    $info = 'freeUSDT=' . number_format($freeUSDT,2,'.','')
          . ' | desired=' . number_format($desiredQuoteUSDT,2,'.','')
          . ' | minNotional=' . number_format($minNot,2,'.','')
          . ' | price=' . number_format($price,8,'.','');

    if (!empty($reasons)) $info = 'skip: ' . implode(', ', $reasons) . ' | ' . $info;
    return $info;
  } catch (Throwable $e) {
    return 'skip: reason=unknown (' . $e->getMessage() . ')';
  }
}
