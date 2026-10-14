<?php
declare(strict_types=1);
/**
 * Коротке посилання для Instagram bio.
 * У профілі: https://strim.in.ua/ig
 */
header(
    'Location: /instruction.html?utm_source=instagram&utm_medium=bio&utm_campaign=guide',
    true,
    302
);
exit;
