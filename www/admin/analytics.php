<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../auth/_session_user.php';
require_once __DIR__ . '/../api/_csrf.php';
require_once __DIR__ . '/../api/_trading_analytics.php';

Dotenv\Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();

$mysqli = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
$mysqli->set_charset('utf8mb4');

$uid = current_user_id($mysqli);
if ($uid <= 0) {
    header('Location: /auth/login.php?next=' . urlencode('/admin/analytics.php'), true, 302);
    exit;
}
$admRes = $mysqli->query('SELECT is_admin FROM users WHERE id=' . (int)$uid . ' LIMIT 1');
$admRow = $admRes ? $admRes->fetch_assoc() : null;
if (!$admRow || (int)($admRow['is_admin'] ?? 0) !== 1) {
    http_response_code(403);
    echo '403 Forbidden';
    exit;
}

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$ready = analytics_tables_ready($mysqli);
$tab = (string)($_GET['tab'] ?? 'reports');
if (!in_array($tab, ['reports', 'daily', 'advice', 'users'], true)) {
    $tab = 'reports';
}
$reportId = (int)($_GET['report'] ?? 0);
$periodFilter = (string)($_GET['period'] ?? '');
$notice = '';

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'run_daily') {
        try {
            $r = analytics_collect_daily($mysqli, date('Y-m-d'));
            $notice = 'Знімок зібрано: юзерів ' . $r['users'] . ', пар ' . $r['pairs'];
        } catch (Throwable $e) {
            $notice = 'Помилка знімка: ' . $e->getMessage();
        }
    } elseif ($action === 'run_reports') {
        try {
            $reps = analytics_run_scheduled_reports($mysqli);
            $notice = 'Звіти оновлено: ' . count($reps);
            if ($reps) {
                $reportId = (int)$reps[0]['id'];
                $tab = 'reports';
            }
        } catch (Throwable $e) {
            $notice = 'Помилка звітів: ' . $e->getMessage();
        }
    }
}

$reports = [];
$selected = null;
$advice = [];
$daily = [];
$latestAdvice = [];

if ($ready) {
    $q = "SELECT id, period_type, period_start, period_end, title, summary_uk, created_at
          FROM analytics_report";
    if (in_array($periodFilter, ['week', 'month', 'year'], true)) {
        $q .= " WHERE period_type='" . $mysqli->real_escape_string($periodFilter) . "'";
    }
    $q .= ' ORDER BY period_end DESC, FIELD(period_type,\'week\',\'month\',\'year\'), id DESC LIMIT 50';
    $rr = $mysqli->query($q);
    while ($rr && ($row = $rr->fetch_assoc())) {
        $reports[] = $row;
    }
    if ($reportId <= 0 && $reports) {
        $reportId = (int)$reports[0]['id'];
    }
    if ($reportId > 0) {
        $sr = $mysqli->query('SELECT * FROM analytics_report WHERE id=' . $reportId . ' LIMIT 1');
        $selected = $sr ? $sr->fetch_assoc() : null;
        $ar = $mysqli->query(
            'SELECT * FROM analytics_advice WHERE report_id=' . $reportId . ' AND active=1 ORDER BY priority DESC, id DESC'
        );
        while ($ar && ($a = $ar->fetch_assoc())) {
            $advice[] = $a;
        }
    }
    $dr = $mysqli->query(
        "SELECT snap_date, user_id, nickname, pairs_running, fba_sum_running, capital_total_usdt,
                realized_pnl_7d, efficiency_score, risk_score, deposit_inferred_usdt, deposit_api_usdt
         FROM analytics_user_daily
         ORDER BY snap_date DESC, COALESCE(efficiency_score,-999) DESC
         LIMIT 120"
    );
    while ($dr && ($d = $dr->fetch_assoc())) {
        $daily[] = $d;
    }
    $la = $mysqli->query(
        "SELECT a.*, r.period_type, r.period_end, r.title
         FROM analytics_advice a
         LEFT JOIN analytics_report r ON r.id = a.report_id
         WHERE a.active=1 AND a.scope='global'
         ORDER BY a.created_at DESC LIMIT 20"
    );
    while ($la && ($x = $la->fetch_assoc())) {
        $latestAdvice[] = $x;
    }
}

