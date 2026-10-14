<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../auth/_session_user.php';
require_once __DIR__ . '/_csrf.php';
require_once __DIR__ . '/_worker_manager.php';

Dotenv\Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();
header('Content-Type: application/json; charset=utf-8');

$mysqli = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
if ($mysqli->connect_errno) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'db connect error']);
  exit;
}
$uid = current_user_id($mysqli);
if ($uid <= 0) {
  http_response_code(401);
  echo json_encode(['ok' => false, 'error' => 'auth']);
  exit;
}
$admRes = $mysqli->query("SELECT is_admin FROM users WHERE id=" . (int)$uid . " LIMIT 1");
$admRow = $admRes ? $admRes->fetch_assoc() : null;
if (!$admRow || (int)($admRow['is_admin'] ?? 0) !== 1) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'error' => 'forbidden']);
  exit;
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
try {
  $settings = worker_get_settings($mysqli);
  $target = (int)$settings['target'];
  $auto = (int)$settings['auto'];

  if ($method === 'POST') {
    require_csrf_or_403();
    $action = trim((string)($_POST['action'] ?? ''));
    $newTarget = isset($_POST['target']) ? max(1, min(32, (int)$_POST['target'])) : $target;
    $msg = '';
    $details = [];

    if ($action === 'start') {
      $r = worker_start_missing($newTarget);
      $msg = count($r['started']) ? ('Успішно запущено: ' . count($r['started'])) : 'Воркери вже запущені';
      $details = $r;
      worker_set_setting($mysqli, 'worker_target_count', (string)$newTarget);
    } elseif ($action === 'pause') {
      $r = worker_stop_all();
      $msg = count($r['stopped']) ? ('Успішно зупинено: ' . count($r['stopped'])) : 'Немає активних воркерів';
      $details = $r;
    } elseif ($action === 'restart') {
      $r = worker_restart($newTarget);
      $msg = 'Успішний перезапуск';
      $details = $r;
      worker_set_setting($mysqli, 'worker_target_count', (string)$newTarget);
    } elseif ($action === 'set_auto') {
      $newAuto = (int)($_POST['auto'] ?? 0) ? 1 : 0;
      worker_set_setting($mysqli, 'worker_auto_enabled', (string)$newAuto);
      $msg = 'Режим автооновлення збережено';
      $details = ['auto' => $newAuto];
    } elseif ($action === 'dry_run') {
      $settingsNow = worker_get_settings($mysqli);
      $targetNow = isset($_POST['target']) ? max(1, min(32, (int)$_POST['target'])) : (int)$settingsNow['target'];
      $autoNow = isset($_POST['auto']) ? (((int)$_POST['auto']) ? 1 : 0) : (int)$settingsNow['auto'];
      $runningNow = worker_running_pids();
      $queuedNow = 0;
      $q = $mysqli->query("SELECT COUNT(*) AS c FROM job_queue WHERE status='queued'");
      if ($q && ($qr = $q->fetch_assoc())) $queuedNow = (int)($qr['c'] ?? 0);
      $need = max(0, $targetNow - count($runningNow));
      $wouldStart = ($autoNow === 1 && $queuedNow > 0 && $need > 0);
      if ($wouldStart) {
        $msg = "DRY-RUN: буде запущено {$need} воркер(ів) (queued={$queuedNow}, running=" . count($runningNow) . ", target={$targetNow})";
      } else {
        $msg = "DRY-RUN: автозапуск не спрацює (auto={$autoNow}, queued={$queuedNow}, running=" . count($runningNow) . ", target={$targetNow})";
      }
      $details = [
        'dry_run' => true,
        'would_start' => $wouldStart,
        'would_start_count' => $wouldStart ? $need : 0,
        'queued' => $queuedNow,
        'running' => count($runningNow),
        'target' => $targetNow,
        'auto' => $autoNow,
      ];
    } elseif ($action === 'cleanup_template_queue') {
      $apply = ((int)($_POST['apply'] ?? 0)) === 1;
      $cleanup = worker_cleanup_template_queue($mysqli, $apply);
      $msg = $apply
        ? ('Прибрано TEMPLATE з черги: ' . (int)$cleanup['updated'] . ' job(s)')
        : ('Перевірка: знайдено ' . (int)$cleanup['found'] . ' TEMPLATE job(s) у queued/processing');
      $details = $cleanup;
    } else {
      echo json_encode(['ok' => false, 'error' => 'unknown action']);
      exit;
    }

    $settings = worker_get_settings($mysqli);
    $running = worker_running_pids();
    $workers = [];
    foreach ($running as $pid) {
      $workers[] = ['pid' => $pid, 'uptime_sec' => worker_uptime_sec($pid), 'status' => 'running', 'role' => 'queue worker'];
    }
    echo json_encode([
      'ok' => true,
      'message' => $msg,
      'details' => $details,
      'settings' => $settings,
      'workers' => $workers,
      'queue_by_strategy' => worker_queue_by_strategy($mysqli),
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // GET status (includes auto-heal if enabled)
  $queued = 0;
  $processing = 0;
  $jq = $mysqli->query("SELECT status, COUNT(*) AS c FROM job_queue WHERE status IN ('queued','processing') GROUP BY status");
  if ($jq) {
    while ($row = $jq->fetch_assoc()) {
      $st = (string)($row['status'] ?? '');
      $c = (int)($row['c'] ?? 0);
      if ($st === 'queued') $queued = $c;
      if ($st === 'processing') $processing = $c;
    }
  }
  $runningBefore = worker_running_pids();
  $autoTriggered = false;
  if ($auto === 1 && count($runningBefore) < $target && $queued > 0) {
    worker_start_missing($target);
    $autoTriggered = true;
  }
  $running = worker_running_pids();
  $workers = [];
  foreach ($running as $pid) {
    $workers[] = [
      'pid' => $pid,
      'uptime_sec' => worker_uptime_sec($pid),
      'status' => 'running',
      'role' => 'queue worker',
    ];
  }
  echo json_encode([
    'ok' => true,
    'settings' => ['target' => $target, 'auto' => $auto],
    'queue' => ['queued' => $queued, 'processing' => $processing],
    'queue_by_strategy' => worker_queue_by_strategy($mysqli),
    'auto_triggered' => $autoTriggered,
    'workers' => $workers,
  ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode([
    'ok' => false,
    'error' => 'worker_api_exception',
    'message' => $e->getMessage(),
  ], JSON_UNESCAPED_UNICODE);
}
