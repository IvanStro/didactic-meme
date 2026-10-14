<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../auth/_session_user.php';
require_once __DIR__ . '/_adm_tools_client.php';

Dotenv\Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();
header('Content-Type: application/json; charset=utf-8');

$mysqli = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
if ($mysqli->connect_errno) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'db connect error']);
  exit;
}
$mysqli->set_charset('utf8mb4');

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

$checks = [];
$criticalFail = 0;
$warnCount = 0;
$okCount = 0;
$push = function(string $key, string $label, string $status, string $detail, array $meta = []) use (&$checks, &$criticalFail, &$warnCount, &$okCount): void {
  $checks[] = ['key' => $key, 'label' => $label, 'status' => $status, 'detail' => $detail, 'meta' => $meta];
  if ($status === 'fail') $criticalFail++;
  elseif ($status === 'warn') $warnCount++;
  else $okCount++;
};

// Job queue quick snapshot (used by multiple checks)
$jqQueued = 0;
$jqProcessing = 0;
$jqDone = 0;
$jqFailed = 0;
$jqLastUpdate = null;
$jqRes = $mysqli->query("
  SELECT
    SUM(CASE WHEN status='queued' THEN 1 ELSE 0 END) AS queued,
    SUM(CASE WHEN status='processing' THEN 1 ELSE 0 END) AS processing,
    SUM(CASE WHEN status='done' THEN 1 ELSE 0 END) AS done_cnt,
    SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) AS failed_cnt,
    MAX(updated_at) AS last_update
  FROM job_queue
");
if ($jqRes && ($jqr = $jqRes->fetch_assoc())) {
  $jqQueued = (int)($jqr['queued'] ?? 0);
  $jqProcessing = (int)($jqr['processing'] ?? 0);
  $jqDone = (int)($jqr['done_cnt'] ?? 0);
  $jqFailed = (int)($jqr['failed_cnt'] ?? 0);
  $jqLastUpdate = !empty($jqr['last_update']) ? (string)$jqr['last_update'] : null;
}

// Worker status (DB-based heuristic)
if ($jqProcessing > 0) {
  $push('worker_status', 'Фонові процеси торгівлі', 'ok', "Працюють: обробляється {$jqProcessing} завдань", [
    'queued' => $jqQueued,
    'processing' => $jqProcessing,
    'done' => $jqDone,
    'failed' => $jqFailed,
    'last_job_update' => $jqLastUpdate,
  ]);
} else {
  if ($jqLastUpdate !== null) {
    $jobLag = time() - strtotime($jqLastUpdate);
    if ($jobLag <= 300) {
      $push('worker_status', 'Фонові процеси торгівлі', 'ok', "Черга активна, оновлено {$jobLag} с тому", [
        'queued' => $jqQueued,
        'processing' => $jqProcessing,
        'done' => $jqDone,
        'failed' => $jqFailed,
        'last_job_update' => $jqLastUpdate,
      ]);
    } elseif ($jqQueued > 0) {
      $push('worker_status', 'Фонові процеси торгівлі', 'fail', "У черзі {$jqQueued} завдань, але ніхто не обробляє ({$jobLag} с без руху)", [
        'queued' => $jqQueued,
        'processing' => $jqProcessing,
        'done' => $jqDone,
        'failed' => $jqFailed,
        'last_job_update' => $jqLastUpdate,
      ]);
    } else {
      $push('worker_status', 'Фонові процеси торгівлі', 'warn', "Зараз простій (немає завдань у роботі), останнє оновлення {$jobLag} с тому", [
        'queued' => $jqQueued,
        'processing' => $jqProcessing,
        'done' => $jqDone,
        'failed' => $jqFailed,
        'last_job_update' => $jqLastUpdate,
      ]);
    }
  } else {
    $push('worker_status', 'Фонові процеси торгівлі', 'warn', 'Недостатньо даних у черзі завдань');
  }
}

