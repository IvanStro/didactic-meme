<?php
declare(strict_types=1);

require_once __DIR__ . '/_google_ads_config.php';

/**
 * Секрет Measurement Protocol API (GA4 → Admin → Data streams → Measurement Protocol).
 * Підтримує legacy-ключ у .env: Measurement_Protocol_API
 */
function ga4_mp_api_secret(): string
{
    foreach ([
        'GA4_MEASUREMENT_PROTOCOL_API_SECRET',
        'Measurement_Protocol_API',
        'GA4_API_SECRET',
    ] as $key) {
        $v = trim((string) ($_ENV[$key] ?? getenv($key) ?: ''));
        if ($v !== '') {
            return $v;
        }
    }

    return '';
}

/** Чи можна слати події server-side у GA4. */
function ga4_mp_is_configured(): bool
{
    return ga4_measurement_id() !== '' && ga4_mp_api_secret() !== '';
}

/**
 * @param array<string, scalar|null> $params
 */
function ga4_mp_send_event(string $clientId, string $eventName, array $params = []): array
{
    $measurementId = ga4_measurement_id();
    $apiSecret = ga4_mp_api_secret();
    if ($measurementId === '' || $apiSecret === '') {
        return ['ok' => false, 'http' => 0, 'error' => 'ga4_mp_not_configured'];
    }
    $clientId = trim($clientId);
    $eventName = preg_replace('/[^a-zA-Z0-9_]/', '', $eventName) ?? '';
    if ($clientId === '' || $eventName === '') {
        return ['ok' => false, 'http' => 0, 'error' => 'invalid_client_or_event'];
    }

    $cleanParams = [];
    foreach ($params as $k => $v) {
        if (!is_string($k) || $k === '') {
            continue;
        }
        if (is_bool($v)) {
            $cleanParams[$k] = $v;
        } elseif (is_int($v) || is_float($v)) {
            $cleanParams[$k] = $v;
        } elseif (is_string($v) && $v !== '') {
            $cleanParams[$k] = $v;
        }
    }

    $url = 'https://www.google-analytics.com/mp/collect'
        . '?measurement_id=' . rawurlencode($measurementId)
        . '&api_secret=' . rawurlencode($apiSecret);

    $body = json_encode([
        'client_id' => $clientId,
        'events' => [[
            'name' => $eventName,
            'params' => $cleanParams,
        ]],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($body)) {
        return ['ok' => false, 'http' => 0, 'error' => 'json_encode_failed'];
    }

    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => $body,
            'timeout' => 8,
            'ignore_errors' => true,
        ],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    $http = 0;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', (string) $http_response_header[0], $m)) {
        $http = (int) $m[1];
    }

    return [
        'ok' => $http >= 200 && $http < 300,
        'http' => $http,
        'error' => ($http >= 200 && $http < 300) ? '' : ('http_' . $http),
        'body' => is_string($raw) ? substr($raw, 0, 200) : '',
    ];
}
