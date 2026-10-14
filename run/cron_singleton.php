<?php
declare(strict_types=1);

/**
 * Неблокуючий singleton для cron (ADM.TOOLS: Concurrent script execution blocked).
 * Якщо попередній запуск ще триває — вихід 0 за <1 мс.
 * Завислий lock (мертвий PID або старіший за CRON_SINGLETON_STALE_SEC) — знімається.
 */
function cron_singleton_pid_alive(int $pid): bool
{
    if ($pid <= 0) {
        return false;
    }
    if (function_exists('posix_kill')) {
        return @posix_kill($pid, 0);
    }
    return is_dir('/proc/' . $pid);
}

function cron_singleton_acquire(string $name): bool
{
    $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $name);
    $dir = dirname(__DIR__) . '/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $path = $dir . '/cron_singleton_' . $safe . '.lock';
    $defaultStale = ($safe === 'cron_audit_orphan_sells') ? 900 : 300;
    $staleSec = max(90, (int)(getenv('CRON_SINGLETON_STALE_SEC') ?: ($_ENV['CRON_SINGLETON_STALE_SEC'] ?? $defaultStale)));
    if (is_file($path)) {
        $age = time() - (int)(filemtime($path) ?: 0);
        $pid = 0;
        $raw = @file_get_contents($path);
        if (is_string($raw) && preg_match('/^(\d+)/', trim($raw), $m)) {
            $pid = (int)$m[1];
        }
        $deadPid = ($pid > 0 && !cron_singleton_pid_alive($pid));
        $agedOut = ($age > $staleSec);
        if ($agedOut || $deadPid || $pid <= 0) {
            @unlink($path);
            // Лог лише коли lock реально «завис» по віку — не після кожного нормального exit
            // (файл лишався з мертвим PID → stale_lock_cleared щохвилини для ws_price).
            if ($agedOut) {
                echo date('c') . " cron_singleton stale_lock_cleared name={$safe} age={$age}s pid={$pid}\n";
            }
        }
    }
    $fp = fopen($path, 'c+');
    if ($fp === false) {
        return true;
    }
    if (!flock($fp, LOCK_EX | LOCK_NB)) {
        fclose($fp);
        echo date('c') . " cron_singleton skip name={$safe} (already running)\n";
        exit(0);
    }
    ftruncate($fp, 0);
    fwrite($fp, (string)getmypid() . ' ' . date('c') . "\n");
    fflush($fp);
    $GLOBALS['_cron_singleton_fp'] = $fp;
    $GLOBALS['_cron_singleton_path'] = $path;
    return true;
}

function cron_singleton_release(): void
{
    $path = isset($GLOBALS['_cron_singleton_path']) ? (string)$GLOBALS['_cron_singleton_path'] : '';
    if (!empty($GLOBALS['_cron_singleton_fp']) && is_resource($GLOBALS['_cron_singleton_fp'])) {
        flock($GLOBALS['_cron_singleton_fp'], LOCK_UN);
        fclose($GLOBALS['_cron_singleton_fp']);
        unset($GLOBALS['_cron_singleton_fp']);
    }
    // Прибрати файл після нормального завершення — інакше наступний cron бачить мертвий PID.
    if ($path !== '' && is_file($path)) {
        @unlink($path);
    }
    unset($GLOBALS['_cron_singleton_path']);
}

register_shutdown_function(static function (): void {
    cron_singleton_release();
});
