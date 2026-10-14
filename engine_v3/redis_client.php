<?php
declare(strict_types=1);

require_once __DIR__ . '/queue_config.php';

/**
 * Predis client (опційно). Якщо Redis недоступний — null, fallback на MySQL.
 */
function redis_client(): ?\Predis\Client
{
    static $client = null;
    static $failed = false;

    if ($failed) {
        return null;
    }
    if ($client instanceof \Predis\Client) {
        return $client;
    }

    if (!class_exists(\Predis\Client::class)) {
        $failed = true;
        return null;
    }

    $url = trim((string)(getenv('REDIS_URL') ?: ($_ENV['REDIS_URL'] ?? '')));
    $socket = trim((string)(getenv('REDIS_SOCKET') ?: ($_ENV['REDIS_SOCKET'] ?? '')));

    // ADM.TOOLS: unix:///home/bot20/.system/redis.sock
    if ($url !== '' && str_starts_with($url, 'unix://')) {
        $socket = preg_replace('#^unix://#', '', $url);
        $url = '';
    }
    if ($socket === '' && $url === '') {
        $defaultSock = '/home/bot20/.system/redis.sock';
        if (is_readable($defaultSock)) {
            $socket = $defaultSock;
        }
    }

    $options = [
        'read_write_timeout' => 2,
        'connection_timeout' => 2,
    ];

    try {
        if ($socket !== '' && is_readable($socket)) {
            $client = new \Predis\Client([
                'scheme' => 'unix',
                'path' => $socket,
            ], $options);
        } elseif ($url !== '') {
            $client = new \Predis\Client($url, $options);
        } else {
            $failed = true;
            return null;
        }
        $client->ping();
        return $client;
    } catch (Throwable $e) {
        $failed = true;
        error_log('redis_client: ' . $e->getMessage());
        return null;
    }
}

function redis_available(): bool
{
    return redis_client() !== null;
}
