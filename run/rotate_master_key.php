<?php
declare(strict_types=1);

/**
 * One-shot: re-encrypt users.api_*_enc from legacy MASTER_KEY (changeme)
 * to a new strong MASTER_KEY, then print the key for .env (does not write .env itself
 * unless --write-env is passed).
 *
 * Usage:
 *   php run/rotate_master_key.php              # dry-run check
 *   php run/rotate_master_key.php --apply      # re-encrypt + print new key
 *   php run/rotate_master_key.php --apply --write-env
 */

$root = dirname(__DIR__);
require $root . '/www/vendor/autoload.php';
require_once $root . '/www/api/_user_keys.php';

Dotenv\Dotenv::createImmutable($root)->safeLoad();

$apply = in_array('--apply', $argv, true);
$writeEnv = in_array('--write-env', $argv, true);

$db = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
if ($db->connect_errno) {
    fwrite(STDERR, "db connect failed\n");
    exit(1);
}
$db->set_charset('utf8mb4');

$oldKey = 'changeme';
$envKey = trim((string)($_ENV['MASTER_KEY'] ?? ''));
if ($envKey !== '' && strcasecmp($envKey, 'changeme') !== 0 && strlen($envKey) >= 16) {
    fwrite(STDERR, "MASTER_KEY already set (len=" . strlen($envKey) . "). Nothing to rotate.\n");
    // Still verify decrypt works
    $row = $db->query("SELECT id, api_key_enc FROM users WHERE api_key_enc<>'' LIMIT 1")->fetch_assoc();
    if ($row) {
        $ok = dec((string)$row['api_key_enc'], $envKey) !== null;
        echo $ok ? "decrypt_ok with current MASTER_KEY\n" : "WARN: cannot decrypt with current MASTER_KEY\n";
    }
    exit($ok ?? true ? 0 : 2);
}

function enc_rot(string $plaintext, string $key): string {
    $iv = random_bytes(16);
    $cipher = openssl_encrypt($plaintext, 'AES-256-CBC', hash('sha256', $key, true), OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $cipher);
}

$res = $db->query("SELECT id, api_key_enc, api_secret_enc FROM users WHERE api_key_enc IS NOT NULL AND api_key_enc<>'' AND api_secret_enc IS NOT NULL AND api_secret_enc<>''");
$rows = [];
while ($res && ($r = $res->fetch_assoc())) {
    $rows[] = $r;
}

$okCount = 0;
$fail = [];
foreach ($rows as $r) {
    $k = dec((string)$r['api_key_enc'], $oldKey);
    $s = dec((string)$r['api_secret_enc'], $oldKey);
    if ($k === null || $s === null || $k === '' || $s === '') {
        $fail[] = (int)$r['id'];
        continue;
    }
    $okCount++;
}

echo "users_with_keys=" . count($rows) . " decrypt_ok_legacy={$okCount} fail=" . count($fail) . "\n";
if ($fail) {
    echo "fail_ids=" . implode(',', $fail) . "\n";
}

if (!$apply) {
    echo "dry-run only. Re-run with --apply to rotate.\n";
    exit(count($fail) ? 2 : 0);
}

$newKey = bin2hex(random_bytes(24)); // 48 hex chars
$updated = 0;
foreach ($rows as $r) {
    $id = (int)$r['id'];
    $k = dec((string)$r['api_key_enc'], $oldKey);
    $s = dec((string)$r['api_secret_enc'], $oldKey);
    if ($k === null || $s === null) {
        continue;
    }
    $ke = enc_rot($k, $newKey);
    $se = enc_rot($s, $newKey);
    $stmt = $db->prepare('UPDATE users SET api_key_enc=?, api_secret_enc=? WHERE id=?');
    $stmt->bind_param('ssi', $ke, $se, $id);
    if ($stmt->execute()) {
        $updated++;
    }
}

echo "reencrypted={$updated}\n";
echo "NEW_MASTER_KEY={$newKey}\n";

if ($writeEnv) {
    $envPath = $root . '/.env';
    $raw = is_readable($envPath) ? (string)file_get_contents($envPath) : '';
    if (preg_match('/^MASTER_KEY=.*$/m', $raw)) {
        $raw = preg_replace('/^MASTER_KEY=.*$/m', 'MASTER_KEY=' . $newKey, $raw, 1);
    } else {
        $raw = rtrim($raw) . "\nMASTER_KEY=" . $newKey . "\n";
    }
    // Drop legacy flag if present
    $raw = preg_replace('/^STRIM_ALLOW_LEGACY_MASTER=.*$/m', '', $raw);
    file_put_contents($envPath, $raw);
    echo "wrote {$envPath}\n";
}

// verify
$chk = $db->query("SELECT id, api_key_enc FROM users WHERE api_key_enc<>'' LIMIT 1")->fetch_assoc();
if ($chk) {
    $plain = dec((string)$chk['api_key_enc'], $newKey);
    echo $plain ? "verify_ok uid={$chk['id']}\n" : "VERIFY_FAIL\n";
}
