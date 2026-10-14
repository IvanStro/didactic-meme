<?php
declare(strict_types=1);

/**
 * Коли процеси під ADM.TOOLS Supervisor — cron_ensure лише моніторить, не спавнить.
 * .env: STRIM_SUPERVISOR_MANAGED=1
 */
function strim_supervisor_managed(): bool
{
    $v = strtolower(trim((string) (getenv('STRIM_SUPERVISOR_MANAGED') ?: ($_ENV['STRIM_SUPERVISOR_MANAGED'] ?? ''))));
    return in_array($v, ['1', 'true', 'yes', 'on'], true);
}