// Engine last tick (with running + queue-aware fallback)
$runningStrategies = 0;
$runRes = $mysqli->query("SELECT COUNT(*) AS c FROM strategies WHERE status='running'");
if ($runRes && ($rr = $runRes->fetch_assoc())) {
  $runningStrategies = (int)($rr['c'] ?? 0);
}

$lastTick = null;
$lastRes = $mysqli->query("SELECT MAX(last_tick_at) AS last_tick FROM strategies");
if ($lastRes && ($row = $lastRes->fetch_assoc()) && !empty($row['last_tick'])) {
  $lastTick = (string)$row['last_tick'];
}
if ($lastTick !== null) {
  $lag = time() - strtotime($lastTick);
  if ($lag > 600) {
    if ($runningStrategies <= 0) {
      $push('engine_tick', 'Активність торгового рушія', 'warn', "Немає запущених стратегій, останній тік {$lag} с тому", [
        'last_tick_at' => $lastTick,
        'lag_seconds' => $lag,
        'running_strategies' => $runningStrategies,
      ]);
      goto engine_tick_done;
    }
    $lastJobUpdate = $jqLastUpdate;
    if ($lastJobUpdate !== null) {
      $jobLag = time() - strtotime($lastJobUpdate);
      if ($jobLag <= 180) {
        $push('engine_tick', 'Активність торгового рушія', 'warn', "Тік застарілий ({$lag} с), але черга завдань жива ({$jobLag} с)", [
          'last_tick_at' => $lastTick,
          'lag_seconds' => $lag,
          'last_job_update' => $lastJobUpdate,
          'job_lag_seconds' => $jobLag,
          'running_strategies' => $runningStrategies,
          'queued' => $jqQueued,
          'processing' => $jqProcessing,
        ]);
      } else {
        if ($jqQueued === 0 && $jqProcessing === 0) {
          $push('engine_tick', 'Активність торгового рушія', 'warn', "Тік застарілий ({$lag} с), черга зараз порожня", [
            'last_tick_at' => $lastTick,
            'lag_seconds' => $lag,
            'running_strategies' => $runningStrategies,
            'queued' => $jqQueued,
            'processing' => $jqProcessing,
            'last_job_update' => $lastJobUpdate,
          ]);
        } else {
          $push('engine_tick', 'Активність торгового рушія', 'fail', "Рушій не оновлюється вже {$lag} с", [
            'last_tick_at' => $lastTick,
            'lag_seconds' => $lag,
            'running_strategies' => $runningStrategies,
            'queued' => $jqQueued,
            'processing' => $jqProcessing,
            'last_job_update' => $lastJobUpdate,
          ]);
        }
      }
    } else {
      if ($jqQueued === 0 && $jqProcessing === 0) {
        $push('engine_tick', 'Активність торгового рушія', 'warn', "Тік застарілий ({$lag} с), черга неактивна", [
          'last_tick_at' => $lastTick,
          'lag_seconds' => $lag,
          'running_strategies' => $runningStrategies,
          'queued' => $jqQueued,
          'processing' => $jqProcessing,
        ]);
      } else {
        $push('engine_tick', 'Engine last tick', 'fail', "Engine не тікає: lag {$lag}s", [
          'last_tick_at' => $lastTick,
          'lag_seconds' => $lag,
          'running_strategies' => $runningStrategies,
          'queued' => $jqQueued,
          'processing' => $jqProcessing,
        ]);
      }
    }
  } elseif ($lag > 120) {
    $push('engine_tick', 'Активність торгового рушія', 'warn', "Затримка підвищена: {$lag} с", ['last_tick_at' => $lastTick, 'lag_seconds' => $lag]);
  } else {
    $push('engine_tick', 'Активність торгового рушія', 'ok', "Все гаразд, затримка {$lag} с", ['last_tick_at' => $lastTick, 'lag_seconds' => $lag]);
  }
} else {
  if ($runningStrategies <= 0) {
    $push('engine_tick', 'Активність торгового рушія', 'warn', 'Немає запущених стратегій — перевірка не критична');
    goto engine_tick_done;
  }
  $jqFallback = $mysqli->query("
    SELECT
      MAX(updated_at) AS last_job_update,
      SUM(CASE WHEN status='processing' THEN 1 ELSE 0 END) AS processing_jobs,
      SUM(CASE WHEN status='queued' THEN 1 ELSE 0 END) AS queued_jobs
    FROM job_queue
  ");
  $jf = $jqFallback ? $jqFallback->fetch_assoc() : null;
  $lastJobUpdate = $jf && !empty($jf['last_job_update']) ? (string)$jf['last_job_update'] : null;
  $processingJobs = (int)($jf['processing_jobs'] ?? 0);
  $queuedJobs = (int)($jf['queued_jobs'] ?? 0);
  if ($lastJobUpdate !== null) {
    $jobLag = time() - strtotime($lastJobUpdate);
    if ($jobLag <= 180) {
      $push('engine_tick', 'Активність торгового рушія', 'warn', "Немає тіка в стратегіях, але черга жива ({$jobLag} с)", [
        'last_job_update' => $lastJobUpdate,
        'job_lag_seconds' => $jobLag,
        'processing_jobs' => $processingJobs,
        'queued_jobs' => $queuedJobs,
      ]);
    } else {
      $push('engine_tick', 'Активність торгового рушія', 'fail', "Немає тіка і черга не рухається ({$jobLag} с)", [
        'last_job_update' => $lastJobUpdate,
        'job_lag_seconds' => $jobLag,
        'processing_jobs' => $processingJobs,
        'queued_jobs' => $queuedJobs,
      ]);
    }
  } else {
    $push('engine_tick', 'Активність торгового рушія', 'fail', 'Немає даних про активність рушія та черги');
  }
}
engine_tick_done:

// Job queue
$jq = $mysqli->query("SHOW TABLES LIKE 'job_queue'");
if ($jq && $jq->num_rows > 0) {
  $c = ['queued' => $jqQueued, 'processing' => $jqProcessing, 'failed' => $jqFailed, 'done' => $jqDone];
  if ($c['failed'] > 20) {
    $push('job_queue_failed', 'Помилки в черзі завдань', 'warn', "Багато невдалих: {$c['failed']}", $c);
  } else {
    $push('job_queue_failed', 'Помилки в черзі завдань', 'ok', "Невдалих: {$c['failed']}", $c);
  }
  if ($c['queued'] > 1000) {
    $push('job_queue_backlog', 'Завантаженість черги', 'warn', "Дуже багато в очікуванні: {$c['queued']}", $c);
  } else {
    $push('job_queue_backlog', 'Завантаженість черги', 'ok', "В очікуванні: {$c['queued']}", $c);
  }
} else {
  $push('job_queue', 'Черга завдань', 'warn', 'Таблиця черги не знайдена в базі');
}

// Referral reconciliation
$pendingRewards = 0.0;
$r1 = $mysqli->query("SELECT COALESCE(SUM(reward_usdt),0) AS s FROM referral_rewards WHERE status='pending'");
if ($r1 && ($x = $r1->fetch_assoc())) $pendingRewards = (float)$x['s'];
$pendingWithdrawals = 0.0;
$r2 = $mysqli->query("SELECT COALESCE(SUM(amount_usdt),0) AS s FROM referral_withdrawals WHERE status='pending'");
if ($r2 && ($x = $r2->fetch_assoc())) $pendingWithdrawals = (float)$x['s'];
if ($pendingWithdrawals - $pendingRewards > 0.0001) {
  $push('referral_pending_delta', 'Реферали: виводи та нарахування', 'fail', 'Сума очікуваних виводів більша за нарахування', [
    'pending_rewards' => $pendingRewards,
    'pending_withdrawals' => $pendingWithdrawals,
  ]);
} else {
  $push('referral_pending_delta', 'Реферали: виводи та нарахування', 'ok', 'Суми узгоджені', [
    'pending_rewards' => $pendingRewards,
    'pending_withdrawals' => $pendingWithdrawals,
  ]);
}

$dupCount = 0;
$dup = $mysqli->query("
  SELECT COUNT(*) AS c FROM (
    SELECT referred_user_id, payment_amount_usdt, COUNT(*) AS cc
    FROM referral_rewards
    GROUP BY referred_user_id, payment_amount_usdt
    HAVING cc > 1
  ) t
");
if ($dup && ($d = $dup->fetch_assoc())) $dupCount = (int)$d['c'];
if ($dupCount > 0) {
  $push('referral_duplicates', 'Реферали: можливі дублікати', 'warn', "Підозрілих записів: {$dupCount}");
} else {
  $push('referral_duplicates', 'Реферали: можливі дублікати', 'ok', 'Дублікатів не знайдено');
}

// Admin audit table
$aa = $mysqli->query("SHOW TABLES LIKE 'admin_action_audit'");
if (!$aa || $aa->num_rows === 0) {
  $push('admin_audit', 'Журнал дій адміна', 'fail', 'Таблиця журналу не створена');
} else {
  $count = 0;
  $lastAt = null;
  $c1 = $mysqli->query("SELECT COUNT(*) AS c FROM admin_action_audit");
  if ($c1 && ($cr = $c1->fetch_assoc())) $count = (int)$cr['c'];
  $c2 = $mysqli->query("SELECT created_at FROM admin_action_audit ORDER BY id DESC LIMIT 1");
  if ($c2 && ($lr = $c2->fetch_assoc())) $lastAt = (string)($lr['created_at'] ?? '');
  if ($count === 0) {
    $push('admin_audit_rows', 'Записи журналу адміна', 'warn', 'Поки немає жодного запису');
  } else {
    $push('admin_audit_rows', 'Записи журналу адміна', 'ok', "Записів: {$count}; останній: " . ($lastAt ?: '-'));
  }
}

$tgToken = trim((string)($_ENV['TG_BOT_TOKEN'] ?? ''));
$expectedWh = trim((string)($_ENV['TG_WEBHOOK_URL'] ?? 'https://strim.in.ua/api/tg_webhook.php'));
if ($tgToken === '') {
  $push('tg_webhook', 'Telegram-бот (привʼязка)', 'warn', 'Токен бота не заданий у .env');
} else {
  $whRaw = @file_get_contents('https://api.telegram.org/bot' . $tgToken . '/getWebhookInfo');
  $whJ = is_string($whRaw) ? json_decode($whRaw, true) : null;
  $whUrl = is_array($whJ) ? (string)($whJ['result']['url'] ?? '') : '';
  $pending = is_array($whJ) ? (int)($whJ['result']['pending_update_count'] ?? 0) : 0;
  $lastErr = is_array($whJ) ? (string)($whJ['result']['last_error_message'] ?? '') : '';
  $okHost = $whUrl !== '' && (stripos($whUrl, 'strim.in.ua') !== false || stripos($whUrl, 'luckydrop.uno') !== false);
  if ($whUrl === '' || !$okHost) {
    $push('tg_webhook', 'Telegram-бот (привʼязка)', 'fail', 'Неправильна адреса webhook: ' . ($whUrl ?: '(порожньо)') . ' — потрібно ' . $expectedWh);
  } elseif ($lastErr !== '') {
    $push('tg_webhook', 'Telegram-бот (привʼязка)', 'warn', $whUrl . ' | ' . $lastErr . ' | очікує обробки: ' . $pending);
  } else {
    $push('tg_webhook', 'Telegram-бот (привʼязка)', 'ok', $whUrl . ($pending > 0 ? " (в черзі: {$pending})" : ''));
  }
}

echo json_encode([
  'ok' => true,
  'summary' => [
    'critical_fail' => $criticalFail,
    'warn' => $warnCount,
    'ok' => $okCount,
    'checked_at' => date('Y-m-d H:i:s'),
  ],
  'checks' => $checks,
], JSON_UNESCAPED_UNICODE);
