<?php
declare(strict_types=1);

/**
 * Thin-exec feature flags (Stage A2+).
 * Default: off → поведінка байт-в-байт як повний process_strategy.
 *
 * Env:
 *   THIN_EXEC_MODE=off|shadow|canary|on
 *   THIN_EXEC_CANARY_SIDS=2,9
 *   THIN_EXEC_ACTIONS=SUB_SELL  (comma prefixes; empty = none in live thin)
 *   THIN_EXEC_FALLBACK_FULL=1
 */

function thin_exec_refresh_env(): void
{
    if (function_exists('refresh_env_from_disk')) {
        refresh_env_from_disk();
    }
}

function thin_exec_mode(): string
{
    thin_exec_refresh_env();
    $m = strtolower(trim((string)(getenv('THIN_EXEC_MODE') ?: ($_ENV['THIN_EXEC_MODE'] ?? 'off'))));
    if (!in_array($m, ['off', 'shadow', 'canary', 'on'], true)) {
        return 'off';
    }
    return $m;
}

/** @return list<int> */
function thin_exec_canary_sids(): array
{
    thin_exec_refresh_env();
    $raw = trim((string)(getenv('THIN_EXEC_CANARY_SIDS') ?: ($_ENV['THIN_EXEC_CANARY_SIDS'] ?? '')));
    if ($raw === '') {
        return [];
    }
    $out = [];
    foreach (explode(',', $raw) as $p) {
        $n = (int)trim($p);
        if ($n > 0) {
            $out[$n] = $n;
        }
    }
    return array_values($out);
}

/** @return list<string> prefixes, e.g. SUB_SELL, MAIN_SELL, LADDER, BUY0 */
function thin_exec_action_prefixes(): array
{
    thin_exec_refresh_env();
    $raw = trim((string)(getenv('THIN_EXEC_ACTIONS') ?: ($_ENV['THIN_EXEC_ACTIONS'] ?? '')));
    if ($raw === '') {
        return [];
    }
    $out = [];
    foreach (explode(',', $raw) as $p) {
        $p = strtoupper(trim($p));
        if ($p !== '') {
            $out[] = $p;
        }
    }
    return $out;
}

function thin_exec_fallback_full(): bool
{
    thin_exec_refresh_env();
    $v = trim((string)(getenv('THIN_EXEC_FALLBACK_FULL') ?: ($_ENV['THIN_EXEC_FALLBACK_FULL'] ?? '1')));
    return $v !== '0' && strtolower($v) !== 'false';
}

function thin_exec_signal_matches_actions(string $signal): bool
{
    $signal = strtoupper(trim($signal));
    if ($signal === '') {
        return false;
    }
    $prefixes = thin_exec_action_prefixes();
    if ($prefixes === []) {
        return false;
    }
    foreach ($prefixes as $pre) {
        if ($signal === $pre || str_starts_with($signal, $pre)) {
            return true;
        }
    }
    return false;
}

/**
 * Чи sid/signal кандидат на thin (shadow або live).
 * При mode=off завжди false → лише full path.
 */
function thin_exec_is_candidate(int $strategyId, string $signal): bool
{
    $mode = thin_exec_mode();
    if ($mode === 'off') {
        return false;
    }
    if (!thin_exec_signal_matches_actions($signal)) {
        return false;
    }
    if ($mode === 'shadow' || $mode === 'on') {
        return true;
    }
    // canary
    if ($mode === 'canary') {
        return in_array($strategyId, thin_exec_canary_sids(), true);
    }
    return false;
}

/**
 * Live thin (реальний ордерний шлях) — canary/on + candidate action.
 * C0: SUB_SELL через thin_exec_try_sub_sell (handler).
 */
function thin_exec_live_enabled(int $strategyId, string $signal): bool
{
    $mode = thin_exec_mode();
    if ($mode === 'off' || $mode === 'shadow') {
        return false;
    }
    return thin_exec_is_candidate($strategyId, $signal);
}

function thin_exec_log(string $msg): void
{
    $root = dirname(__DIR__);
    $file = $root . '/logs/thin_exec.log';
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
}
