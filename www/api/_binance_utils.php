<?php
declare(strict_types=1);

if (defined('BINANCE_UTILS_LOADED')) { return; }
define('BINANCE_UTILS_LOADED', true);

require_once __DIR__.'/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__, 2)); $dotenv->safeLoad();

/** envget */
if (!function_exists('envget')) {
  function envget(string $k, string $def = ''): string {
    foreach ([$_POST[$k] ?? null, getenv($k), $_ENV[$k] ?? null, $_SERVER[$k] ?? null] as $v) {
      if (is_string($v) && $v !== '') return trim($v);
    }
    return $def;
  }
}

/** bn_base */
if (!function_exists('bn_base')) {
  function bn_base(): string {
    return envget('BINANCE_TESTNET', '0') === '1'
      ? 'https://testnet.binance.vision'
      : 'https://api.binance.com';
  }
}

/** bn_keys */
if (!function_exists('bn_keys')) {
  function bn_keys(): array {
    $k = envget('BINANCE_API_KEY');
    $s = envget('BINANCE_API_SECRET');
    if ($k === '' || $s === '') throw new RuntimeException('BINANCE API keys missing');
    return [$k, $s];
  }
}

/** http_json */
if (!function_exists('http_json')) {
  function http_json(string $url, array $headers = [], string $method = 'GET', ?string $body = null): array {
    $ch = curl_init($url);
    $opt = [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT        => 20,
      CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
      CURLOPT_HTTPHEADER     => $headers,
      CURLOPT_CUSTOMREQUEST  => $method,
    ];
    if ($body !== null) { $opt[CURLOPT_POSTFIELDS] = $body; }
    curl_setopt_array($ch, $opt);
    $resp = curl_exec($ch);
    $errno = curl_errno($ch);
    $err = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$http, $resp, $errno, $err];
  }
}

/**
 * Parallel GETs (same shape as http_json per URL: [http, body, errno, err]).
 * Preserves numeric indices 0..n-1 for alignment with the input URL list.
 */
if (!function_exists('http_json_parallel_get')) {
  function http_json_parallel_get(array $urls, int $timeout = 15): array {
    if ($urls === []) {
      return [];
    }
    $mh = curl_multi_init();
    if ($mh === false) {
      return [];
    }
    $chs = [];
    foreach ($urls as $i => $url) {
      if (!is_string($url) || $url === '') {
        continue;
      }
      $ch = curl_init($url);
      if ($ch === false) {
        continue;
      }
      curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
      ]);
      curl_multi_add_handle($mh, $ch);
      $chs[$i] = $ch;
    }
    $active = null;
    do {
      $mrc = curl_multi_exec($mh, $active);
      if ($mrc !== CURLM_OK) {
        break;
      }
      if ($active) {
        curl_multi_select($mh, 1.0);
      }
    } while ($active > 0);

    $out = [];
    foreach ($chs as $i => $ch) {
      $body = (string)curl_multi_getcontent($ch);
      $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
      $errno = curl_errno($ch);
      $err = curl_error($ch);
      $out[$i] = [$http, $body, $errno, $err];
      curl_multi_remove_handle($mh, $ch);
      curl_close($ch);
    }
    curl_multi_close($mh);
    return $out;
  }
}

/** bn_server_time */
if (!function_exists('bn_server_time')) {
  function bn_server_time(): int {
    [$h, $r, $e, $er] = http_json(bn_base().'/api/v3/time');
    if ($e || $h !== 200) throw new RuntimeException('time: '.$er.' http='.$h);
    $j = json_decode((string)$r, true);
    return (int)($j['serverTime'] ?? 0);
  }
}

/** bn_exchange_info */
if (!function_exists('bn_exchange_info')) {
  function bn_exchange_info(string $symbol): array {
    [$h, $r, $e, $er] = http_json(bn_base().'/api/v3/exchangeInfo?symbol='.$symbol);
    if ($e || $h !== 200) throw new RuntimeException('exchangeInfo: '.$er.' http='.$h);
    $j = json_decode((string)$r, true);
    if (!isset($j['symbols'][0])) throw new RuntimeException('symbol not found');
    return $j['symbols'][0];
  }
}

/** apply_filters_amount */
if (!function_exists('apply_filters_amount')) {
  function apply_filters_amount(float $qty, array $symbolInfo): float {
    $step = null; $minQty = null; $maxQty = null;
    foreach ($symbolInfo['filters'] ?? [] as $f) {
      if (($f['filterType'] ?? '') === 'LOT_SIZE') {
        $step   = (float)$f['stepSize'];
        $minQty = (float)$f['minQty'];
        $maxQty = (float)$f['maxQty'];
      }
    }
    if ($step && $step>0) { $qty = floor($qty / $step) * $step; }
    if ($minQty && $qty < $minQty) throw new RuntimeException('qty < minQty');
    if ($maxQty && $qty > $maxQty) throw new RuntimeException('qty > maxQty');
    return (float)number_format($qty, 8, '.', '');
  }
}

/** check_min_notional */
if (!function_exists('check_min_notional')) {
  function check_min_notional(float $qty, float $price, array $symbolInfo): void {
    foreach ($symbolInfo['filters'] ?? [] as $f) {
      if (($f['filterType'] ?? '') === 'NOTIONAL') {
        $minN = (float)($f['minNotional'] ?? 0);
        if ($minN > 0 && ($qty * $price) < $minN) throw new RuntimeException('notional too small');
      }
    }
  }
}

/** bn_sign_query */
if (!function_exists('bn_sign_query')) {
  function bn_sign_query(array $params, string $secret): string {
    ksort($params);
    $q = http_build_query($params, '', '&');
    $sig = hash_hmac('sha256', $q, $secret);
    return $q.'&signature='.$sig;
  }
}
