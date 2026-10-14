<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../auth/_session_user.php';
require_once __DIR__ . '/_csrf.php';
require_once __DIR__ . '/_admin_audit.php';

Dotenv\Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();
header('Content-Type: application/json; charset=utf-8');

$mysqli = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
if ($mysqli->connect_errno) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'db_connect'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}
$mysqli->set_charset('utf8mb4');

$uid = current_user_id($mysqli);
if ($uid <= 0) {
  http_response_code(401);
  echo json_encode(['ok' => false, 'error' => 'unauthorized'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}
$admRes = $mysqli->query("SELECT is_admin FROM users WHERE id=" . (int)$uid . " LIMIT 1");
$admRow = $admRes ? $admRes->fetch_assoc() : null;
if (!$admRow || (int)($admRow['is_admin'] ?? 0) !== 1) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

$root = dirname(__DIR__, 2);
$phpBin = '/usr/local/php84/bin/php';
if (!is_executable($phpBin)) {
  $phpBin = 'php';
}
$bashBin = '/bin/bash';
if (!is_executable($bashBin)) {
  $bashBin = 'bash';
}

$catalog = [
  'prelaunch_smoke' => [
    'title' => 'Швидка перевірка сайту',
    'description' => 'Чи відкриваються головні сторінки та службові адреси.',
    'risk' => 'read',
    'timeout' => 90,
    'command' => [$bashBin, $root . '/run/prelaunch_smoke.sh'],
  ],
  'verify_all_public' => [
    'title' => 'Повна перевірка публічних сторінок',
    'description' => 'Усі сторінки та API з чекліста перед рекламою.',
    'risk' => 'read',
    'timeout' => 120,
    'command' => [$bashBin, $root . '/run/verify_all_public.sh'],
  ],
  'verify_main_sell_cb' => [
    'title' => 'Перевірка логіки продажу',
    'description' => 'Чи коректно працює головний сценарій продажу позиції.',
    'risk' => 'read',
    'timeout' => 90,
    'command' => [$bashBin, $root . '/run/verify_main_sell_cb.sh'],
  ],
  'reconcile_strategy_readonly' => [
    'title' => 'Список стратегій (read-only)',
    'description' => 'Показує останні 40 стратегій з id, символом, статусом і user_id. Нічого не змінює. Для деталей по одній — «Звірка стратегії — деталі».',
    'risk' => 'read',
    'timeout' => 60,
    'command' => [$phpBin, $root . '/run/reconcile_strategy_readonly.php', '--list'],
  ],
  'reconcile_strategy_detail' => [
    'title' => 'Звірка стратегії — деталі (без змін)',
    'description' => 'Read-only звіт по одній стратегії з бази: positions, sub_positions, trade_ledger, engine_journal. Параметр: strategy_id (наприклад: 16).',
    'risk' => 'read',
    'timeout' => 120,
    'args_hint' => 'strategy_id',
    'args_default' => '16',
    'command' => [$phpBin, $root . '/run/reconcile_strategy_readonly.php'],
  ],
  'reconcile_binance_all' => [
    'title' => 'Звірка Binance — усі активні стратегії',
    'description' => 'Для кожної running/paused стратегії порівнює Binance (баланс, myTrades) з базою. Показує розбіжності, невідстежені ордери та кількість SYNC_EXTERNAL_BUY у журналі. Нічого не змінює.',
    'risk' => 'read',
    'timeout' => 300,
    'command' => [$phpBin, $root . '/run/admin_binance_reconcile_all.php'],
  ],
  'reconcile_binance_one' => [
    'title' => 'Звірка Binance — одна стратегія',
    'description' => 'Детальний JSON-звіт по одній парі: баланс на біржі, myTrades, positions, trade_ledger, sub_positions. Для розбору скарг користувача. Параметри: user_id і strategy_id (наприклад: 15 16).',
    'risk' => 'read',
    'timeout' => 120,
    'args_hint' => 'user_id strategy_id',
    'args_default' => '15 16',
    'command' => [$phpBin, $root . '/run/reconcile_binance_strategy.php'],
  ],
  'audit_user_strategy' => [
    'title' => 'Аудит стратегії (таймлайн + пропущені рівні)',
    'description' => 'Глибокий read-only звіт: коли закінчились USDT, мінімальна ціна, пропущені L-рівні, останні події журналу. Параметри: user_id strategy_id (наприклад: 15 16).',
    'risk' => 'read',
    'timeout' => 120,
    'args_hint' => 'user_id strategy_id',
    'args_default' => '15 16',
    'command' => [$phpBin, $root . '/run/audit_user_strategy.php'],
  ],
  'unfreeze_burst_plan_all' => [
    'title' => 'Burst після розморозки — усі пари користувача',
    'description' => 'Read-only симуляція burst (варіант A): синтетична сітка, уявні тригери, TP, сума USDT. Параметри: user_id [extra_usdt] (наприклад: 11 500).',
    'risk' => 'read',
    'timeout' => 120,
    'args_hint' => 'user_id [extra_usdt]',
    'args_default' => '11 500',
    'command' => [$phpBin, $root . '/run/verify_user_unfreeze_burst_all.php'],
  ],
  'cleanup_sync_external_dry' => [
    'title' => 'Перевірка хибних SYNC_EXTERNAL_BUY',
    'description' => 'Dry-run: показує скільки записів SYNC_EXTERNAL_BUY у журналі можна безпечно видалити (коли на Binance немає невідстежених BUY). Нічого не видаляє.',
    'risk' => 'read',
    'timeout' => 180,
    'command' => [$phpBin, $root . '/run/cleanup_false_sync_external_buy.php'],
  ],
  'cleanup_sync_external_apply' => [
    'title' => 'Видалити хибні SYNC_EXTERNAL_BUY',
    'description' => 'Обережно: видаляє з engine_journal підтверджені хибні записи SYNC_EXTERNAL_BUY (спам від старого бага синхронізації). Спочатку запустіть dry-run перевірку.',
    'risk' => 'write',
    'timeout' => 300,
    'command' => [$phpBin, $root . '/run/cleanup_false_sync_external_buy.php', '--apply'],
  ],
  'set_support_tg_webhook' => [
    'title' => 'Оновити webhook бота підтримки',
    'description' => 'Перевстановлює Telegram webhook для «Підтримка StrIM» на strim.in.ua. Якщо відповіді адміна не доходять до клієнтів — запустіть це.',
    'risk' => 'maintenance',
    'timeout' => 60,
    'command' => [$phpBin, $root . '/run/set_support_tg_webhook.php'],
  ],
  'adm_dns_audit' => [
    'title' => 'Перевірка DNS і пошти',
    'description' => 'Домен, MX, SPF, DKIM, скринька support@ через API хостингу.',
    'risk' => 'read',
    'timeout' => 60,
    'command' => [$phpBin, $root . '/run/adm_dns_audit.php'],
  ],
  'adm_tools_ping' => [
    'title' => 'Звʼязок з панеллю хостингу',
    'description' => 'Чи працює токен ADM.TOOLS і баланс акаунта.',
    'risk' => 'read',
    'timeout' => 45,
    'command' => [$phpBin, $root . '/run/adm_tools_ping.php'],
  ],
  'cron_ensure_trading_ops' => [
    'title' => 'Запустити наглядач торгівлі',
    'description' => 'Перевіряє фонові процеси і запускає відсутні.',
    'risk' => 'maintenance',
    'timeout' => 120,
    'command' => [$phpBin, $root . '/run/cron_ensure_trading_ops.php'],
  ],
  'platform_watchdog' => [
    'title' => 'Наглядач платформи (watchdog)',
    'description' => 'Перевіряє lag тіків, trading_ops daemon, воркери. При аномалії — Telegram адміну. Auto-heal за WATCHDOG_AUTO_HEAL=1.',
    'risk' => 'maintenance',
    'timeout' => 90,
    'command' => [$phpBin, $root . '/run/platform_watchdog.php'],
  ],
  'cleanup_template_queue_dry' => [
    'title' => 'Перевірка TEMPLATE у черзі',
    'description' => 'Dry-run: показує скільки jobs у job_queue для службових TEMPLATE-стратегій забивають чергу. Нічого не змінює.',
    'risk' => 'read',
    'timeout' => 60,
    'command' => [$phpBin, $root . '/run/cleanup_template_queue_jobs.php'],
  ],
  'cleanup_template_queue_apply' => [
    'title' => 'Прибрати TEMPLATE з черги',
    'description' => 'Знімає queued/processing jobs для TEMPLATE-стратегій (done). Якщо running-стратегія «зависла» в хвості черги — запустіть це.',
    'risk' => 'maintenance',
    'timeout' => 60,
    'command' => [$phpBin, $root . '/run/cleanup_template_queue_jobs.php', '--apply'],
  ],
  'restart_trading_ops' => [
    'title' => 'Перезапуск торгівлі',
    'description' => 'Мʼяко перезапускає наглядач і процеси обробки.',
    'risk' => 'maintenance',
    'timeout' => 150,
    'command' => [$bashBin, $root . '/run/restart_trading_ops.sh'],
  ],
  'start_trading_ops_daemon' => [
    'title' => 'Старт наглядача торгівлі',
    'description' => 'Увімкнути фоновий наглядач без примусової зупинки.',
    'risk' => 'maintenance',
    'timeout' => 90,
    'command' => [$bashBin, $root . '/run/start_trading_ops_daemon.sh'],
  ],
  'backfill_referral_rewards' => [
    'title' => 'Донарахувати реферальні бонуси',
    'description' => 'Обережно: записує в базу пропущені реферальні нарахування.',
    'risk' => 'write',
    'timeout' => 180,
    'command' => [$phpBin, $root . '/run/backfill_referral_rewards.php'],
  ],
];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  $items = [];
  foreach ($catalog as $id => $row) {
    $items[] = [
      'id' => $id,
      'title' => $row['title'],
      'description' => $row['description'],
      'risk' => $row['risk'],
      'timeout' => (int)$row['timeout'],
      'args_hint' => (string)($row['args_hint'] ?? ''),
      'args_default' => (string)($row['args_default'] ?? ''),
      'command_preview' => implode(' ', array_map(static fn(string $x): string => basename($x), $row['command'])),
    ];
  }
  echo json_encode(['ok' => true, 'items' => $items], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'error' => 'method_not_allowed'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

require_csrf_or_403();

$scriptId = trim((string)($_POST['script_id'] ?? ''));
if ($scriptId === '' || !isset($catalog[$scriptId])) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'unknown_script'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

$cfg = $catalog[$scriptId];
$command = $cfg['command'];

foreach ($command as $part) {
  if (!is_string($part) || $part === '') {
    continue;
  }
  if (str_starts_with($part, $root . '/') && !is_file($part)) {
    http_response_code(500);
    echo json_encode([
      'ok' => false,
      'error' => 'script_file_missing',
      'missing' => str_replace($root . '/', '', $part),
      'hint' => 'Файл не знайдено на сервері. Запустіть deploy_admin_and_ops.sh з локальної машини.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
  }
}

$scriptArgs = trim((string)($_POST['script_args'] ?? ''));
if ($scriptArgs !== '') {
  if (!preg_match('/^[0-9A-Za-z_,=\.\-\s]+$/', $scriptArgs)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_script_args'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
  }
  foreach (preg_split('/\s+/', $scriptArgs) as $part) {
    if ($part === '') {
      continue;
    }
    $command[] = $part;
  }
}
$cmd = implode(' ', array_map(static fn(string $x): string => escapeshellarg($x), $command));
$timeout = max(15, (int)($cfg['timeout'] ?? 90));

$spec = [
  0 => ['pipe', 'r'],
  1 => ['pipe', 'w'],
  2 => ['pipe', 'w'],
];
$proc = proc_open($cmd, $spec, $pipes, $root);
if (!is_resource($proc)) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'proc_open_failed'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}
fclose($pipes[0]);
stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);

$started = microtime(true);
$stdout = '';
$stderr = '';
$timedOut = false;

while (true) {
  $stdout .= (string)stream_get_contents($pipes[1]);
  $stderr .= (string)stream_get_contents($pipes[2]);
  $status = proc_get_status($proc);
  if (!$status['running']) {
    break;
  }
  if ((microtime(true) - $started) > $timeout) {
    $timedOut = true;
    @proc_terminate($proc, 9);
    break;
  }
  usleep(80_000);
}

$stdout .= (string)stream_get_contents($pipes[1]);
$stderr .= (string)stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exitCode = proc_close($proc);
if ($timedOut) {
  $exitCode = 124;
}

$cap = static function (string $s, int $max = 12000): string {
  if (strlen($s) <= $max) return $s;
  return substr($s, 0, $max) . "\n...[truncated]...";
};

$ok = (!$timedOut && $exitCode === 0);
$payload = [
  'script_id' => $scriptId,
  'risk' => $cfg['risk'],
  'exit_code' => $exitCode,
  'timed_out' => $timedOut,
];
admin_audit_log($mysqli, $uid, 'admin_script_run', 0, $payload, $ok ? 'ok' : 'fail');

echo json_encode([
  'ok' => $ok,
  'script_id' => $scriptId,
  'title' => $cfg['title'],
  'risk' => $cfg['risk'],
  'exit_code' => $exitCode,
  'timed_out' => $timedOut,
  'stdout' => $cap($stdout),
  'stderr' => $cap($stderr),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
