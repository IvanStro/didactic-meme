<?php
declare(strict_types=1);

use Dotenv\Dotenv;

/**
 * Багато хостингів тримають .env у /home/user/.env, а Dotenv у проєкті читає лише
 * /home/user/strim.in.ua/.env (dirname від www/api — це корінь сайту з www).
 * Якщо WhitePay-ключі лишилися «домашні» — підвантажуємо батьківський .env (immutable: не перезаписує вже задані змінні).
 */
function strim_whitepay_maybe_load_home_env(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $get = static function (string $k): string {
        $v = $_ENV[$k] ?? getenv($k);
        return is_string($v) ? trim($v) : '';
    };

    if ($get('WHITEPAY_ACCESS_TOKEN') !== '' && $get('WHITEPAY_PAYMENT_PAGE_SLUG') !== '' && $get('WHITEPAY_WEBHOOK_TOKEN') !== '') {
        return;
    }

    // __FILE__ = .../www/api/_whitepay_dotenv.php → на 3 рівні вгору = /home/bot20 (батько strim.in.ua)
    $homeDir = dirname(__DIR__, 3);
    $path = $homeDir . '/.env';
    if (!is_readable($path)) {
        return;
    }

    try {
        // open_basedir може заборонити батьківський каталог у web SAPI
        Dotenv::createImmutable($homeDir)->safeLoad();
    } catch (Throwable $e) {
        // ignore
    }
}