$ranking = [];
if ($selected && !empty($selected['ranking_json'])) {
    $decoded = json_decode((string)$selected['ranking_json'], true);
    if (is_array($decoded)) {
        $ranking = $decoded;
    }
}
?>
<!doctype html>
<html lang="uk">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex">
  <title>Аналітика ефективності — StrIM Admin</title>
  <link rel="stylesheet" href="/admin/admin-panel.css?v=20260927a">
  <style>
    .an-grid { display:grid; grid-template-columns: 280px 1fr; gap:16px; }
    @media (max-width:900px){ .an-grid{ grid-template-columns:1fr; } }
    .an-list a { display:block; padding:8px 10px; border-radius:8px; text-decoration:none; color:inherit; margin-bottom:4px; }
    .an-list a.on, .an-list a:hover { background: rgba(255,255,255,.06); }
    .an-pre { white-space: pre-wrap; line-height:1.45; font-size:.95rem; }
    .an-table { width:100%; border-collapse:collapse; font-size:.88rem; }
    .an-table th, .an-table td { padding:6px 8px; border-bottom:1px solid rgba(255,255,255,.08); text-align:left; }
    .an-pill { display:inline-block; padding:2px 8px; border-radius:999px; background:rgba(255,255,255,.08); font-size:.75rem; }
    .risk-hi { color:#f87171; } .risk-mid { color:#fbbf24; } .risk-lo { color:#4ade80; }
  </style>
</head>
<body class="adm-body">
<div class="adm-wrap">
  <header class="adm-header">
    <div>
      <h1>Аналітика ефективності торгівлі</h1>
      <p>Щоденні знімки · тиждень / місяць / рік · поради для майбутніх користувачів</p>
    </div>
    <nav class="adm-nav-actions">
      <a class="adm-btn" href="/admin/users.php">← Адмін</a>
      <a class="adm-btn" href="/admin/analytics.php?tab=reports">Звіти</a>
      <a class="adm-btn" href="/admin/analytics.php?tab=daily">Знімки</a>
      <a class="adm-btn" href="/admin/analytics.php?tab=advice">Поради</a>
    </nav>
  </header>

  <?php if (!$ready): ?>
    <div class="adm-card"><div class="adm-card__body">
      Таблиці ще не створені. Застосуйте міграцію
      <code>run/migrations/20260927_create_trading_analytics.sql</code>
    </div></div>
  <?php else: ?>

  <?php if ($notice !== ''): ?>
    <p class="adm-card" style="padding:12px 16px" role="status"><?= h($notice) ?></p>
  <?php endif; ?>

  <div class="adm-card" style="margin-bottom:16px">
    <div class="adm-card__body" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
      <form method="post"><input type="hidden" name="action" value="run_daily">
        <button class="adm-btn adm-btn--primary" type="submit">Зібрати знімок зараз</button>
      </form>
      <form method="post"><input type="hidden" name="action" value="run_reports">
        <button class="adm-btn" type="submit">Оновити week/month/year звіти</button>
      </form>
      <span class="adm-muted">Cron: щодня 08:00 · неділя 12:00 (TG + БД)</span>
    </div>
  </div>

  <?php if ($tab === 'reports'): ?>
  <div class="an-grid">
    <aside class="adm-card an-list">
      <div class="adm-card__body">
        <div style="margin-bottom:10px">
          <a class="an-pill" href="?tab=reports">усі</a>
          <a class="an-pill" href="?tab=reports&period=week">тиждень</a>
          <a class="an-pill" href="?tab=reports&period=month">місяць</a>
          <a class="an-pill" href="?tab=reports&period=year">рік</a>
        </div>
        <?php foreach ($reports as $r): ?>
          <a class="<?= $reportId === (int)$r['id'] ? 'on' : '' ?>" href="?tab=reports&report=<?= (int)$r['id'] ?>">
            <span class="an-pill"><?= h((string)$r['period_type']) ?></span>
            <?= h((string)$r['period_start']) ?> — <?= h((string)$r['period_end']) ?><br>
            <small class="adm-muted"><?= h((string)$r['title']) ?></small>
          </a>
        <?php endforeach; ?>
        <?php if (!$reports): ?><p class="adm-muted">Звітів ще немає.</p><?php endif; ?>
      </div>
    </aside>
    <section class="adm-card">
      <div class="adm-card__body">
        <?php if (!$selected): ?>
          <p class="adm-muted">Оберіть звіт ліворуч або натисніть «Оновити звіти».</p>
        <?php else: ?>
          <h2><?= h((string)$selected['title']) ?></h2>
          <p><strong><?= h((string)$selected['summary_uk']) ?></strong></p>
          <hr style="border:0;border-top:1px solid rgba(255,255,255,.1);margin:16px 0">
          <div class="an-pre"><?= h((string)$selected['body_uk']) ?></div>

          <?php if ($advice): ?>
            <h3 style="margin-top:24px">Порада (збережена)</h3>
            <?php foreach ($advice as $a): ?>
              <div class="an-pre" style="background:rgba(255,255,255,.03);padding:12px;border-radius:8px;margin-bottom:8px"><?= h((string)$a['advice_uk']) ?></div>
            <?php endforeach; ?>
          <?php endif; ?>

          <?php if ($ranking): ?>
            <h3 style="margin-top:24px">Рейтинг користувачів</h3>
            <table class="an-table">
              <thead>
                <tr>
                  <th>#</th><th>Юзер</th><th>PnL періоду</th><th>Капітал</th><th>Пар</th><th>FBA Σ</th><th>FBA/кап%</th><th>Ризик</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($ranking as $i => $t):
                  $rs = (int)($t['risk_score'] ?? 0);
                  $rc = $rs >= 50 ? 'risk-hi' : ($rs >= 30 ? 'risk-mid' : 'risk-lo');
              ?>
                <tr>
                  <td><?= $i + 1 ?></td>
                  <td><?= h((string)($t['nickname'] ?? '')) ?> <span class="adm-muted">#<?= (int)($t['user_id'] ?? 0) ?></span></td>
                  <td><?= number_format((float)($t['pnl_period'] ?? 0), 2, '.', ' ') ?></td>
                  <td><?= number_format((float)($t['capital_usdt'] ?? 0), 2, '.', ' ') ?></td>
                  <td><?= (int)($t['pairs_running'] ?? 0) ?></td>
                  <td><?= number_format((float)($t['fba_sum'] ?? 0), 2, '.', ' ') ?></td>
                  <td><?= isset($t['fba_to_capital_pct']) && $t['fba_to_capital_pct'] !== null ? number_format((float)$t['fba_to_capital_pct'], 1) : '—' ?></td>
                  <td class="<?= $rc ?>"><?= $rs ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </section>
  </div>
  <?php elseif ($tab === 'daily'): ?>
    <div class="adm-card"><div class="adm-card__body">
      <h2>Останні щоденні знімки</h2>
      <table class="an-table">
        <thead>
          <tr>
            <th>Дата</th><th>Юзер</th><th>Пар</th><th>FBA Σ</th><th>Капітал BN</th>
            <th>PnL 7д</th><th>Eff</th><th>Ризик</th><th>Депозит≈</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($daily as $d): ?>
          <tr>
            <td><?= h((string)$d['snap_date']) ?></td>
            <td><?= h((string)($d['nickname'] ?: ('#'.$d['user_id']))) ?></td>
            <td><?= (int)$d['pairs_running'] ?></td>
            <td><?= number_format((float)$d['fba_sum_running'], 2, '.', ' ') ?></td>
            <td><?= $d['capital_total_usdt'] !== null ? number_format((float)$d['capital_total_usdt'], 2, '.', ' ') : '—' ?></td>
            <td><?= number_format((float)$d['realized_pnl_7d'], 2, '.', ' ') ?></td>
            <td><?= $d['efficiency_score'] !== null ? h((string)$d['efficiency_score']) : '—' ?></td>
            <td><?= (int)$d['risk_score'] ?></td>
            <td><?= number_format((float)$d['deposit_inferred_usdt'] + (float)($d['deposit_api_usdt'] ?? 0), 2, '.', ' ') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div></div>
  <?php else: /* advice */ ?>
    <div class="adm-card"><div class="adm-card__body">
      <h2>Узагальнюючі поради</h2>
      <?php if (!$latestAdvice): ?>
        <p class="adm-muted">Порад ще немає — згенеруйте звіти.</p>
      <?php endif; ?>
      <?php foreach ($latestAdvice as $a): ?>
        <article style="margin-bottom:18px;padding-bottom:18px;border-bottom:1px solid rgba(255,255,255,.08)">
          <div class="adm-muted" style="margin-bottom:6px">
            <?= h((string)($a['period_type'] ?? '')) ?> · до <?= h((string)($a['period_end'] ?? '')) ?>
            · <a href="?tab=reports&report=<?= (int)($a['report_id'] ?? 0) ?>">звіт</a>
          </div>
          <div class="an-pre"><?= h((string)$a['advice_uk']) ?></div>
        </article>
      <?php endforeach; ?>
    </div></div>
  <?php endif; ?>

  <?php endif; ?>
</div>
</body>
</html>
