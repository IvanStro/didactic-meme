<?php
declare(strict_types=1);
/** Додає до SKIP текст з anchor/target/price/gap (%). Target рахуємо БЕЗ ε для прозорості. */
function skip_extra(mysqli $db, float $anchor, float $drop_pct, float $price): string {
  $target = ($anchor>0) ? $anchor * (1.0 - $drop_pct/100.0) : 0.0;
  $gapPct = ($target>0 && $price>0) ? ((($price/$target)-1.0)*100.0) : 0.0;
  return sprintf("anchor=%.8f target=%.8f price=%.8f gap=%.4f%%", $anchor, $target, $price, $gapPct);
}
