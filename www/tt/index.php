<?php
declare(strict_types=1);
/**
 * Коротке посилання для TikTok bio.
 * У профілі: https://strim.in.ua/tt
 */
header(
    'Location: /?utm_source=tiktok&utm_medium=bio&utm_campaign=launch',
    true,
    302
);
exit;
