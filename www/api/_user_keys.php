<?php

use Dotenv\Dotenv;

/**
 * Розшифровка, дзеркальна до enc() у save_user.php
 */
function dec($ciphertextB64, $key) {
  $raw = base64_decode($ciphertextB64, true);
  if ($raw === false || strlen($raw) < 17) {
    return null;
  }

  $iv     = substr($raw, 0, 16);
  $cipher = substr($raw, 16);

  $plain = openssl_decrypt(
    $cipher,
    'AES-256-CBC',
    hash('sha256', $key, true),
    OPENSSL_RAW_DATA,
    $iv
  );

  if ($plain === false) {
    return null;
  }

  return $plain;
}

/**
 * MASTER_KEY for encrypting user Binance credentials.
 * Refuses empty / default "changeme". Temporary legacy decrypt may still try changeme
 * only when STRIM_ALLOW_LEGACY_MASTER=1 (migration window).
 */
function strim_master_key(bool $allowLegacyFallback = false): string {
  if (!isset($_ENV['MASTER_KEY']) && !array_key_exists('MASTER_KEY', $_ENV)) {
    if (!class_exists('Dotenv\\Dotenv')) {
      require __DIR__ . '/../vendor/autoload.php';
    }
    $dotenv = Dotenv::createImmutable(dirname(__DIR__, 2));
    $dotenv->safeLoad();
  }

  $masterKey = trim((string)($_ENV['MASTER_KEY'] ?? ''));
  if ($masterKey !== '' && strcasecmp($masterKey, 'changeme') !== 0 && strlen($masterKey) >= 16) {
    return $masterKey;
  }

  if ($allowLegacyFallback || (string)($_ENV['STRIM_ALLOW_LEGACY_MASTER'] ?? '') === '1') {
    // Historical default used when MASTER_KEY was unset.
    return 'changeme';
  }

  throw new RuntimeException('MASTER_KEY missing or weak (set a random >=16 char secret in .env)');
}

/**
 * Повертає [apiKey, apiSecret] для поточного user_id з таблиці users (зашифроване зберігання).
 * Якщо нічого немає або не вдалось розшифрувати — [null, null].
 */
function user_keys($db, $uid) {
  if ($uid <= 0) {
    return array(null, null);
  }

  try {
    $masterKey = strim_master_key(true);
  } catch (Throwable $e) {
    error_log('user_keys: ' . $e->getMessage());
    return array(null, null);
  }

  $stmt = $db->prepare("SELECT api_key_enc, api_secret_enc FROM users WHERE id = ? LIMIT 1");
  if (!$stmt) {
    return array(null, null);
  }

  $stmt->bind_param('i', $uid);
  if (!$stmt->execute()) {
    return array(null, null);
  }

  $res = $stmt->get_result();
  if (!$res) {
    return array(null, null);
  }

  $row = $res->fetch_assoc();
  if (!$row) {
    return array(null, null);
  }

  $apiKeyEnc = '';
  if (isset($row['api_key_enc'])) {
    $apiKeyEnc = trim((string)$row['api_key_enc']);
  }

  $apiSecretEnc = '';
  if (isset($row['api_secret_enc'])) {
    $apiSecretEnc = trim((string)$row['api_secret_enc']);
  }

  if ($apiKeyEnc === '' || $apiSecretEnc === '') {
    return array(null, null);
  }

  // Prefer configured key; if that fails and legacy allowed, try changeme.
  $apiKey    = dec($apiKeyEnc, $masterKey);
  $apiSecret = dec($apiSecretEnc, $masterKey);
  if (($apiKey === null || $apiSecret === null) && $masterKey !== 'changeme') {
    $apiKey    = dec($apiKeyEnc, 'changeme');
    $apiSecret = dec($apiSecretEnc, 'changeme');
  }

  if ($apiKey === null || $apiSecret === null || $apiKey === '' || $apiSecret === '') {
    return array(null, null);
  }

  return array($apiKey, $apiSecret);
}
