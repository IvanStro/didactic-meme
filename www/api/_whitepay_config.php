<?php
declare(strict_types=1);

if (defined('STRIM_WHITEPAY_CONFIG_LOADED')) {
    return;
}
define('STRIM_WHITEPAY_CONFIG_LOADED', true);

function whitepay_env(string $k, string $def = ''): string
{
    foreach ([getenv($k), $_ENV[$k] ?? null, $_SERVER[$k] ?? null] as $v) {
        if (is_string($v) && $v !== '') {
            return trim($v);
        }
    }
    return $def;
}

function whitepay_amount_uah(): float
{
    $n = (float) whitepay_env('WHITEPAY_AMOUNT_UAH', '500');
    if ($n < 1) {
        $n = 500.0;
    }
    return $n;
}

function whitepay_slug(): string
{
    return whitepay_env('WHITEPAY_PAYMENT_PAGE_SLUG', '');
}

/**
 * Slug у шляху POST /private-api/crypto-orders/{slug} (з вкладки платіжної сторінки в CRM).
 * Якщо порожньо — береться WHITEPAY_PAYMENT_PAGE_SLUG (інколи в CRM інший ідентифікатор, ніж у піддомені).
 */
function whitepay_crypto_api_slug(): string
{
    $s = whitepay_env('WHITEPAY_CRYPTO_API_SLUG', '');
    return $s !== '' ? $s : whitepay_slug();
}

function whitepay_access_token(): string
{
    return whitepay_env('WHITEPAY_ACCESS_TOKEN', '');
}

function whitepay_webhook_token(): string
{
    return whitepay_env('WHITEPAY_WEBHOOK_TOKEN', '');
}

function whitepay_api_create_url(): string
{
    return whitepay_env('WHITEPAY_API_CREATE_URL', '');
}

/**
 * База HTTP API WhitePay для crypto acquiring (див. документацію).
 * @see https://docs.whitepay.com/NIAMpzZRbwAoMUawtJRH/http-api/crypto-acquiring
 */
function whitepay_api_base_url(): string
{
    return whitepay_env('WHITEPAY_API_BASE_URL', 'https://api.whitepay.com');
}

/** Валюта для POST /private-api/crypto-orders/{slug} (ticker, напр. UAH). */
function whitepay_invoice_currency(): string
{
    $c = whitepay_env('WHITEPAY_INVOICE_CURRENCY', 'UAH');
    return $c !== '' ? strtoupper($c) : 'UAH';
}

/**
 * Якщо задано повний WHITEPAY_API_CREATE_URL — використовується він (legacy).
 * Інакше: POST {WHITEPAY_API_BASE_URL}/private-api/crypto-orders/{slug}.
 */
function whitepay_crypto_order_create_endpoint(string $slug): string
{
    $slug = trim($slug);
    if ($slug === '') {
        return '';
    }
    $base = rtrim(whitepay_api_base_url(), '/');
    if ($base === '') {
        return '';
    }
    return $base . '/private-api/crypto-orders/' . rawurlencode($slug);
}

/** Повна URL оплати без REST (hosted). Якщо порожньо — збирається з slug (корінь сторінки, як у кабінеті WhitePay). */
function whitepay_hosted_pay_url(): string
{
    $u = whitepay_env('WHITEPAY_HOSTED_PAY_URL', '');
    if ($u !== '') {
        return rtrim($u, '?&');
    }
    $slug = whitepay_slug();
    if ($slug === '') {
        return '';
    }
    return 'https://' . $slug . '.pay.whitepay.com/';
}

function whitepay_success_url(): string
{
    $u = whitepay_env('WHITEPAY_SUCCESS_URL', 'https://strim.in.ua/payment/wait.php');
    return $u !== '' ? $u : 'https://strim.in.ua/payment/wait.php';
}

function whitepay_fail_url(): string
{
    $u = whitepay_env('WHITEPAY_FAIL_URL', 'https://strim.in.ua/tariffs.html?pay=fail');
    return $u !== '' ? $u : 'https://strim.in.ua/tariffs.html?pay=fail';
}

function whitepay_build_external_id(int $userId): string
{
    return 'strim-u' . $userId . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(3));
}

/** WhitePay external_order_id: лише [a-zA-Z0-9_-], max 255. */
function whitepay_external_order_id_for_api(string $externalId): string
{
    $s = preg_replace('/[^a-zA-Z0-9_-]/', '', $externalId) ?? '';
    if (strlen($s) > 255) {
        $s = substr($s, 0, 255);
    }
    if ($s === '') {
        $s = 'strim-' . bin2hex(random_bytes(8));
    }
    return $s;
}

/** Сума для WhitePay API (десятковий рядок із крапкою, зазвичай 2 знаки). */
function whitepay_amount_string_for_api(float $amount): string
{
    return number_format($amount, 2, '.', '');
}

/** Якщо 1 — у crypto-acquiring не передаються successful_link / failure_link (для тесту, якщо CRM відхиляє домен). */
function whitepay_crypto_omit_redirect_links(): bool
{
    return whitepay_env('WHITEPAY_CRYPTO_OMIT_REDIRECTS', '0') === '1';
}

/**
 * Якщо перший crypto-запит повернув 422 — повторити з трьома полями (amount, currency, external_order_id).
 * Вимкнути: WHITEPAY_CRYPTO_NO_422_RETRY=1.
 */
function whitepay_crypto_retry_minimal_on_422(): bool
{
    return whitepay_env('WHITEPAY_CRYPTO_NO_422_RETRY', '0') !== '1';
}
