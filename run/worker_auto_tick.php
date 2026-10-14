<?php
declare(strict_types=1);

require __DIR__ . '/../www/vendor/autoload.php';
require __DIR__ . '/../www/api/_worker_manager.php';
Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

$m = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
if ($m->connect_errno) {
  fwrite(STDERR, "db_connect_failed\n");
  exit(1);
}

$s = worker_get_settings($m);
$target = (int)$s['target'];
$auto = (int)$s['auto'];
if ($auto !== 1) {
  echo "auto_disabled\n";
  exit(0);
}

$queued = 0;
$q = $m->query("SELECT COUNT(*) AS c FROM job_queue WHERE status='queued'");
if ($q && ($r = $q->fetch_assoc())) {
  $queued = (int)($r['c'] ?? 0);
}

$running = worker_running_pids();
// Завжди тримати цільову кількість воркерів (якщо один упав — підняти без очікування черги).
if (count($running) < $target) {
  $res = worker_start_missing($target);
  echo "started=" . count($res['started']) . " running=" . count($res['running']) . " queued=$queued target=$target\n";
} else {
  echo "noop running=" . count($running) . " queued=$queued target=$target\n";
}
