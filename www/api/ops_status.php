<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
use Dotenv\Dotenv;

header('Content-Type: application/json; charset=utf-8');

try {
    $dotenv = Dotenv::createImmutable(dirname(__DIR__, 2));
    $dotenv->safeLoad();

    $secret = (string) ($_ENV['OPS_STATUS_SECRET'] ?? '');
    $hdr = isset($_SERVER['HTTP_X_OPS_SECRET']) ? (string) $_SERVER['HTTP_X_OPS_SECRET'] : '';
    $q = isset($_GET['secret']) ? (string) $_GET['secret'] : '';
    $incoming = $hdr !== '' ? $hdr : $q;

    if ($secret === '' || $incoming === '' || !hash_equals($secret, $incoming)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $mysqli = @new mysqli(
        (string) ($_ENV['DB_HOST'] ?? ''),
        (string) ($_ENV['DB_USER'] ?? ''),
        (string) ($_ENV['DB_PASS'] ?? ''),
        (string) ($_ENV['DB_NAME'] ?? '')
    );
    if ($mysqli->connect_errno) {
        http_response_code(503);
        echo json_encode(['ok' => false, 'error' => 'db_connect_failed'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $mysqli->set_charset('utf8mb4');

    $out = [
        'ok' => true,
        'checked_at' => date('Y-m-d H:i:s'),
        'db_ok' => true,
    ];

    $res = $mysqli->query('SELECT MAX(last_tick_at) AS last_tick FROM strategies');
    $row = $res ? $res->fetch_assoc() : null;
    $last = $row && $row['last_tick'] ? strtotime((string) $row['last_tick']) : null;
    $now = time();
    $lag = $last ? ($now - $last) : null;
    $out['engine'] = [
        'alive' => ($last !== null && $lag !== null && $lag <= 120),
        'last_tick_at' => $row['last_tick'] ?? null,
        'lag_seconds' => $lag,
    ];

    $tq = $mysqli->query("SHOW TABLES LIKE 'job_queue'");
    if ($tq && $tq->num_rows > 0) {
        $counts = ['queued' => 0, 'processing' => 0, 'done' => 0, 'failed' => 0];
        $rc = $mysqli->query('SELECT status, COUNT(*) AS c FROM job_queue GROUP BY status');
        if ($rc) {
            while ($c = $rc->fetch_assoc()) {
                $st = (string) $c['status'];
                if (isset($counts[$st])) {
                    $counts[$st] = (int) $c['c'];
                }
            }
        }
        $failed1h = 0;
        $rf = $mysqli->query(
            "SELECT COUNT(*) AS c FROM job_queue WHERE status='failed' AND updated_at >= (NOW() - INTERVAL 1 HOUR)"
        );
        if ($rf && ($f = $rf->fetch_assoc())) {
            $failed1h = (int) $f['c'];
        }
        $oldestSec = null;
        $ro = $mysqli->query(
            "SELECT TIMESTAMPDIFF(SECOND, MIN(available_at), NOW()) AS d FROM job_queue WHERE status='queued'"
        );
        if ($ro && ($o = $ro->fetch_assoc()) && $o['d'] !== null) {
            $oldestSec = (int) $o['d'];
        }
        $out['job_queue'] = array_merge($counts, [
            'failed_last_hour' => $failed1h,
            'oldest_queued_age_seconds' => $oldestSec,
        ]);
    }

    $sq = $mysqli->query("SHOW TABLES LIKE 'signal_queue'");
    if ($sq && $sq->num_rows > 0) {
        $sc = ['queued' => 0, 'processing' => 0, 'done' => 0, 'failed' => 0];
        $r2 = $mysqli->query('SELECT status, COUNT(*) AS c FROM signal_queue GROUP BY status');
        if ($r2) {
            while ($c = $r2->fetch_assoc()) {
                $st = (string) $c['status'];
                if (isset($sc[$st])) {
                    $sc[$st] = (int) $c['c'];
                }
            }
        }
        $out['signal_queue'] = $sc;
    }

    echo json_encode($out, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('ops_status: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server_error'], JSON_UNESCAPED_UNICODE);
}
