<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../auth/_session_user.php';
require_once __DIR__ . '/../api/_user_keys.php';
require_once __DIR__ . '/../api/_ws_price.php';
require_once __DIR__ . '/../api/_admin_realized_pnl.php';
require_once __DIR__ . '/../api/_filters_utils.php';
require_once __DIR__ . '/../api/_csrf.php';
require_once __DIR__ . '/../api/_adm_tools_client.php';
require_once __DIR__ . '/../api/_billing_access.php';
require_once dirname(__DIR__, 2) . '/engine/engine_helpers.php';

Dotenv\Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();

$mysqli = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
$mysqli->set_charset('utf8mb4');

$uid = current_user_id($mysqli);
if ($uid <= 0) {
  header('Location: /auth/login.php?next=' . urlencode('/admin/users.php'), true, 302);
  exit;
}
$admRes = $mysqli->query("SELECT is_admin FROM users WHERE id=" . (int)$uid . " LIMIT 1");
$admRow = $admRes ? $admRes->fetch_assoc() : null;
if (!$admRow || (int)($admRow['is_admin'] ?? 0) !== 1) {
  http_response_code(403);
  echo '403 Forbidden';
  exit;
}
$csrfToken = ensure_csrf_token();
$csrfJson = json_encode($csrfToken, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function fmt2(float $v): string { return number_format($v, 2, '.', ' '); }
function fmt8(float $v): string { return rtrim(rtrim(number_format($v, 8, '.', ''), '0'), '.'); }

function admin_live_price(string $symbol): float {
  if ($symbol === '') return 0.0;
  // Admin list: WS cache only — never block SSR on Binance REST.
  $ws = ws_ticker_price($symbol);
  if (is_finite($ws) && $ws > 0) return (float)$ws;
  return 0.0;
}

function local_assets_from_positions(mysqli $db, int $userId): string {
  $rows = [];
  $res = $db->query("
    SELECT s.symbol, p.qty, p.state
    FROM strategies s
    LEFT JOIN positions p ON p.strategy_id = s.id
    WHERE s.user_id = $userId
      AND UPPER(COALESCE(s.symbol,'')) <> 'TEMPLATE'
  ");
  while ($res && ($r = $res->fetch_assoc())) {
    $symbol = (string)($r['symbol'] ?? '');
    $qty = (float)($r['qty'] ?? 0);
    $state = (string)($r['state'] ?? '');
    if ($symbol === '' || $qty <= 0 || $state !== 'open') continue;
    $asset = $symbol;
    if (substr($symbol, -4) === 'USDT') $asset = substr($symbol, 0, -4);
    $rows[] = $asset . ' ' . fmt8($qty);
  }
  $rows = array_values(array_unique($rows));
  return count($rows) ? implode('; ', $rows) : '—';
}

function effective_billing(string $status, ?string $trialUntil, ?string $paidUntil): string {
  if ($status === 'blocked') return 'blocked';
  $now = time();
  if ($paidUntil && strtotime($paidUntil) > $now) return 'active';
  if ($trialUntil && strtotime($trialUntil) > $now) return 'trial';
  return 'expired';
}

/** Percent-aware status for admin list (active / trial / exhausted / blocked / expired). */
function admin_user_billing_status(mysqli $db, array $u): string {
  if (billing_percent_mode_active($db)) {
    $eff = billing_user_row_effective_status($u);
    return $eff === 'exhausted' ? 'exhausted' : $eff;
  }
  return effective_billing(
    (string)($u['billing_status'] ?? 'trial'),
    isset($u['trial_until']) ? (string)$u['trial_until'] : null,
    isset($u['paid_until']) ? (string)$u['paid_until'] : null
  );
}

function fetch_binance_snapshot_for_user(mysqli $db, int $userId): array {
  $localAssets = local_assets_from_positions($db, $userId);
  [$apiKey, $apiSecret] = user_keys($db, $userId);
  if (!$apiKey || !$apiSecret) return [null, $localAssets !== '—' ? ($localAssets . ' (з позицій)') : 'не підключено'];
  try {
    $acc = exi_get_account($apiKey, $apiSecret);
  } catch (Throwable $e) {
    // Fallback to local open positions snapshot when exchange account endpoint is unavailable.
    return [null, $localAssets !== '—' ? ($localAssets . ' (з позицій)') : 'тимчасово недоступно'];
  }
  $balances = $acc['balances'] ?? [];
  $usdt = null;
  $assets = [];
  foreach ($balances as $b) {
    $asset = (string)($b['asset'] ?? '');
    $free = (float)($b['free'] ?? 0);
    $locked = (float)($b['locked'] ?? 0);
    $total = $free + $locked;
    if ($asset === 'USDT') {
      $usdt = $free;
      continue;
    }
    if ($total <= 0) continue;
    $assets[] = $asset . ' ' . fmt8($total);
  }
  return [$usdt, count($assets) ? implode('; ', $assets) : '—'];
}

$users = [];
$walletCols = billing_percent_mode_active($mysqli);
$uRes = $mysqli->query("
  SELECT
    u.id, u.email, u.nickname, u.withdraw_wallet, u.is_admin,
    u.billing_status, u.trial_until, u.paid_until,
    u.tg_chat_id, u.tg_enabled,
    " . ($walletCols
      ? "u.balance_usdt, u.fee_shortfall_usdt, u.wallet_activated_at,"
      : "") . "
    u.ref_code, u.signup_ref_code_used, ru.email AS referrer_email
  FROM users u
  LEFT JOIN users ru ON ru.id = u.referred_by_user_id
  ORDER BY u.id DESC
");
while ($uRes && ($u = $uRes->fetch_assoc())) {
  $id = (int)$u['id'];
  $billingEff = admin_user_billing_status($mysqli, $u);
  $balanceUsdt = $walletCols ? (float)($u['balance_usdt'] ?? 0) : null;
  $shortfallUsdt = $walletCols ? (float)($u['fee_shortfall_usdt'] ?? 0) : 0.0;
  $rewardRes = $mysqli->query("
    SELECT
      COALESCE(SUM(reward_usdt),0) AS total_rewards,
      COALESCE(SUM(CASE WHEN status='pending' THEN reward_usdt ELSE 0 END),0) AS pending_rewards
    FROM referral_rewards
    WHERE referrer_user_id = $id
  ");
  $rewardRow = $rewardRes ? $rewardRes->fetch_assoc() : [];
  $withdrawRes = $mysqli->query("
    SELECT COALESCE(SUM(amount_usdt),0) AS paid_withdrawn
    FROM referral_withdrawals
    WHERE user_id = $id AND status='paid'
  ");
  $withdrawRow = $withdrawRes ? $withdrawRes->fetch_assoc() : [];
  $ownRefEsc = $mysqli->real_escape_string((string)($u['ref_code'] ?? ''));
  $refLinkedRes = $mysqli->query("SELECT COUNT(*) AS c FROM users WHERE referred_by_user_id = $id");
  $refLinkedRow = $refLinkedRes ? $refLinkedRes->fetch_assoc() : [];
  $refMismatchRes = $ownRefEsc !== '' ? $mysqli->query("
    SELECT COUNT(*) AS c FROM users
    WHERE UPPER(TRIM(COALESCE(signup_ref_code_used,''))) = UPPER('$ownRefEsc')
      AND (referred_by_user_id IS NULL OR referred_by_user_id <> $id)
  ") : null;
  $refMismatchRow = $refMismatchRes ? $refMismatchRes->fetch_assoc() : [];
  $refAccrualRes = $mysqli->query("SELECT COUNT(*) AS c FROM referral_rewards WHERE referrer_user_id = $id");
  $refAccrualRow = $refAccrualRes ? $refAccrualRes->fetch_assoc() : [];

  $realizedRow = ['pnl' => admin_fetch_realized_pnl($mysqli, $id)];

  $unrealized = 0.0;
  $running = 0;
  $totalStrategies = 0;
  $symbols = [];
  $posRes = $mysqli->query("
    SELECT s.id AS strategy_id, s.symbol, s.status, s.user_id, s.owner_id
    FROM strategies s
    WHERE s.user_id = $id
      AND UPPER(COALESCE(s.symbol,'')) <> 'TEMPLATE'
  ");
  while ($posRes && ($p = $posRes->fetch_assoc())) {
    $totalStrategies++;
    if (($p['status'] ?? '') === 'running') {
      $running++;
      $symbols[] = (string)($p['symbol'] ?? '');
    }
    $sid = (int)($p['strategy_id'] ?? 0);
    $uidPos = (int)($p['owner_id'] ?? 0);
    if ($uidPos <= 0) {
      $uidPos = (int)($p['user_id'] ?? 0);
    }
    $pos = ($sid > 0) ? position_pick_row($mysqli, $sid, $uidPos) : null;
    $qty = $pos ? (float)($pos['qty'] ?? 0) : 0.0;
    $avg = $pos ? (float)($pos['avg_price'] ?? 0) : 0.0;
    $invested = $pos ? (float)($pos['invested_usdt'] ?? 0) : 0.0;
    $state = $pos ? (string)($pos['state'] ?? '') : '';
    $pid = $pos ? (int)($pos['id'] ?? 0) : 0;
    if ($qty <= 1e-12 && $pid > 0) {
      $subQty = position_open_sub_qty_sum($mysqli, $pid);
      if ($subQty > 1e-12) {
        $subCost = position_open_sub_cost_sum($mysqli, $pid);
        $qty = $subQty;
        $invested = $subCost;
        $avg = $subQty > 0 ? ($subCost / $subQty) : 0.0;
        $state = 'open';
      }
    }
    if ($state !== 'open' || $qty <= 0) continue;
    $symbol = (string)($p['symbol'] ?? '');
    $mark = admin_live_price($symbol);
    if ($mark > 0) {
      $effectiveInvested = $invested > 0 ? $invested : ($avg > 0 ? ($avg * $qty) : 0.0);
      $unrealized += ($qty * $mark) - $effectiveInvested;
    }
  }
  $symbols = array_values(array_unique(array_filter($symbols)));

  // Binance live snapshot is loaded async (see admin_users_live.php) — keeps SSR fast.
  $binanceUsdt = null;
  $assetsText = 'завантаження…';
  $maxRunning = billing_max_running_strategies($mysqli, $id);
  $maxOverride = billing_get_max_running_override($mysqli, $id);

  $users[] = [
    'id' => $id,
    'email' => (string)$u['email'],
    'is_admin' => (int)($u['is_admin'] ?? 0),
    'nickname' => (string)($u['nickname'] ?? ''),
    'tg_enabled' => (int)($u['tg_enabled'] ?? 0) === 1,
    'tg_linked' => ((int)($u['tg_enabled'] ?? 0) === 1) && trim((string)($u['tg_chat_id'] ?? '')) !== '' && (string)($u['tg_chat_id'] ?? '') !== '0',
    'ref_code' => (string)($u['ref_code'] ?? ''),
    'referrer_email' => (string)($u['referrer_email'] ?? ''),
    'signup_ref_code_used' => (string)($u['signup_ref_code_used'] ?? ''),
    'withdraw_wallet' => (string)($u['withdraw_wallet'] ?? ''),
    'billing' => $billingEff,
    'trial_until' => $u['trial_until'] ?? null,
    'paid_until' => $u['paid_until'] ?? null,
    'balance_usdt' => $balanceUsdt,
    'fee_shortfall_usdt' => $shortfallUsdt,
    'wallet_activated_at' => $walletCols ? ($u['wallet_activated_at'] ?? null) : null,
    'referrals_linked' => (int)($refLinkedRow['c'] ?? 0),
    'referrals_code_mismatch' => (int)($refMismatchRow['c'] ?? 0),
    'referral_accrual_events' => (int)($refAccrualRow['c'] ?? 0),
    'referral_total' => (float)($rewardRow['total_rewards'] ?? 0),
    'referral_pending' => (float)($rewardRow['pending_rewards'] ?? 0),
    'referral_paid_out' => (float)($withdrawRow['paid_withdrawn'] ?? 0),
    'wallet_balance' => max(0.0, (float)($rewardRow['pending_rewards'] ?? 0)),
    'binance_usdt' => $binanceUsdt,
    'assets_text' => $assetsText,
    'strategies_running' => $running,
    'strategies_total' => $totalStrategies,
    'strategies_max_running' => $maxRunning,
    'strategies_max_override' => $maxOverride,
    'symbols_running' => $symbols,
    'unrealized_pnl' => $unrealized,
    'realized_pnl' => (float)($realizedRow['pnl'] ?? 0),
  ];
}

$strimStats = ['total' => 0, 'today' => 0];
$strimRes = $mysqli->query("
  SELECT
    COUNT(*) AS total,
    SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) AS today
  FROM users
  WHERE signup_ref_code_used = 'STRIM'
");
if ($strimRes && ($sr = $strimRes->fetch_assoc())) {
  $strimStats['total'] = (int)($sr['total'] ?? 0);
  $strimStats['today'] = (int)($sr['today'] ?? 0);
}

$withdrawals = [];
$wRes = $mysqli->query("
  SELECT w.id, w.user_id, w.amount_usdt, w.wallet_address, w.status, w.note, w.created_at, u.email
  FROM referral_withdrawals w
  JOIN users u ON u.id = w.user_id
  ORDER BY w.id DESC
");
while ($wRes && ($w = $wRes->fetch_assoc())) $withdrawals[] = $w;

$topReferrers = [];
$tRes = $mysqli->query("
  SELECT
    u.id, u.email, u.ref_code,
    COUNT(DISTINCT r.id) AS referrals_count,
    (
      SELECT COALESCE(SUM(reward_usdt), 0)
      FROM referral_rewards rr2
      WHERE rr2.referrer_user_id = u.id
    ) AS earnings
  FROM users u
  LEFT JOIN users r ON r.referred_by_user_id = u.id
  GROUP BY u.id, u.email, u.ref_code
  ORDER BY earnings DESC, referrals_count DESC
  LIMIT 30
");
while ($tRes && ($t = $tRes->fetch_assoc())) $topReferrers[] = $t;

$auditRows = [];
$auditTableExists = false;
$auditFilterAction = trim((string)($_GET['audit_action'] ?? ''));
$auditFilterTarget = (int)($_GET['audit_target_user'] ?? 0);
$at = $mysqli->query("SHOW TABLES LIKE 'admin_action_audit'");
if ($at && $at->num_rows > 0) {
  $auditTableExists = true;
  $where = [];
  if ($auditFilterAction !== '') {
    $where[] = "a.action_type='" . $mysqli->real_escape_string($auditFilterAction) . "'";
  }
  if ($auditFilterTarget > 0) {
    $where[] = "a.target_user_id=" . $auditFilterTarget;
  }
  $whereSql = count($where) ? ('WHERE ' . implode(' AND ', $where)) : '';
  $aRes = $mysqli->query("
    SELECT
      a.id, a.actor_user_id, a.action_type, a.target_user_id,
      a.payload_json, a.result_status, a.ip, a.created_at,
      ua.email AS actor_email, ut.email AS target_email
    FROM admin_action_audit a
    LEFT JOIN users ua ON ua.id = a.actor_user_id
    LEFT JOIN users ut ON ut.id = a.target_user_id
    $whereSql
    ORDER BY a.id DESC
    LIMIT 120
  ");
  while ($aRes && ($r = $aRes->fetch_assoc())) $auditRows[] = $r;
}

$dashStats = [
  'users_total' => count($users),
  'billing_active' => 0,
  'billing_trial' => 0,
  'billing_expired' => 0,
  'billing_blocked' => 0,
  'strategies_running' => 0,
  'withdraw_pending' => 0,
];
foreach ($users as $u) {
  $bk = (string)($u['billing'] ?? '');
  if ($bk === 'exhausted') {
    $bk = 'expired';
  }
  if (isset($dashStats['billing_' . $bk])) {
    $dashStats['billing_' . $bk]++;
  }
  $dashStats['strategies_running'] += (int)($u['strategies_running'] ?? 0);
}
foreach ($withdrawals as $w) {
  if (($w['status'] ?? '') === 'pending') {
    $dashStats['withdraw_pending']++;
  }
}

function admin_billing_label(string $b): string {
  return match ($b) {
    'active' => 'Активна',
    'trial' => 'Trial',
    'exhausted' => 'Вичерпано',
    'expired' => 'Закінчилась',
    'blocked' => 'Заблок.',
    default => strtoupper($b),
  };
}

function admin_billing_balance_line(array $u): string {
  if (array_key_exists('balance_usdt', $u) && $u['balance_usdt'] !== null) {
    $bal = (float)$u['balance_usdt'];
    $sf = (float)($u['fee_shortfall_usdt'] ?? 0);
    $line = number_format($bal, 2, '.', '') . ' USDT';
    if ($sf > 1e-8) {
      $line .= ' · борг ' . number_format($sf, 2, '.', '');
    }
    $st = (string)($u['billing'] ?? '');
    if ($st === 'trial') {
      return 'тест · ' . $line;
    }
    return $line;
  }
  return 'до ' . admin_short_date(($u['paid_until'] ?? null) ?: ($u['trial_until'] ?? null));
}

function admin_short_date(?string $dt): string {
  if (!$dt) return '—';
  $ts = strtotime($dt);
  return $ts ? date('d.m.y', $ts) : '—';
}
?>
<!doctype html>
<html lang="uk">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Адмін — StrIM</title>
  <link rel="stylesheet" href="/admin/admin-panel.css?v=20260529">
</head>
<body class="adm-body">
<div class="adm-wrap">
  <header class="adm-header">
    <div>
      <h1>Адмін-панель StrIM</h1>
      <p>Користувачі, підписки, виводи та операції платформи</p>
    </div>
    <nav class="adm-nav-actions">
      <a class="adm-btn" href="/dashboard.php">← Кабінет</a>
      <a class="adm-btn adm-btn--primary" href="/admin/accounting.php">Бухгалтерія</a>
      <a class="adm-btn" href="/admin/analytics.php">Аналітика</a>
      <a class="adm-btn" href="/admin/traffic.php">Трафік</a>
      <a class="adm-btn" href="/admin/onboarding.php">Новачки та підтримка</a>
      <a class="adm-btn" href="/auth/logout.php">Вихід</a>
    </nav>
  </header>

  <div class="adm-kpis">
    <div class="adm-kpi"><div class="label">Користувачів</div><div class="value"><?= (int)$dashStats['users_total'] ?></div></div>
    <div class="adm-kpi"><div class="label">З балансом</div><div class="value"><?= (int)$dashStats['billing_active'] ?></div></div>
    <div class="adm-kpi"><div class="label">Trial</div><div class="value"><?= (int)$dashStats['billing_trial'] ?></div></div>
    <div class="adm-kpi"><div class="label">Боти (running)</div><div class="value"><?= (int)$dashStats['strategies_running'] ?></div></div>
    <div class="adm-kpi"><div class="label">Виводи (очікують)</div><div class="value"><?= (int)$dashStats['withdraw_pending'] ?></div></div>
    <div class="adm-kpi"><div class="label">STRIM (всього)</div><div class="value"><?= (int)$strimStats['total'] ?></div></div>
  </div>

  <nav class="adm-tabs" role="tablist">
    <button type="button" class="adm-tab is-active" data-tab="overview" role="tab">Огляд</button>
    <button type="button" class="adm-tab" data-tab="users" role="tab">Користувачі <span class="badge badge--muted"><?= (int)$dashStats['users_total'] ?></span></button>
    <button type="button" class="adm-tab" data-tab="withdrawals" role="tab">Виводи<?php if ($dashStats['withdraw_pending'] > 0): ?> <span class="badge"><?= (int)$dashStats['withdraw_pending'] ?></span><?php endif; ?></button>
    <button type="button" class="adm-tab" data-tab="referrers" role="tab">Реферали</button>
    <button type="button" class="adm-tab" data-tab="ops" role="tab">Операції</button>
    <button type="button" class="adm-tab" data-tab="audit" role="tab">Журнал</button>
  </nav>

  <section id="tab-overview" class="adm-panel is-active" role="tabpanel">
    <details class="adm-card adm-acc" open>
      <summary>Стан системи (торгівля, черга, Telegram)</summary>
      <div class="adm-card__body">
        <div class="adm-card__tools" style="margin-bottom:10px">
          <button class="adm-btn" type="button" onclick="runPrelaunchChecks()">Оновити</button>
          <button class="adm-btn" type="button" onclick="openWorkersModal()">Фонові процеси</button>
          <span class="adm-pill adm-mono" id="ladderModeBadge" title="Режим докупки рівнів">Режим сітки: …</span>
          <button class="adm-btn" type="button" onclick="toggleLadderMode()">Змінити режим</button>
          <button class="adm-btn" type="button" onclick="adminRestartWorkers()">Перезапустити торгівлю</button>
          <span class="adm-muted" id="opsEngineMsg"></span>
        </div>
        <div class="adm-card" style="margin:12px 0;padding:12px;border:1px solid rgba(255,255,255,.08);border-radius:10px">
          <div style="font-weight:600;margin-bottom:8px">Спадок портфеля (пороги)</div>
          <div class="adm-muted" style="font-size:12px;margin-bottom:10px;line-height:1.4">
            Класифікація мішків з біржі при онбордингу: пил / здоровий / глибоке просідання.
          </div>
          <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:end">
            <label style="font-size:12px">Пил USDT<br><input class="adm-input" id="legacyDustUsdt" type="number" step="0.1" min="0.5" style="width:90px"></label>
            <label style="font-size:12px">DD ok %<br><input class="adm-input" id="legacyDdOk" type="number" step="1" min="0" max="99" style="width:90px"></label>
            <label style="font-size:12px">DD deep %<br><input class="adm-input" id="legacyDdDeep" type="number" step="1" min="0" max="99" style="width:90px"></label>
            <label style="font-size:12px">Stale днів<br><input class="adm-input" id="legacyStaleDays" type="number" step="1" min="1" style="width:90px"></label>
            <button class="adm-btn" type="button" onclick="saveLegacyThresholds()">Зберегти пороги</button>
            <span class="adm-muted" id="legacyThreshMsg"></span>
          </div>
        </div>
        <div id="checksSummary" class="adm-muted" style="margin-bottom:8px">Завантаження перевірок…</div>
        <div id="checksList" class="checksList"></div>
      </div>
    </details>
    <details class="adm-card adm-acc">
      <summary>Реєстрації з кодом STRIM</summary>
      <div class="adm-card__body">
        <div class="adm-kpis" style="margin:0">
          <div class="adm-kpi"><div class="label">Всього</div><div class="value"><?= (int)$strimStats['total'] ?></div></div>
          <div class="adm-kpi"><div class="label">Сьогодні</div><div class="value"><?= (int)$strimStats['today'] ?></div></div>
        </div>
      </div>
    </details>
  </section>

  <section id="tab-users" class="adm-panel" role="tabpanel">
    <div class="adm-toolbar">
      <input type="search" id="userSearch" class="adm-search" placeholder="Пошук: email, нік, ref, id…" autocomplete="off">
      <span class="adm-muted" id="userSearchCount"></span>
    </div>
    <div class="adm-table-wrap">
      <table class="adm-table" id="usersTable">
        <thead>
          <tr>
            <th>#</th>
            <th>Користувач</th>
            <th>Баланс</th>
            <th>Торгівля</th>
            <th>PnL</th>
            <th>Реферал</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($users as $u):
            $searchBlob = strtolower(implode(' ', [
              (string)$u['id'],
              (string)$u['email'],
              (string)$u['nickname'],
              (string)$u['ref_code'],
              (string)$u['referrer_email'],
              (string)$u['signup_ref_code_used'],
            ]));
            $symShort = implode(', ', array_slice($u['symbols_running'], 0, 3));
            if (count($u['symbols_running']) > 3) $symShort .= '…';
          ?>
          <tr class="adm-user-row" data-search="<?= h($searchBlob) ?>">
            <td class="adm-mono"><?= (int)$u['id'] ?></td>
            <td class="adm-cell-user">
              <strong><?= h($u['email']) ?></strong>
              <?php if ($u['nickname']): ?><span class="adm-muted"><?= h($u['nickname']) ?></span><?php endif; ?>
              <?php if ((int)($u['is_admin'] ?? 0) === 1): ?><span class="adm-pill trial">admin</span><?php endif; ?>
            </td>
            <td>
              <span class="adm-pill <?= h($u['billing'] === 'exhausted' ? 'expired' : $u['billing']) ?>"><?= h(admin_billing_label((string)$u['billing'])) ?></span>
              <div class="adm-muted"><?= h(admin_billing_balance_line($u)) ?></div>
            </td>
            <td>
              <div><?= (int)$u['strategies_running'] ?>/<?= (int)$u['strategies_max_running'] ?> ботів<?= $u['strategies_max_override'] !== null ? ' <span class="adm-muted" title="індивідуальний ліміт">★</span>' : '' ?></div>
              <div class="adm-muted"><?= h($symShort ?: '—') ?></div>
              <div class="adm-muted" data-live-binance="<?= (int)$u['id'] ?>">Binance: …</div>
            </td>
            <td class="adm-mono">
              <div title="нереалізований">U <?= fmt2((float)$u['unrealized_pnl']) ?></div>
              <div class="adm-muted" title="реалізований">R <?= fmt2((float)$u['realized_pnl']) ?></div>
            </td>
            <td>
              <div><?= (int)$u['referrals_linked'] ?> ref · <?= fmt2((float)$u['referral_pending']) ?> USDT</div>
              <?php if ((int)($u['referrals_code_mismatch'] ?? 0) > 0): ?>
                <div class="checkWarn" style="font-size:0.72rem">⚠ звʼязок <?= (int)$u['referrals_code_mismatch'] ?></div>
              <?php endif; ?>
            </td>
            <td>
              <div class="adm-actions">
                <button class="adm-btn adm-btn--primary" type="button" data-user-details="<?= (int)$u['id'] ?>"
                  data-billing="<?= h((string)$u['billing']) ?>"
                  data-ref-code="<?= h((string)$u['ref_code']) ?>"
                  data-referrer="<?= h((string)($u['referrer_email'] ?: $u['signup_ref_code_used'])) ?>"
                  data-wallet="<?= h((string)$u['withdraw_wallet']) ?>"
                  data-assets="<?= h((string)$u['assets_text']) ?>"
                  data-binance="<?= $u['binance_usdt'] === null ? '' : fmt2((float)$u['binance_usdt']) ?>"
                  data-ref-linked="<?= (int)$u['referrals_linked'] ?>"
                  data-ref-pending="<?= fmt2((float)$u['referral_pending']) ?>"
                  data-ref-total="<?= fmt2((float)$u['referral_total']) ?>">Деталі</button>
                <button class="adm-btn btn-user-logs" type="button" data-user-id="<?= (int)$u['id'] ?>" data-user-email="<?= h((string)$u['email']) ?>">Логи</button>
                <div class="adm-menu">
                  <button type="button" class="adm-btn adm-menu__btn" onclick="admToggleMenu(this, event)">⋯</button>
                  <div class="adm-menu__drop">
                    <?php if (!empty($u['tg_linked'])): ?>
                    <button type="button" class="btn-tg-message" data-user-id="<?= (int)$u['id'] ?>" data-user-email="<?= h((string)$u['email']) ?>" data-user-nick="<?= h((string)($u['nickname'] ?? '')) ?>">Відправити повідомлення</button>
                    <?php else: ?>
                    <button type="button" disabled title="Користувач не підключив Telegram у кабінеті">TG не підключено</button>
                    <?php endif; ?>
                    <div class="sep"></div>
                    <button type="button" onclick="billingAction(<?= (int)$u['id'] ?>,'add500')">+500 грн</button>
                    <button type="button" onclick="billingAction(<?= (int)$u['id'] ?>,'trial')">Trial 3 дні</button>
                    <button type="button" onclick="billingAction(<?= (int)$u['id'] ?>,'block')">Заблокувати</button>
                    <button type="button" onclick="setMaxBots(<?= (int)$u['id'] ?>, <?= (int)$u['strategies_max_running'] ?>, <?= $u['strategies_max_override'] === null ? 'null' : (int)$u['strategies_max_override'] ?>)">Ліміт пар (<?= (int)$u['strategies_max_running'] ?>)</button>
                    <div class="sep"></div>
                    <button type="button" class="btn-burst-plan" data-user-id="<?= (int)$u['id'] ?>" data-user-email="<?= h((string)$u['email']) ?>">Burst після розморозки</button>
                    <button type="button" class="btn-user-topups" data-user-id="<?= (int)$u['id'] ?>" data-user-email="<?= h((string)$u['email']) ?>" data-user-nick="<?= h((string)($u['nickname'] ?? '')) ?>">Поповнення</button>
                    <div class="sep"></div>
                    <button type="button" class="btn-reset-password" data-user-id="<?= (int)$u['id'] ?>" data-user-email="<?= h((string)$u['email']) ?>">Скинути пароль</button>
                    <?php if ((int)$u['id'] !== (int)$uid && (int)($u['is_admin'] ?? 0) !== 1): ?>
                    <button type="button" class="btn-delete-user adm-btn--danger" data-user-id="<?= (int)$u['id'] ?>" data-user-email="<?= h((string)$u['email']) ?>">Видалити</button>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

  <section id="tab-withdrawals" class="adm-panel" role="tabpanel">
    <div class="adm-table-wrap">
      <table class="adm-table">
        <thead>
          <tr><th>#</th><th>Користувач</th><th>Сума</th><th>Гаманець</th><th>Статус</th><th>Дата</th><th></th></tr>
        </thead>
        <tbody>
          <?php if (!count($withdrawals)): ?>
          <tr><td colspan="7" class="adm-muted">Заявок немає</td></tr>
          <?php endif; ?>
          <?php foreach ($withdrawals as $w): ?>
          <tr>
            <td class="adm-mono"><?= (int)$w['id'] ?></td>
            <td><?= h((string)$w['email']) ?></td>
            <td><b><?= fmt2((float)$w['amount_usdt']) ?></b> USDT</td>
            <td class="adm-mono" style="max-width:160px;overflow:hidden;text-overflow:ellipsis" title="<?= h((string)$w['wallet_address']) ?>"><?= h((string)$w['wallet_address']) ?></td>
            <td><span class="adm-pill <?= ($w['status'] ?? '') === 'pending' ? 'trial' : '' ?>"><?= h((string)$w['status']) ?></span></td>
            <td class="adm-mono adm-muted"><?= h(admin_short_date((string)$w['created_at'])) ?></td>
            <td>
              <?php if (($w['status'] ?? '') === 'pending'): ?>
                <button class="adm-btn" type="button" onclick="withdrawAction(<?= (int)$w['id'] ?>,'paid')">Виплачено</button>
                <button class="adm-btn" type="button" onclick="withdrawAction(<?= (int)$w['id'] ?>,'cancel')">Скасувати</button>
              <?php else: ?><span class="adm-muted">—</span><?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

  <section id="tab-referrers" class="adm-panel" role="tabpanel">
    <div class="adm-table-wrap">
      <table class="adm-table">
        <thead><tr><th>Користувач</th><th>Код</th><th>Рефералів</th><th>Заробіток</th></tr></thead>
        <tbody>
          <?php foreach ($topReferrers as $t): ?>
          <tr>
            <td><?= h((string)$t['email']) ?></td>
            <td class="adm-mono"><?= h((string)$t['ref_code']) ?></td>
            <td><?= (int)$t['referrals_count'] ?></td>
            <td><b><?= fmt2((float)$t['earnings']) ?></b> USDT</td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

  <section id="tab-ops" class="adm-panel" role="tabpanel">
    <details class="adm-card adm-acc" open>
      <summary>Хостинг і пошта (ADM.TOOLS)</summary>
      <div class="adm-card__body">
        <div class="adm-card__tools" style="margin-bottom:10px">
          <button class="adm-btn" type="button" onclick="loadHostingStatus()">Оновити</button>
          <span class="adm-muted" id="hostingSummary">Завантаження…</span>
        </div>
        <div id="hostingChecksList" class="checksList"></div>
        <div id="hostingPanel" class="hostingPanel"></div>
      </div>
    </details>
    <details class="adm-card adm-acc" open>
      <summary>Burst після розморозки USDT (симуляція)</summary>
      <div class="adm-card__body">
        <p class="adm-muted small" style="margin-top:0">
          Read-only план burst для всіх running-пар користувача: уявні тригери, TP, сума USDT.
          Нічого не купує — лише розрахунок «що станеться після поповнення».
        </p>
        <div class="adm-card__tools" style="margin-top:10px;flex-wrap:wrap;gap:10px;align-items:flex-end">
          <label class="adm-muted small">ID користувача
            <input type="number" id="burstPlanUserId" class="adm-search" style="width:120px;margin-left:6px" min="1" placeholder="11">
          </label>
          <label class="adm-muted small">+USDT (симуляція)
            <input type="number" id="burstPlanExtraUsdt" class="adm-search" style="width:120px;margin-left:6px" min="0" step="1" value="500" placeholder="500">
          </label>
          <button class="adm-btn adm-btn--primary" type="button" onclick="loadUnfreezeBurstPlan()">Розрахувати</button>
          <span class="adm-muted" id="burstPlanMsg"></span>
        </div>
        <div id="burstPlanOut" class="burstPlanOut mt"></div>
      </div>
    </details>
    <details class="adm-card adm-acc">
      <summary>Службові команди на сервері</summary>
      <div class="adm-card__body">
        <div class="adm-card__tools" style="margin-bottom:10px">
          <button class="adm-btn" type="button" onclick="loadAdminScripts()">Оновити список</button>
          <span class="adm-muted" id="adminScriptsMsg"></span>
        </div>
        <div id="adminScriptsList" class="scriptsGrid"></div>
      </div>
    </details>
  </section>

  <section id="tab-audit" class="adm-panel" role="tabpanel">
  <?php if (!$auditTableExists): ?>
    <p class="adm-muted">Таблиця <code>admin_action_audit</code> не знайдена. Запустіть <code>run/apply_admin_audit_migration.php</code>.</p>
  <?php else: ?>
    <form method="get" class="auditFilters">
      <label class="adm-muted">Дія
        <select name="audit_action">
          <option value="">(усі)</option>
          <?php
            $actionOpts = ['billing_add30','billing_trial','billing_block','billing_set_max_bots','withdraw_paid','withdraw_cancel','billing_invalid','withdraw_invalid','billing_unknown','whitepay_webhook_paid','admin_script_run','admin_reset_password'];
            foreach ($actionOpts as $opt):
          ?>
            <option value="<?= h($opt) ?>" <?= $auditFilterAction === $opt ? 'selected' : '' ?>><?= h(strim_admin_audit_action_label($opt)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="adm-muted">ID користувача
        <input type="number" name="audit_target_user" min="1" value="<?= $auditFilterTarget > 0 ? (int)$auditFilterTarget : '' ?>" placeholder="10">
      </label>
      <button class="adm-btn" type="submit">Фільтр</button>
      <a class="adm-btn" href="/admin/users.php#audit">Скинути</a>
    </form>
    <div class="adm-table-wrap">
      <table class="adm-table">
        <thead>
          <tr><th>#</th><th>Час</th><th>Дія</th><th>Адмін</th><th>Ціль</th><th>Результат</th><th>Деталі</th></tr>
        </thead>
        <tbody>
          <?php if (!count($auditRows)): ?>
            <tr><td colspan="7" class="adm-muted">Немає записів</td></tr>
          <?php endif; ?>
          <?php foreach ($auditRows as $a):
            $payload = (string)($a['payload_json'] ?? '{}');
            $payloadShort = strlen($payload) > 80 ? (substr($payload, 0, 77) . '…') : $payload;
            $rs = (string)($a['result_status'] ?? '');
            $cls = $rs === 'ok' ? 'checkOk' : (($rs === 'invalid_input' || $rs === 'unknown_action') ? 'checkWarn' : 'checkFail');
          ?>
          <tr>
            <td class="adm-mono"><?= (int)$a['id'] ?></td>
            <td class="adm-mono adm-muted"><?= h(admin_short_date((string)$a['created_at'])) ?><br><?= h(date('H:i', strtotime((string)$a['created_at']) ?: 0)) ?></td>
            <td><?= h(strim_admin_audit_action_label((string)$a['action_type'])) ?></td>
            <td class="adm-muted">#<?= (int)$a['actor_user_id'] ?></td>
            <td class="adm-muted">#<?= (int)$a['target_user_id'] ?></td>
            <td><span class="<?= h($cls) ?>"><?= h($rs) ?></span></td>
            <td class="auditPayload adm-mono" title="<?= h($payload) ?>"><?= h($payloadShort) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
  </section>
</div>

<div id="userModal" class="modal" onclick="if(event.target===this) closeModal()">
  <div class="modalCard">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:8px">
      <h3 style="margin:0">Деталі користувача</h3>
      <button class="btn" onclick="closeModal()">Закрити</button>
    </div>
    <div id="modalBody" class="mt muted">Завантаження...</div>
  </div>
</div>

<div id="workersModal" class="modal" onclick="if(event.target===this) closeWorkersModal()">
  <div class="modalCard" style="width:min(980px,100%)">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:8px">
      <h3 style="margin:0">Фонові процеси торгівлі</h3>
      <button class="btn" onclick="closeWorkersModal()">Закрити</button>
    </div>
    <div id="workersSummary" class="small muted mt">Завантаження...</div>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:8px">
      <label class="small muted">Скільки процесів тримати
        <input id="workersTarget" type="number" min="1" max="32" value="4" style="width:90px;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.12);color:#e7eef7;border-radius:8px;padding:7px 8px">
      </label>
      <label class="small muted">Автозапуск
        <select id="workersAuto" style="background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.12);color:#e7eef7;border-radius:8px;padding:7px 8px">
          <option value="1">Увімкнено</option>
          <option value="0">Вимкнено</option>
        </select>
      </label>
      <button class="btn" onclick="workerAction('set_auto')">Зберегти</button>
      <button class="btn" onclick="workerAction('dry_run')">Перевірка (без запуску)</button>
      <button class="btn" onclick="workerAction('start')">Запустити</button>
      <button class="btn" onclick="workerAction('pause')">Зупинити</button>
      <button class="btn" onclick="workerAction('restart')">Перезапуск</button>
      <button class="btn" onclick="workerAction('cleanup_template_queue', 0)">Перевірити TEMPLATE</button>
      <button class="btn" onclick="workerCleanupTemplateApply()">Прибрати TEMPLATE</button>
      <button class="btn" onclick="loadWorkersStatus()">Оновити</button>
    </div>
    <div id="workersActionMsg" class="small muted" style="margin-top:8px"></div>
    <table class="workersTable mt">
      <thead><tr><th>№ процесу</th><th>Що робить</th><th>Стан</th><th>Працює (сек)</th></tr></thead>
      <tbody id="workersBody"><tr><td colspan="4" class="muted">Завантаження...</td></tr></tbody>
    </table>
    <h4 class="mt" style="margin-bottom:6px">Черга по стратегіях</h4>
    <p class="small muted" style="margin:0 0 8px">queued/processing + lag останнього тіку. TEMPLATE забивають чергу — приберіть кнопкою вище.</p>
    <table class="workersTable">
      <thead><tr><th>sid</th><th>uid</th><th>Пара</th><th>Статус</th><th>queued</th><th>proc</th><th>lag тіку</th></tr></thead>
      <tbody id="workersQueueBody"><tr><td colspan="7" class="muted">Завантаження...</td></tr></tbody>
    </table>
  </div>
</div>

<div id="logsModal" class="modal" onclick="if(event.target===this) closeLogsModal()">
  <div class="modalCard" style="width:min(980px,100%)">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap">
      <h3 style="margin:0">Журнал користувача</h3>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <button class="btn" type="button" onclick="copyUserLogs()">Скопіювати для аналізу</button>
        <button class="btn" type="button" onclick="closeLogsModal()">Закрити</button>
      </div>
    </div>
    <p id="logsModalUser" class="small muted mt">—</p>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:8px">
      <label class="small muted">Стратегія
        <select id="logsStrategySelect" style="min-width:200px;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.12);color:#e7eef7;border-radius:8px;padding:7px 8px"></select>
      </label>
      <button class="btn" type="button" onclick="loadUserJournal()">Оновити</button>
    </div>
    <p class="small muted" style="margin-top:8px">Показано повний журнал (включно з SYNC_EXTERNAL_BUY та технічними подіями).</p>
    <div id="logsModalOut" class="scriptOut mt" style="max-height:min(420px,55vh);overflow:auto">Завантаження…</div>
  </div>
</div>

<div id="topupsModal" class="modal" onclick="if(event.target===this) closeTopupsModal()">
  <div class="modalCard" style="width:min(920px,100%)">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap">
      <h3 style="margin:0">Поповнення Binance (депозити + Spot)</h3>
      <button class="btn" type="button" onclick="closeTopupsModal()">Закрити</button>
    </div>
    <p id="topupsModalUser" class="small muted mt">—</p>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:8px">
      <button class="btn" type="button" onclick="loadUserTopups(true)">Оновити</button>
    </div>
    <div id="topupsModalOut" class="mt" style="max-height:min(560px,65vh);overflow:auto">Завантаження…</div>
  </div>
</div>

<div id="burstPlanModal" class="modal" onclick="if(event.target===this) closeBurstPlanModal()">
  <div class="modalCard" style="width:min(980px,100%)">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap">
      <h3 style="margin:0">Burst після розморозки</h3>
      <button class="btn" type="button" onclick="closeBurstPlanModal()">Закрити</button>
    </div>
    <p id="burstPlanModalUser" class="small muted mt">—</p>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:8px">
      <label class="small muted">+USDT (симуляція)
        <input type="number" id="burstPlanModalExtra" min="0" step="1" value="500" style="min-width:100px;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.12);color:#e7eef7;border-radius:8px;padding:7px 8px;margin-left:6px">
      </label>
      <button class="btn" type="button" onclick="loadUnfreezeBurstPlan(true)">Оновити</button>
      <button class="btn" type="button" onclick="copyBurstPlanJson()">Скопіювати JSON</button>
    </div>
    <div id="burstPlanModalOut" class="burstPlanOut mt" style="max-height:min(520px,60vh);overflow:auto">—</div>
  </div>
</div>

<div id="scriptsModal" class="modal" onclick="if(event.target===this) closeScriptsModal()">
  <div class="modalCard" style="width:min(980px,100%)">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:8px">
      <h3 style="margin:0">Результат команди</h3>
      <button class="btn" onclick="closeScriptsModal()">Закрити</button>
    </div>
    <div id="scriptsModalMeta" class="small muted mt">—</div>
    <div id="scriptsModalOut" class="scriptOut mt">—</div>
  </div>
</div>

<div id="tgMessageModal" class="modal" onclick="if(event.target===this) closeTgMessageModal()">
  <div class="modalCard" style="width:min(560px,100%)">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:8px">
      <h3 style="margin:0">Повідомлення в Telegram</h3>
      <button class="btn" type="button" onclick="closeTgMessageModal()">Закрити</button>
    </div>
    <p id="tgMessageModalUser" class="small muted mt" style="margin-bottom:10px">—</p>
    <label class="small muted" style="display:block;margin-bottom:6px">Текст</label>
    <textarea id="tgMessageText" rows="7" placeholder="Текст повідомлення…" style="width:100%;box-sizing:border-box;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.12);color:#e7eef7;border-radius:8px;padding:10px;resize:vertical"></textarea>
    <label class="small muted" style="display:block;margin:12px 0 6px">Фото (необовʼязково, jpeg/png/webp/gif ≤10 МБ)</label>
    <input type="file" id="tgMessagePhoto" accept="image/jpeg,image/png,image/webp,image/gif" style="width:100%;color:#e7eef7">
    <div id="tgMessagePreview" class="mt" style="display:none">
      <img id="tgMessagePreviewImg" alt="" style="max-width:100%;max-height:180px;border-radius:8px;border:1px solid rgba(255,255,255,.12)">
    </div>
    <div id="tgMessageStatus" class="small muted mt" style="min-height:1.2em"></div>
    <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px;flex-wrap:wrap">
      <button class="adm-btn" type="button" onclick="closeTgMessageModal()">Скасувати</button>
      <button class="adm-btn adm-btn--primary" type="button" id="tgMessageSendBtn" onclick="sendTgMessage()">Надіслати</button>
    </div>
  </div>
</div>

<script>
const ADMIN_CSRF_TOKEN = <?= $csrfJson ?>;
/** Прямі /api/*.php на проді дають 500 — лише через api_gate (див. www/.htaccess). */
document.addEventListener('click', function(ev){
  const logsBtn = ev.target && ev.target.closest ? ev.target.closest('.btn-user-logs') : null;
  if (logsBtn) {
    ev.preventDefault();
    showUserLogs(Number(logsBtn.getAttribute('data-user-id') || 0), logsBtn.getAttribute('data-user-email') || '');
    return;
  }
  const detBtn = ev.target && ev.target.closest ? ev.target.closest('[data-user-details]') : null;
  if (detBtn) {
    ev.preventDefault();
    showUserDetails(Number(detBtn.getAttribute('data-user-details') || 0));
    return;
  }
  const runBtn = ev.target && ev.target.closest ? ev.target.closest('.btn-run-script') : null;
  if (runBtn) {
    ev.preventDefault();
    runAdminScript(runBtn.getAttribute('data-script-id') || '');
    return;
  }
  const pwdBtn = ev.target && ev.target.closest ? ev.target.closest('.btn-reset-password') : null;
  if (pwdBtn) {
    ev.preventDefault();
    adminResetPassword(
      Number(pwdBtn.getAttribute('data-user-id') || 0),
      pwdBtn.getAttribute('data-user-email') || ''
    );
    return;
  }
  const delBtn = ev.target && ev.target.closest ? ev.target.closest('.btn-delete-user') : null;
  if (delBtn) {
    ev.preventDefault();
    adminDeleteUser(
      Number(delBtn.getAttribute('data-user-id') || 0),
      delBtn.getAttribute('data-user-email') || ''
    );
    return;
  }
  const burstBtn = ev.target && ev.target.closest ? ev.target.closest('.btn-burst-plan') : null;
  if (burstBtn) {
    ev.preventDefault();
    openBurstPlanForUser(
      Number(burstBtn.getAttribute('data-user-id') || 0),
      burstBtn.getAttribute('data-user-email') || ''
    );
    return;
  }
  const topupsBtn = ev.target && ev.target.closest ? ev.target.closest('.btn-user-topups') : null;
  if (topupsBtn) {
    ev.preventDefault();
    openUserTopups(
      Number(topupsBtn.getAttribute('data-user-id') || 0),
      topupsBtn.getAttribute('data-user-email') || '',
      topupsBtn.getAttribute('data-user-nick') || ''
    );
    return;
  }
  const tgBtn = ev.target && ev.target.closest ? ev.target.closest('.btn-tg-message') : null;
  if (tgBtn) {
    ev.preventDefault();
    openTgMessageModal(
      Number(tgBtn.getAttribute('data-user-id') || 0),
      tgBtn.getAttribute('data-user-email') || '',
      tgBtn.getAttribute('data-user-nick') || ''
    );
  }
});

var TG_MSG_USER_ID = 0;

function closeTgMessageModal(){
  var modal = document.getElementById('tgMessageModal');
  if (modal) modal.classList.remove('open');
  TG_MSG_USER_ID = 0;
  var t = document.getElementById('tgMessageText');
  var p = document.getElementById('tgMessagePhoto');
  var prev = document.getElementById('tgMessagePreview');
  var st = document.getElementById('tgMessageStatus');
  if (t) t.value = '';
  if (p) p.value = '';
  if (prev) prev.style.display = 'none';
  if (st) st.textContent = '';
}

function openTgMessageModal(userId, email, nick){
  TG_MSG_USER_ID = Number(userId) || 0;
  if (TG_MSG_USER_ID <= 0) return;
  var modal = document.getElementById('tgMessageModal');
  var userEl = document.getElementById('tgMessageModalUser');
  var t = document.getElementById('tgMessageText');
  var p = document.getElementById('tgMessagePhoto');
  var prev = document.getElementById('tgMessagePreview');
  var st = document.getElementById('tgMessageStatus');
  if (userEl) {
    userEl.textContent = '#' + TG_MSG_USER_ID
      + (nick ? ' · ' + nick : '')
      + (email ? ' · ' + email : '');
  }
  if (t) t.value = '';
  if (p) p.value = '';
  if (prev) prev.style.display = 'none';
  if (st) st.textContent = '';
  if (modal) modal.classList.add('open');
  document.querySelectorAll('.adm-menu.is-open').forEach(function(m){ m.classList.remove('is-open'); });
  if (t) setTimeout(function(){ t.focus(); }, 50);
}

document.addEventListener('change', function(ev){
  var input = ev.target && ev.target.id === 'tgMessagePhoto' ? ev.target : null;
  if (!input) return;
  var prev = document.getElementById('tgMessagePreview');
  var img = document.getElementById('tgMessagePreviewImg');
  var file = input.files && input.files[0] ? input.files[0] : null;
  if (!file || !img || !prev) {
    if (prev) prev.style.display = 'none';
    return;
  }
  var url = URL.createObjectURL(file);
  img.onload = function(){ URL.revokeObjectURL(url); };
  img.src = url;
  prev.style.display = 'block';
});

async function sendTgMessage(){
  var uid = TG_MSG_USER_ID;
  if (uid <= 0) return;
  var t = document.getElementById('tgMessageText');
  var p = document.getElementById('tgMessagePhoto');
  var st = document.getElementById('tgMessageStatus');
  var btn = document.getElementById('tgMessageSendBtn');
  var text = t ? String(t.value || '').trim() : '';
  var file = p && p.files && p.files[0] ? p.files[0] : null;
  if (!text && !file) {
    if (st) st.textContent = 'Введіть текст або додайте фото.';
    return;
  }
  if (text.length > 4096) {
    if (st) st.textContent = 'Текст задовгий (макс. 4096).';
    return;
  }
  var fd = new FormData();
  fd.append('user_id', String(uid));
  fd.append('text', text);
  fd.append('csrf_token', ADMIN_CSRF_TOKEN);
  if (file) fd.append('photo', file);

  if (btn) btn.disabled = true;
  if (st) st.textContent = 'Надсилаю…';
  try {
    var r = await fetch(adminApi('admin_tg_message.php'), {
      method: 'POST',
      credentials: 'include',
      headers: { 'X-CSRF-Token': ADMIN_CSRF_TOKEN },
      body: fd
    });
    var j = await r.json().catch(function(){ return {}; });
    if (!j.ok) {
      var errMap = {
        tg_not_linked: 'У користувача немає підключеного Telegram.',
        empty_message: 'Порожнє повідомлення.',
        photo_type: 'Непідтримуваний тип файлу.',
        photo_size: 'Фото завелике (макс. 10 МБ).',
        tg_send_failed: 'Telegram не прийняв повідомлення' + (j.detail ? (': ' + j.detail) : '.')
      };
      if (st) st.textContent = errMap[j.error] || (j.error || 'Помилка надсилання');
      return;
    }
    if (st) st.textContent = 'Надіслано ✓';
    setTimeout(closeTgMessageModal, 700);
  } catch (e) {
    if (st) st.textContent = 'Помилка запиту';
  } finally {
    if (btn) btn.disabled = false;
  }
}

async function adminResetPassword(userId, email){
  const uid = Number(userId) || 0;
  if (uid <= 0) return;
  const label = email ? ('#' + uid + ' ' + email) : ('#' + uid);
  if (!confirm('Скинути пароль для ' + label + '?\nУсі активні сесії користувача будуть завершені.')) {
    return;
  }

  let sendMode = 'generate';
  let password = '';
  if (!confirm('Згенерувати випадковий пароль (12 символів)?\n\nOK — так, Cancel — ввести пароль вручну')) {
    password = window.prompt('Новий пароль (мін. 6 символів):', '');
    if (password === null) return;
    if (String(password).length < 6) {
      alert('Пароль занадто короткий');
      return;
    }
    sendMode = 'set';
  }

  const form = new URLSearchParams({
    user_id: String(uid),
    mode: sendMode,
    csrf_token: ADMIN_CSRF_TOKEN
  });
  if (sendMode === 'set') {
    form.set('password', password);
  }

  try {
    const r = await fetch(adminApi('admin_reset_password.php'), {
      method: 'POST',
      credentials: 'include',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
        'X-CSRF-Token': ADMIN_CSRF_TOKEN
      },
      body: form.toString()
    });
    const j = await r.json();
    if (!j.ok) {
      alert(j.error || 'Не вдалося скинути пароль');
      return;
    }
    if (j.password) {
      const copy = 'Новий пароль для ' + label + ':\n\n' + j.password + '\n\n(скопіюйте та передайте користувачу)';
      try {
        await navigator.clipboard.writeText(String(j.password));
        alert(copy + '\n\nСкопійовано в буфер обміну.');
      } catch (_e) {
        window.prompt('Скопіюйте пароль:', String(j.password));
      }
    } else {
      alert(j.message || 'Пароль оновлено');
    }
  } catch (e) {
    alert('Помилка запиту');
  }
}

async function adminDeleteUser(userId, email){
  const uid = Number(userId) || 0;
  if (uid <= 0) return;
  const label = email ? ('#' + uid + ' ' + email) : ('#' + uid);
  const w1 = 'Видалити користувача ' + label + '?\n\nЦе видалить користувача і пов’язані дані (стратегії, сесії, платежі, реферальні записи).';
  if (!confirm(w1)) return;
  const check = prompt('Підтвердження: введіть DELETE');
  if (check !== 'DELETE') return;

  const form = new URLSearchParams({
    user_id: String(uid),
    csrf_token: ADMIN_CSRF_TOKEN
  });
  try {
    const r = await fetch(adminApi('admin_delete_user.php'), {
      method: 'POST',
      credentials: 'include',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
        'X-CSRF-Token': ADMIN_CSRF_TOKEN
      },
      body: form.toString()
    });
    const j = await r.json();
    if (!j.ok) {
      alert(j.error || 'Не вдалося видалити користувача');
      return;
    }
    alert('Користувача видалено');
    location.reload();
  } catch (_e) {
    alert('Помилка запиту');
  }
}
function adminApi(path, query){
  const p = String(path || '').replace(/^\//, '');
  let url = '/auth/api_gate.php?path=' + encodeURIComponent(p);
  if (query && typeof query === 'object') {
    const qs = new URLSearchParams();
    Object.keys(query).forEach(function(k){ qs.set(k, String(query[k])); });
    const tail = qs.toString();
    if (tail) url += '&' + tail;
  }
  return url;
}
function closeWorkersModal(){
  document.getElementById('workersModal').classList.remove('open');
}

function closeScriptsModal(){
  document.getElementById('scriptsModal').classList.remove('open');
}

function closeScriptsModal(){
  document.getElementById('scriptsModal').classList.remove('open');
}

window.BURST_PLAN_LAST_JSON = null;
window.BURST_PLAN_USER_ID = 0;
window.TOPUPS_USER_ID = 0;

function closeTopupsModal(){
  const m = document.getElementById('topupsModal');
  if (m) m.classList.remove('open');
}

function openUserTopups(userId, email, nick){
  const uid = Number(userId) || 0;
  if (uid <= 0) return;
  window.TOPUPS_USER_ID = uid;
  const modal = document.getElementById('topupsModal');
  const userLine = document.getElementById('topupsModalUser');
  const label = '#' + uid + (nick ? (' · ' + nick) : '') + (email ? (' · ' + email) : '');
  if (userLine) userLine.textContent = label;
  const out = document.getElementById('topupsModalOut');
  if (out) out.textContent = 'Завантаження…';
  if (modal) modal.classList.add('open');
  // close ⋯ menu
  document.querySelectorAll('.adm-menu.open').forEach(function(el){ el.classList.remove('open'); });
  loadUserTopups(false);
}

function fmtTopupNum(v, digits){
  const n = Number(v);
  if (!isFinite(n)) return '—';
  return n.toLocaleString('uk-UA', { maximumFractionDigits: digits == null ? 2 : digits, minimumFractionDigits: 0 });
}

function renderUserTopups(j){
  const p2p = Array.isArray(j.p2p) ? j.p2p : [];
  const payIn = Array.isArray(j.pay_in) ? j.pay_in : [];
  const deposits = Array.isArray(j.deposits) ? j.deposits : [];
  const toSpot = Array.isArray(j.to_spot) ? j.to_spot
    : (Array.isArray(j.funding_to_spot) ? j.funding_to_spot : []);
  const sum = j.p2p_summary || {};
  const paySum = j.pay_in_summary || {};
  const depSum = j.deposits_summary || {};
  let html = '';
  html += '<div class="small muted" style="margin-bottom:10px">Знято: ' + escHtml(j.generated_at || '—');
  if (j.note_uk) html += ' · ' + escHtml(j.note_uk);
  html += '</div>';

  html += '<div style="margin-bottom:8px"><b>Pay / внутрішні (User→User)</b>';
  html += ' <span class="small muted">вхідних: ' + Number(paySum.rows || payIn.length || 0);
  html += ' · ' + fmtTopupNum(paySum.usdt, 2) + ' USDT</span></div>';
  if (!payIn.length) {
    html += '<div class="adm-muted small" style="margin-bottom:14px">Внутрішніх переказів Pay/C2C за період немає</div>';
  } else {
    html += '<div class="adm-table-wrap" style="margin-bottom:16px"><table class="adm-table"><thead><tr>';
    html += '<th>Дата</th><th>Від кого</th><th>Тип</th><th>Гаманець</th><th>Актив</th><th>Сума</th><th>Order ID</th>';
    html += '</tr></thead><tbody>';
    payIn.forEach(function(row){
      html += '<tr>';
      html += '<td>' + escHtml(row.at || '—') + '</td>';
      html += '<td>' + escHtml(row.from || '—') + '</td>';
      html += '<td>' + escHtml(row.order_type || '—') + '</td>';
      html += '<td>' + escHtml(row.wallet || '—') + '</td>';
      html += '<td>' + escHtml(row.asset || '—') + '</td>';
      html += '<td><b>' + escHtml(String(row.amount || '—')) + '</b></td>';
      html += '<td class="small muted">' + escHtml(String(row.order_id || row.tx_id || '—')) + '</td>';
      html += '</tr>';
    });
    html += '</tbody></table></div>';
  }

  html += '<div style="margin-bottom:8px"><b>Депозити (ончейн)</b>';
  html += ' <span class="small muted">успішних: ' + Number(depSum.ok_count || 0);
  html += ' · USDT на Spot: ' + fmtTopupNum(depSum.usdt_to_spot, 2);
  html += ' · USDT усі гаманці: ' + fmtTopupNum(depSum.usdt_all_wallets, 2) + '</span></div>';
  if (!deposits.length) {
    html += '<div class="adm-muted small" style="margin-bottom:14px">Ончейн-депозитів за період не знайдено</div>';
  } else {
    html += '<div class="adm-table-wrap" style="margin-bottom:16px"><table class="adm-table"><thead><tr>';
    html += '<th>Дата</th><th>Тип</th><th>Гаманець</th><th>Актив</th><th>Сума</th><th>Статус</th><th>Мережа / Tx</th>';
    html += '</tr></thead><tbody>';
    deposits.forEach(function(row){
      const st = String(row.status || '');
      const ok = st === 'SUCCESS' || st === 'CREDITED';
      const stClass = ok ? 'checkOk' : (st === 'PENDING' ? 'checkWarn' : 'checkFail');
      const kind = String(row.kind || '');
      const kindLabel = kind === 'INTERNAL' ? 'Внутрішній' : (kind === 'ONCHAIN' ? 'Ончейн' : kind || '—');
      html += '<tr>';
      html += '<td>' + escHtml(row.at || '—') + '</td>';
      html += '<td>' + escHtml(kindLabel) + '</td>';
      html += '<td>' + escHtml(row.wallet || '—') + '</td>';
      html += '<td>' + escHtml(row.asset || '—') + '</td>';
      html += '<td><b>' + escHtml(String(row.amount || '—')) + '</b></td>';
      html += '<td><span class="' + stClass + '">' + escHtml(st || '—') + '</span></td>';
      html += '<td class="small muted">' + escHtml(row.network || '—');
      if (row.tx_id) html += '<br><span title="' + escHtml(String(row.tx_id)) + '">' + escHtml(String(row.tx_id).slice(0, 22)) + (String(row.tx_id).length > 22 ? '…' : '') + '</span>';
      html += '</td>';
      html += '</tr>';
    });
    html += '</tbody></table></div>';
  }

  html += '<div style="margin-bottom:8px"><b>P2P (Binance C2C → USDT)</b>';
  html += ' <span class="small muted">успішних: ' + Number(sum.completed_count || 0);
  html += ' · ' + fmtTopupNum(sum.completed_usdt_net, 2) + ' USDT';
  html += ' · ' + fmtTopupNum(sum.completed_uah, 0) + ' UAH</span></div>';

  if (!p2p.length) {
    html += '<div class="adm-muted small" style="margin-bottom:14px">P2P поповнень за період не знайдено</div>';
  } else {
    html += '<div class="adm-table-wrap" style="margin-bottom:16px"><table class="adm-table"><thead><tr>';
    html += '<th>Дата</th><th>Статус</th><th>USDT (net)</th><th>UAH</th><th>Курс</th><th>Спосіб</th>';
    html += '</tr></thead><tbody>';
    p2p.forEach(function(row){
      const st = String(row.status || '');
      const ok = st === 'COMPLETED';
      const stClass = ok ? 'checkOk' : (st === 'CANCELLED' || st.indexOf('CANCELLED') === 0 ? 'checkFail' : 'checkWarn');
      html += '<tr>';
      html += '<td>' + escHtml(row.at || '—') + '</td>';
      html += '<td><span class="' + stClass + '">' + escHtml(st || '—') + '</span></td>';
      html += '<td><b>' + escHtml(String(row.usdt_net || row.usdt || '—')) + '</b>';
      if (row.usdt && row.usdt_net && row.usdt !== row.usdt_net) {
        html += ' <span class="small muted">(' + escHtml(String(row.usdt)) + ')</span>';
      }
      html += '</td>';
      html += '<td>' + escHtml(String(row.uah || '—')) + '</td>';
      html += '<td>' + escHtml(String(row.rate || '—')) + '</td>';
      html += '<td>' + escHtml(row.pay || '—') + '</td>';
      html += '</tr>';
    });
    html += '</tbody></table></div>';
  }

  const toSpotUsdt = (j.to_spot_usdt != null) ? j.to_spot_usdt : j.funding_to_spot_usdt;
  html += '<div style="margin-bottom:8px"><b>На Spot (внутрішні перекази гаманців)</b>';
  html += ' <span class="small muted">USDT: ' + fmtTopupNum(toSpotUsdt, 2) + ' · Funding / Margin / Futures → Spot</span></div>';
  if (!toSpot.length) {
    html += '<div class="adm-muted small">Переказів на Spot за період немає</div>';
  } else {
    html += '<div class="adm-table-wrap"><table class="adm-table"><thead><tr>';
    html += '<th>Дата</th><th>Тип</th><th>Актив</th><th>Сума</th><th>Статус</th><th>Tran ID</th>';
    html += '</tr></thead><tbody>';
    toSpot.forEach(function(row){
      html += '<tr>';
      html += '<td>' + escHtml(row.at || '—') + '</td>';
      html += '<td class="small">' + escHtml(row.type || '—') + '</td>';
      html += '<td>' + escHtml(row.asset || '—') + '</td>';
      html += '<td><b>' + escHtml(String(row.amount || '—')) + '</b></td>';
      html += '<td>' + escHtml(row.status || '—') + '</td>';
      html += '<td class="small muted">' + escHtml(String(row.tran_id != null ? row.tran_id : '—')) + '</td>';
      html += '</tr>';
    });
    html += '</tbody></table></div>';
  }

  if (Array.isArray(j.errors) && j.errors.length) {
    html += '<div class="checkWarn small" style="margin-top:10px">Частина вікон Binance API з помилкою (' + j.errors.length + ')</div>';
  }
  return html;
}

async function loadUserTopups(){
  const uid = Number(window.TOPUPS_USER_ID) || 0;
  const out = document.getElementById('topupsModalOut');
  if (!out || uid <= 0) return;
  out.textContent = 'Завантаження з Binance…';
  try {
    const r = await fetch(adminApi('admin_user_topups.php', { user_id: uid, months: 8 }), {
      credentials: 'include',
      headers: { 'X-CSRF-Token': ADMIN_CSRF_TOKEN }
    });
    const j = await r.json();
    if (!j.ok) {
      out.innerHTML = '<div class="checkFail">Помилка: ' + escHtml(j.error || ('HTTP ' + r.status)) + '</div>';
      return;
    }
    out.innerHTML = renderUserTopups(j);
  } catch (e) {
    out.innerHTML = '<div class="checkFail">Помилка запиту</div>';
  }
}

function closeBurstPlanModal(){
  const m = document.getElementById('burstPlanModal');
  if (m) m.classList.remove('open');
}

function openBurstPlanForUser(userId, email){
  const uid = Number(userId) || 0;
  if (uid <= 0) return;
  window.BURST_PLAN_USER_ID = uid;
  const inp = document.getElementById('burstPlanUserId');
  if (inp) inp.value = String(uid);
  const tabBtn = document.querySelector('.adm-tab[data-tab="ops"]');
  if (tabBtn) admSwitchTab('ops', true);
  const modal = document.getElementById('burstPlanModal');
  const userLine = document.getElementById('burstPlanModalUser');
  if (userLine) userLine.textContent = '#' + uid + (email ? (' · ' + email) : '');
  if (modal) modal.classList.add('open');
  loadUnfreezeBurstPlan(true);
}

function fmtBurstNum(v, digits){
  const n = Number(v);
  if (!isFinite(n)) return '—';
  return n.toFixed(digits == null ? 6 : digits);
}

function renderUnfreezeBurstPlan(report){
  const user = report.user || {};
  const frozen = report.frozen || {};
  const totals = report.totals || {};
  const strategies = Array.isArray(report.strategies) ? report.strategies : [];
  const freeNow = report.free_usdt_now_approx;
  const freeAfter = report.free_usdt_after_topup_sim;
  const extra = Number(report.extra_usdt_simulated || 0);

  let html = '<div class="burstSummary">';
  html += '<div><b>' + escHtml(user.nickname || user.email || ('#' + (user.id || '?'))) + '</b>';
  if (user.email && user.nickname) html += ' <span class="muted small">' + escHtml(user.email) + '</span>';
  html += '</div>';
  html += '<div class="small muted" style="margin-top:4px">Згенеровано: ' + escHtml(report.generated_at || '—') + '</div>';
  if (frozen.frozen) {
    html += '<div class="checkWarn small" style="margin-top:6px">Заморожено з ' + escHtml(frozen.at || '—');
    if (frozen.reason) html += ' · ' + escHtml(String(frozen.reason).slice(0, 120));
    if (frozen.free_usdt != null) html += ' · free≈' + fmtBurstNum(frozen.free_usdt, 2) + ' USDT';
    html += '</div>';
  } else {
    html += '<div class="checkOk small" style="margin-top:6px">Зараз не заморожений (burst спрацює лише після наступної розморозки)</div>';
  }
  html += '<div class="small" style="margin-top:8px">Free USDT зараз: <b>' + (freeNow == null ? '—' : fmtBurstNum(freeNow, 2)) + '</b>';
  if (extra > 0) html += ' · після +' + fmtBurstNum(extra, 0) + ' USDT ≈ <b>' + (freeAfter == null ? '—' : fmtBurstNum(freeAfter, 2)) + '</b>';
  html += '</div>';
  html += '<div class="small" style="margin-top:4px">Burst: <b>' + Number(totals.burst_levels || 0) + '</b> рівнів · <b>' + fmtBurstNum(totals.burst_usdt_needed, 2) + '</b> USDT';
  if (totals.fits_in_extra_usdt === true) html += ' · <span class="checkOk">вистачає</span>';
  else if (totals.fits_in_extra_usdt === false) html += ' · <span class="checkFail">не вистачає</span>';
  html += '</div></div>';

  strategies.forEach(function(st){
    const sym = st.symbol || '—';
    const sid = st.sid || '—';
    html += '<div class="burstStratCard"><div class="burstStratHead"><b>' + escHtml(sym) + '</b> <span class="muted small">sid=' + escHtml(String(sid)) + ' · FBA ' + fmtBurstNum(st.fba, 2) + ' USDT</span>';
    if (st.status !== 'running') {
      html += ' <span class="checkWarn small">' + escHtml(st.note || st.status || '—') + '</span></div></div>';
      return;
    }
    const burst = st.burst || {};
    const pos = st.position || {};
    html += '</div>';
    html += '<div class="small muted">Spot: ' + (st.spot == null ? '—' : fmtBurstNum(st.spot, 6));
    if (pos.max_open_lvl) html += ' · якір L' + escHtml(String(pos.max_open_lvl));
    if (burst.anchor_buy) html += ' buy≈' + fmtBurstNum(burst.anchor_buy, 6);
    html += '</div>';
    const plan = Array.isArray(burst.plan) ? burst.plan : [];
    if (!plan.length) {
      html += '<div class="small muted" style="margin-top:6px">Burst-рівнів немає (' + escHtml(burst.reason || 'no_burst_levels') + ')</div>';
    } else {
      html += '<table class="burstPlanTable"><thead><tr><th>L</th><th>Тригер</th><th>Quote</th><th>TP</th></tr></thead><tbody>';
      plan.forEach(function(p){
        html += '<tr><td>L' + escHtml(String(p.lvl)) + '</td><td>' + fmtBurstNum(p.trigger, 6) + '</td><td>' + fmtBurstNum(p.quote, 2) + '</td><td>' + fmtBurstNum(p.tp_target, 6) + '</td></tr>';
      });
      html += '</tbody></table>';
    }
    if (Array.isArray(st.missed_queue) && st.missed_queue.length) {
      html += '<div class="small muted" style="margin-top:6px">Missed queue: ' + st.missed_queue.map(function(m){ return 'L' + m.lvl; }).join(', ') + '</div>';
    }
    html += '</div>';
  });

  return html;
}

async function loadUnfreezeBurstPlan(fromModal){
  const uid = fromModal
    ? (Number(window.BURST_PLAN_USER_ID) || 0)
    : (Number((document.getElementById('burstPlanUserId') || {}).value) || 0);
  const extraInp = fromModal
    ? document.getElementById('burstPlanModalExtra')
    : document.getElementById('burstPlanExtraUsdt');
  const extra = Number(extraInp && extraInp.value != null ? extraInp.value : 0) || 0;
  const msg = document.getElementById('burstPlanMsg');
  const outInline = document.getElementById('burstPlanOut');
  const outModal = document.getElementById('burstPlanModalOut');
  const out = fromModal ? outModal : outInline;
  if (uid <= 0) {
    if (msg) msg.textContent = 'Вкажіть ID користувача';
    return;
  }
  if (!fromModal) window.BURST_PLAN_USER_ID = uid;
  if (msg) msg.textContent = 'Завантаження…';
  if (out) out.innerHTML = '<div class="muted small">Завантаження…</div>';
  try {
    const url = adminApi('admin_unfreeze_burst_plan.php', {
      user_id: String(uid),
      extra_usdt: String(extra)
    });
    const r = await fetch(url, { credentials: 'include', cache: 'no-store' });
    const j = await r.json();
    if (!j.ok) {
      const err = escHtml(j.error || 'failed');
      if (msg) msg.textContent = 'Помилка: ' + (j.error || 'failed');
      if (out) out.innerHTML = '<div class="checkFail">' + err + '</div>';
      return;
    }
    window.BURST_PLAN_LAST_JSON = j.report || null;
    const html = renderUnfreezeBurstPlan(j.report || {});
    if (out) out.innerHTML = html;
    if (!fromModal && outInline && outModal) outModal.innerHTML = html;
    if (msg) msg.textContent = 'Оновлено ' + (j.report && j.report.generated_at ? j.report.generated_at : '');
  } catch (e) {
    if (msg) msg.textContent = 'Помилка запиту';
    if (out) out.innerHTML = '<div class="checkFail">Помилка мережі</div>';
  }
}

function copyBurstPlanJson(){
  if (!window.BURST_PLAN_LAST_JSON) {
    alert('Немає даних для копіювання');
    return;
  }
  const text = JSON.stringify(window.BURST_PLAN_LAST_JSON, null, 2);
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(text).then(function(){ alert('JSON скопійовано'); }).catch(function(){
      window.prompt('JSON:', text);
    });
  } else {
    window.prompt('JSON:', text);
  }
}

let LOGS_USER_ID = 0;
let LOGS_LAST_ITEMS = [];

function closeLogsModal(){
  const m = document.getElementById('logsModal');
  if (m) m.classList.remove('open');
}

function formatJournalLine(ev){
  const ts = escHtml(ev.created_at || '');
  const type = escHtml(ev.event_type || 'event');
  const side = ev.side ? (' • ' + escHtml(ev.side)) : '';
  const lvl = ev.level_idx != null ? (' • L' + escHtml(String(ev.level_idx))) : '';
  const msg = escHtml(String(ev.message || '').replace(/\s+/g, ' ').slice(0, 500));
  return '<div class="checkCard" style="margin-bottom:8px;text-align:left">' +
    '<div class="small muted">' + ts + '</div>' +
    '<div><b>' + type + side + lvl + '</b></div>' +
    '<div class="small" style="margin-top:4px;word-break:break-word">' + msg + '</div>' +
    '</div>';
}

async function showUserLogs(userId, email){
  LOGS_USER_ID = Number(userId) || 0;
  const modal = document.getElementById('logsModal');
  const userEl = document.getElementById('logsModalUser');
  const sel = document.getElementById('logsStrategySelect');
  const out = document.getElementById('logsModalOut');
  if (!modal || !userEl || !sel || !out || LOGS_USER_ID <= 0) return;
  modal.classList.add('open');
  userEl.textContent = '#' + LOGS_USER_ID + ' ' + (email || '');
  sel.innerHTML = '';
  out.textContent = 'Завантаження…';
  LOGS_LAST_ITEMS = [];
  try {
    const r = await fetch(adminApi('admin_user_journal.php', { user_id: String(LOGS_USER_ID), list_strategies: '1' }), { credentials:'include', cache:'no-store' });
    const j = await r.json();
    if (!j.ok) {
      out.innerHTML = '<span style="color:#ff9a9a">' + escHtml(j.error || 'failed') + '</span>';
      return;
    }
    const strats = j.strategies || [];
    if (!strats.length) {
      out.textContent = 'Немає стратегій у користувача';
      return;
    }
    sel.innerHTML = strats.map(function(s){
      return '<option value="' + Number(s.id) + '">' + escHtml(s.symbol || '?') + ' #' + Number(s.id) + ' (' + escHtml(s.status || '-') + ')</option>';
    }).join('');
    sel.onchange = function(){ loadUserJournal(); };
    await loadUserJournal();
  } catch (e) {
    out.textContent = 'Помилка запиту';
  }
}

async function loadUserJournal(){
  const out = document.getElementById('logsModalOut');
  const sel = document.getElementById('logsStrategySelect');
  if (!out || !sel || LOGS_USER_ID <= 0) return;
  const sid = Number(sel.value) || 0;
  out.textContent = 'Завантаження…';
  try {
    const r = await fetch(adminApi('admin_user_journal.php', {
      user_id: String(LOGS_USER_ID),
      strategy_id: String(sid),
      limit: '80'
    }), { credentials:'include', cache:'no-store' });
    const j = await r.json();
    if (!j.ok) {
      out.innerHTML = '<span style="color:#ff9a9a">' + escHtml(j.error || 'failed') + '</span>';
      return;
    }
    LOGS_LAST_ITEMS = Array.isArray(j.items) ? j.items : [];
    if (!LOGS_LAST_ITEMS.length) {
      out.textContent = 'Журнал порожній для цієї стратегії';
      return;
    }
    out.innerHTML = LOGS_LAST_ITEMS.map(formatJournalLine).join('');
  } catch (e) {
    out.textContent = 'Помилка запиту';
  }
}

async function copyUserLogs(){
  if (!LOGS_LAST_ITEMS.length) {
    alert('Спочатку завантажте логи (кнопка «Оновити»)');
    return;
  }
  const sel = document.getElementById('logsStrategySelect');
  const sid = sel ? Number(sel.value) : 0;
  const payload = {
    user_id: LOGS_USER_ID,
    strategy_id: sid,
    exported_at: new Date().toISOString(),
    items: LOGS_LAST_ITEMS
  };
  const text = JSON.stringify(payload, null, 2);
  try {
    await navigator.clipboard.writeText(text);
    alert('Скопійовано ' + LOGS_LAST_ITEMS.length + ' подій у буфер обміну');
  } catch (e) {
    window.prompt('Скопіюйте JSON вручну:', text.slice(0, 60000));
  }
}

function escHtml(s){
  return String(s == null ? '' : s)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

const ADMIN_STATUS_UA = { ok: 'Добре', warn: 'Увага', fail: 'Проблема' };
const ADMIN_RISK_UA = { read: 'лише перегляд', maintenance: 'обслуговування', write: 'змінює дані' };

function renderCheckCards(listEl, checks){
  if (!listEl) return;
  listEl.innerHTML = (checks || []).map(c => {
    const cls = c.status === 'fail' ? 'checkFail' : (c.status === 'warn' ? 'checkWarn' : 'checkOk');
    const hint = c.hint ? '<div class="small muted" style="margin-top:4px">' + escHtml(c.hint) + '</div>' : '';
    return '<div class="checkCard">' +
      '<div><b>' + escHtml(c.label || c.key || '-') + '</b></div>' +
      '<div class="' + cls + '">' + escHtml(ADMIN_STATUS_UA[c.status] || String(c.status || '').toUpperCase()) + '</div>' +
      '<div class="small">' + escHtml(c.detail || '-') + '</div>' + hint +
    '</div>';
  }).join('');
}

async function loadHostingStatus(){
  const summary = document.getElementById('hostingSummary');
  const list = document.getElementById('hostingChecksList');
  const panel = document.getElementById('hostingPanel');
  if (summary) summary.textContent = 'Завантаження…';
  if (list) list.innerHTML = '';
  if (panel) panel.innerHTML = '';
  try {
    const r = await fetch(adminApi('admin_hosting_status.php'), { credentials:'include', cache:'no-store' });
    const j = await r.json();
    if (!j.ok) {
      if (summary) summary.innerHTML = '<span class="checkFail">Помилка: ' + escHtml(j.error || 'немає даних') + '</span>';
      return;
    }
    const s = j.summary || {};
    if (summary) {
      summary.innerHTML = 'Перевірено: ' + escHtml(j.checked_at || '-') +
        ' | <span class="checkFail">проблем: ' + Number(s.fail || 0) + '</span>' +
        ' | <span class="checkWarn">увага: ' + Number(s.warn || 0) + '</span>' +
        ' | <span class="checkOk">добре: ' + Number(s.ok || 0) + '</span>';
    }
    renderCheckCards(list, j.checks || []);
    const info = j.info || {};
    const api = info.api || {};
    const dom = info.primary_domain || {};
    const mail = info.mail || {};
    const siteMail = info.site_mail || {};
    const proc = info.processes || {};
    const changes = info.changes_since_last_check || [];
    const recent = info.recent_admin_actions || [];
    const dns = info.dns || {};
    let changesHtml = '';
    if (changes.length) {
      changesHtml = changes.map(ch =>
        '<div class="changeRow"><b>' + escHtml(ch.title || '') + '</b><br>' +
        escHtml(ch.detail || '') + '<br><span class="muted">' + escHtml(ch.at || '') + '</span></div>'
      ).join('');
    } else {
      changesHtml = '<div class="small muted">Змін з попередньої перевірки не виявлено' +
        (info.last_snapshot_at ? ' (попередня: ' + escHtml(info.last_snapshot_at) + ')' : '') + '</div>';
    }
    const mx = dns.mx_primary ? escHtml(dns.mx_primary) : '—';
    const boxes = (mail.mailboxes || []).map(escHtml).join(', ') || '—';
    const acct = info.account || {};
    const supApi = info.supervisor || {};
    const cronApi = info.crontab || {};
    const extras = info.extras || {};
    const hosts = info.hosts || [];
    const workers = (proc.workers || []).map(w =>
      'Процес ' + w.pid + (w.uptime_sec != null ? ' (' + w.uptime_sec + ' с)' : '')
    ).join('<br>') || 'немає';
    const supTasks = (supApi.tasks || []).map(t =>
      '<div style="margin:4px 0">' +
      (t.active ? '● ' : '○ ') + escHtml(t.short || t.command || '') +
      (t.task_id ? ' <span class="muted">#' + t.task_id + '</span>' : '') +
      (t.task_id ? ' <button type="button" class="adm-btn" style="padding:2px 8px;font-size:12px;margin-left:6px" onclick="restartSupervisorTask(' + Number(t.task_id) + ',\'' + escHtml(t.short || '') + '\')">Restart</button>' : '') +
      '</div>'
    ).join('') || '—';
    const cronTasks = (cronApi.tasks || []).slice(0, 12).map(t =>
      (t.active ? '● ' : '○ ') + escHtml(t.short || '') +
      (t.schedule ? ' <span class="muted">(' + escHtml(t.schedule) + ')</span>' : '') +
      (t.exit_code && t.exit_code !== '0' ? ' <span class="checkFail">exit ' + escHtml(t.exit_code) + '</span>' : '')
    ).join('<br>') || '—';
    const extraNames = (extras.list || []).map(e => escHtml(e.name || e.type || '')).join(', ') || '—';
    const hostNames = hosts.map(h => escHtml(h.fqdn || '')).join(', ') || '—';
    panel.innerHTML =
      '<div class="hostingBlock"><h3>Гроші та API</h3>' +
        '<div class="small">Баланс на хостингу: <b>' + escHtml(String(api.balance_uah ?? '—')) + ' грн</b></div>' +
        '<div class="small muted">Звʼязок з панеллю: ' + (api.connected ? 'працює' : 'немає') + '</div></div>' +
      '<div class="hostingBlock"><h3>Хостинг-акаунт</h3>' +
        '<div class="small"><b>' + escHtml(acct.login || '—') + '</b>' +
          (acct.id ? ' <span class="muted">#' + acct.id + '</span>' : '') + '</div>' +
        '<div class="small muted">Тариф: ' + escHtml(acct.plan || '—') +
          (acct.valid_until ? ' до ' + escHtml(acct.valid_until) : '') +
          (acct.days_left != null ? ' (' + acct.days_left + ' дн.)' : '') + '</div>' +
        '<div class="small muted">Диск: ' +
          (acct.quota_used_percent != null ? acct.quota_used_percent + '% зайнято' : '—') +
          (acct.quota_left ? ', вільно ' + escHtml(String(acct.quota_left)) : '') + '</div>' +
        '<div class="small muted">Сайти: ' + hostNames + '</div>' +
        '<div class="small muted">Extra: ' + extraNames + '</div></div>' +
      '<div class="hostingBlock"><h3>Домен</h3>' +
        '<div class="small"><b>' + escHtml(dom.name || 'strim.in.ua') + '</b></div>' +
        '<div class="small muted">Оплачено до: ' + escHtml(dom.expires || '—') + '</div>' +
        '<div class="small muted">Вхідна пошта (MX): ' + mx + '</div></div>' +
      '<div class="hostingBlock"><h3>Пошта</h3>' +
        '<div class="small">Скриньки: ' + boxes + '</div>' +
        '<div class="small muted">Листи з сайту: ' + (siteMail.smtp_configured ? 'налаштовано (' + escHtml(siteMail.transport || 'smtp') + ')' : 'не налаштовано') + '</div></div>' +
      '<div class="hostingBlock"><h3>Supervisor (API)</h3>' +
        '<div class="small">' + Number(supApi.active || 0) + ' / ' + Number(supApi.total || 0) + ' активні</div>' +
        '<div class="small" style="margin-top:6px">' + supTasks + '</div></div>' +
      '<div class="hostingBlock"><h3>Cron (API)</h3>' +
        '<div class="small">' + Number(cronApi.active || 0) + ' / ' + Number(cronApi.total || 0) + ' активні' +
          (cronApi.failed_recent ? ' · <span class="checkWarn">помилок: ' + cronApi.failed_recent + '</span>' : '') + '</div>' +
        '<div class="small" style="margin-top:6px">' + cronTasks + '</div></div>' +
      '<div class="hostingBlock"><h3>Процеси на сервері (локально)</h3>' +
        '<div class="small">' + (info.processes_summary || '—') + '</div>' +
        '<div class="small muted">Наглядач: ' + (proc.supervisor_running ? 'працює' : 'не працює') +
          (proc.supervisor_pid ? ' (№ ' + proc.supervisor_pid + ')' : '') + '</div>' +
        '<div class="small" style="margin-top:6px">' + workers + '</div>' +
        '<div class="small muted" style="margin-top:6px">' + escHtml(proc.cron_hint || '') + '</div></div>' +
      '<div class="hostingBlock"><h3>Що змінилось</h3>' + changesHtml + '</div>' +
      '<div class="hostingBlock"><h3>Останні дії в адмінці</h3>' +
        (recent.length ? recent.map(a =>
          '<div class="changeRow">' + escHtml(a.at || '') + ' — <b>' + escHtml(a.action || '') + '</b> (' + escHtml(a.who || '') + ')</div>'
        ).join('') : '<div class="small muted">Поки немає записів</div>') +
      '</div>';
  } catch (e) {
    if (summary) summary.innerHTML = '<span class="checkFail">Помилка завантаження хостингу</span>';
  }
}

async function restartSupervisorTask(taskId, shortName){
  if (!taskId) return;
  if (!confirm('Перезапустити Supervisor: ' + (shortName || ('#' + taskId)) + '?')) return;
  try {
    const r = await fetch(adminApi('admin_supervisor.php'), {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'restart', task_id: taskId })
    });
    const j = await r.json();
    if (!j.ok) {
      alert('Restart fail: ' + (j.error || 'unknown'));
      return;
    }
    alert('Restart OK: ' + (j.short || ('#' + taskId)));
    await loadHostingStatus();
  } catch (e) {
    alert('Restart request failed');
  }
}

async function loadAdminScripts(){
  const box = document.getElementById('adminScriptsList');
  const msg = document.getElementById('adminScriptsMsg');
  window.ADMIN_SCRIPT_INDEX = {};
  if (!box) return;
  box.innerHTML = '<div class="muted small">Завантаження…</div>';
  if (msg) msg.textContent = '';
  try{
    const r = await fetch(adminApi('admin_scripts.php'), { credentials:'include', cache:'no-store' });
    const j = await r.json();
    if(!j.ok){
      box.innerHTML = '<div class="checkFail">Помилка: ' + escHtml(j.error || 'failed') + '</div>';
      return;
    }
    const items = Array.isArray(j.items) ? j.items : [];
    if (!items.length){
      box.innerHTML = '<div class="muted small">Скриптів поки немає</div>';
      return;
    }
    items.forEach(it => { window.ADMIN_SCRIPT_INDEX[String(it.id || '')] = it; });
    box.innerHTML = items.map(it => {
      const risk = String(it.risk || 'read');
      const title = escHtml(it.title || it.id || 'script');
      const desc = escHtml(it.description || '');
      const cmd = escHtml(it.command_preview || '');
      const tmo = Number(it.timeout || 0);
      const argsHint = String(it.args_hint || '').trim();
      const argsDefault = String(it.args_default || '').trim();
      const argsLine = argsHint
        ? ('<div class="small muted" style="margin-top:6px">Параметри: <span class="mono">' + escHtml(argsHint) + '</span>' +
          (argsDefault ? (' · приклад: <span class="mono">' + escHtml(argsDefault) + '</span>') : '') + '</div>')
        : '';
      return '<div class="scriptCard">' +
        '<div class="scriptTitle">' + title + '<span class="riskTag ' + risk + '">' + escHtml(risk) + '</span></div>' +
        '<div class="small muted" style="margin-top:6px">' + desc + '</div>' +
        argsLine +
        '<div class="small muted" style="margin-top:6px">Час виконання: до ' + (tmo || '?') + ' с · ' + cmd + '</div>' +
        '<div style="margin-top:8px"><button class="btn btn-run-script" type="button" data-script-id="' + escHtml(it.id) + '">Запустити</button></div>' +
      '</div>';
    }).join('');
  }catch(e){
    box.innerHTML = '<div class="checkFail">Помилка запиту списку скриптів</div>';
  }
}

async function runAdminScript(scriptId){
  const cfg = (window.ADMIN_SCRIPT_INDEX || {})[String(scriptId)] || {};
  const title = String(cfg.title || scriptId || 'script');
  const risk = String(cfg.risk || 'read');
  const msg = document.getElementById('adminScriptsMsg');
  const m = document.getElementById('scriptsModal');
  const out = document.getElementById('scriptsModalOut');
  const meta = document.getElementById('scriptsModalMeta');
  if (!scriptId) return;
  if ((risk === 'write' || risk === 'maintenance') && !confirm('Підтвердити запуск: ' + title + ' ?')) {
    return;
  }
  let scriptArgs = '';
  const argsHint = String(cfg.args_hint || '').trim();
  if (argsHint) {
    const def = String(cfg.args_default || '').trim();
    const entered = prompt('Параметри (' + argsHint + '):', def);
    if (entered === null) return;
    scriptArgs = String(entered || '').trim();
    if (!scriptArgs) {
      alert('Для цього скрипта потрібні параметри: ' + argsHint);
      return;
    }
  }
  if (msg) msg.textContent = 'Запуск ' + title + '…';
  if (m) m.classList.add('open');
  if (meta) meta.textContent = 'Виконання…';
  if (out) out.textContent = 'Очікуємо завершення…';
  try{
    const form = new URLSearchParams({
      script_id: String(scriptId),
      csrf_token: ADMIN_CSRF_TOKEN
    });
    if (scriptArgs) form.set('script_args', scriptArgs);
    const r = await fetch(adminApi('admin_scripts.php'), {
      method:'POST',
      credentials:'include',
      headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-Token':ADMIN_CSRF_TOKEN},
      body: form.toString()
    });
    const j = await r.json();
    const ok = !!j.ok;
    if (meta) {
      meta.innerHTML = '<b>' + escHtml(j.title || title || scriptId) + '</b> · ' +
        (ok ? '<span class="checkOk">OK</span>' : '<span class="checkFail">FAIL</span>') +
        ' · exit=' + Number(j.exit_code ?? -1) + (j.timed_out ? ' · timeout' : '');
    }
    if (!ok && j.error === 'script_file_missing') {
      if (meta) meta.innerHTML = '<span class="checkFail">Файл не знайдено на сервері</span> · ' + escHtml(j.missing || '');
      if (out) out.textContent = String(j.hint || 'Запустіть deploy_admin_and_ops.sh') + '\nmissing: ' + String(j.missing || '');
      if (msg) msg.textContent = 'Помилка: файл скрипта відсутній';
      return;
    }
    const textOut = ((j.stdout || '') + (j.stderr ? ('\n\n[stderr]\n' + j.stderr) : '')).trim();
    if (out) out.textContent = textOut || '(no output)';
    if (msg) msg.textContent = ok ? 'Виконано: ' + title : 'Помилка: ' + title;
  }catch(e){
    if (meta) meta.innerHTML = '<span class="checkFail">Помилка запиту</span>';
    if (out) out.textContent = String(e && e.message ? e.message : e);
    if (msg) msg.textContent = 'Помилка запуску ' + title;
  }
}

async function openWorkersModal(){
  document.getElementById('workersModal').classList.add('open');
  await loadWorkersStatus();
}

async function loadWorkersStatus(){
  const sum = document.getElementById('workersSummary');
  const body = document.getElementById('workersBody');
  const queueBody = document.getElementById('workersQueueBody');
  const tgt = document.getElementById('workersTarget');
  const autoSel = document.getElementById('workersAuto');
  sum.textContent = 'Завантаження...';
  body.innerHTML = '<tr><td colspan="4" class="muted">Завантаження...</td></tr>';
  if (queueBody) queueBody.innerHTML = '<tr><td colspan="7" class="muted">Завантаження...</td></tr>';
  try{
    const r = await fetch(adminApi('admin_workers.php'), { credentials:'include', cache:'no-store' });
    const j = await r.json();
    if(!j.ok){
      sum.textContent = 'Помилка: ' + (j.error || 'status failed');
      return;
    }
    if (tgt && j.settings) tgt.value = Number(j.settings.target || 4);
    if (autoSel && j.settings) autoSel.value = Number(j.settings.auto || 0) ? '1' : '0';
    const q = j.queue || {};
    sum.textContent = 'queued=' + Number(q.queued||0) + ' | processing=' + Number(q.processing||0) + ' | workers=' + (j.workers||[]).length + (j.auto_triggered ? ' | auto-start' : '');
    const rows = (j.workers||[]).map(w=>{
      return '<tr><td class="mono">'+Number(w.pid||0)+'</td><td>'+String(w.role||'-')+'</td><td>'+String(w.status||'-')+'</td><td class="mono">'+Number(w.uptime_sec||0)+'</td></tr>';
    }).join('');
    body.innerHTML = rows || '<tr><td colspan="4" class="muted">Немає активних воркерів</td></tr>';
    if (queueBody) {
      const qrows = (j.queue_by_strategy||[]).map(row => {
        const lag = row.tick_lag_sec == null ? '—' : Number(row.tick_lag_sec);
        const lagCls = (typeof lag === 'number' && lag > 240) ? 'checkFail' : ((typeof lag === 'number' && lag > 90) ? 'checkWarn' : '');
        const tpl = row.is_template ? ' <span class="checkWarn">TEMPLATE</span>' : '';
        return '<tr><td class="mono">'+Number(row.strategy_id||0)+'</td><td class="mono">'+Number(row.user_id||0)+'</td><td>'+String(row.symbol||'-')+tpl+'</td><td>'+String(row.strategy_status||'-')+'</td><td class="mono">'+Number(row.queued||0)+'</td><td class="mono">'+Number(row.processing||0)+'</td><td class="mono '+lagCls+'">'+lag+'</td></tr>';
      }).join('');
      queueBody.innerHTML = qrows || '<tr><td colspan="7" class="muted">Черга порожня (queued/processing)</td></tr>';
    }
  }catch(e){
    sum.textContent = 'Помилка запиту статусу воркерів';
  }
}

async function workerCleanupTemplateApply(){
  if (!confirm('Прибрати TEMPLATE jobs з черги (queued/processing → done)?')) return;
  await workerAction('cleanup_template_queue', 1);
}

async function workerAction(action, applyTemplate){
  const msg = document.getElementById('workersActionMsg');
  const tgt = Number(document.getElementById('workersTarget')?.value || 4);
  const auto = Number(document.getElementById('workersAuto')?.value || 0);
  msg.textContent = 'Виконання...';
  try{
    const form = new URLSearchParams({
      action: String(action),
      target: String(tgt),
      auto: String(auto),
      csrf_token: ADMIN_CSRF_TOKEN
    });
    if (action === 'cleanup_template_queue') {
      form.set('apply', String(applyTemplate ? 1 : 0));
    }
    const r = await fetch(adminApi('admin_workers.php'), {
      method:'POST',
      credentials:'include',
      headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-Token':ADMIN_CSRF_TOKEN},
      body: form.toString()
    });
    const raw = await r.text();
    let j = null;
    try { j = JSON.parse(raw); } catch(_e) {}
    if (!j) {
      msg.textContent = 'Помилка API (не JSON): ' + raw.slice(0, 180);
      return;
    }
    if(!j.ok){
      msg.textContent = 'Помилка: ' + (j.message || j.error || 'worker action failed');
      return;
    }
    msg.textContent = j.message || 'Успішно';
    if (action === 'dry_run' && j.details) {
      const d = j.details;
      msg.textContent += ' | queued=' + Number(d.queued||0) + ', running=' + Number(d.running||0) + ', target=' + Number(d.target||0) + ', auto=' + Number(d.auto||0);
    }
    if (action === 'cleanup_template_queue' && j.details) {
      const d = j.details;
      msg.textContent += ' | found=' + Number(d.found||0) + (d.apply ? ', updated=' + Number(d.updated||0) : '');
    }
    await loadWorkersStatus();
    await runPrelaunchChecks();
  }catch(e){
    msg.textContent = 'Помилка запиту дії воркерів';
  }
}

async function loadEngineMode(){
  const badge = document.getElementById('ladderModeBadge');
  if (!badge) return;
  try {
    const r = await fetch(adminApi('admin_engine_settings.php'), { credentials:'include', cache:'no-store' });
    const j = await r.json();
    if (!j.ok) {
      badge.textContent = 'Мод: ?';
      badge.title = j.error || 'admin_engine_settings';
      return;
    }
    const m = j.ladder_mode === 'cascade' ? 'cascade' : 'parallel';
    const src = j.ladder_source === 'database' ? 'DB' : 'env';
    badge.textContent = 'Мод: ' + m + ' · ' + src;
    badge.title = 'Докупки: cascade — каскад від L(n−1); parallel — legacy (L1–L4 base, L5 avg, L6+ chain). Джерело: app_settings або ENGINE_LADDER_MODE';
    if (j.legacy) {
      const d = document.getElementById('legacyDustUsdt');
      const o = document.getElementById('legacyDdOk');
      const deep = document.getElementById('legacyDdDeep');
      const st = document.getElementById('legacyStaleDays');
      if (d) d.value = j.legacy.dust_usdt;
      if (o) o.value = j.legacy.dd_ok_pct;
      if (deep) deep.value = j.legacy.dd_deep_pct;
      if (st) st.value = j.legacy.stale_days;
    }
  } catch (e) {
    badge.textContent = 'Мод: —';
  }
}

async function saveLegacyThresholds(){
  const msg = document.getElementById('legacyThreshMsg');
  if (msg) msg.textContent = 'Збереження…';
  try {
    const form = new URLSearchParams({
      action: 'save_legacy_thresholds',
      csrf_token: ADMIN_CSRF_TOKEN,
      dust_usdt: String(document.getElementById('legacyDustUsdt')?.value || '7'),
      dd_ok_pct: String(document.getElementById('legacyDdOk')?.value || '15'),
      dd_deep_pct: String(document.getElementById('legacyDdDeep')?.value || '40'),
      stale_days: String(document.getElementById('legacyStaleDays')?.value || '30')
    });
    const r = await fetch(adminApi('admin_engine_settings.php'), {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': ADMIN_CSRF_TOKEN },
      body: form.toString()
    });
    const j = await r.json();
    if (!j.ok) {
      if (msg) msg.textContent = j.error || 'Помилка';
      return;
    }
    if (msg) msg.textContent = j.message || 'OK';
    await loadEngineMode();
  } catch (e) {
    if (msg) msg.textContent = 'Помилка запиту';
  }
}

async function toggleLadderMode(){
  const msg = document.getElementById('opsEngineMsg');
  if (msg) msg.textContent = 'Зміна режиму…';
  try {
    const form = new URLSearchParams({ action: 'toggle_ladder_mode', csrf_token: ADMIN_CSRF_TOKEN });
    const r = await fetch(adminApi('admin_engine_settings.php'), {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': ADMIN_CSRF_TOKEN },
      body: form.toString()
    });
    const j = await r.json();
    if (!j.ok) {
      if (msg) msg.textContent = j.error || 'Помилка зміни режиму';
      return;
    }
    if (msg) msg.textContent = j.message || 'OK';
    await loadEngineMode();
  } catch (e) {
    if (msg) msg.textContent = 'Помилка запиту (режим)';
  }
}

async function adminRestartWorkers(){
  const msg = document.getElementById('opsEngineMsg');
  if (msg) msg.textContent = 'Перезапуск воркерів…';
  try {
    let tgt = 4;
    let auto = 0;
    try {
      const st = await fetch(adminApi('admin_workers.php'), { credentials: 'include', cache: 'no-store' });
      const sj = await st.json();
      if (sj.ok && sj.settings) {
        tgt = Number(sj.settings.target || 4);
        auto = Number(sj.settings.auto || 0);
      }
    } catch (_e) {}
    const form = new URLSearchParams({
      action: 'restart',
      target: String(tgt),
      auto: String(auto),
      csrf_token: ADMIN_CSRF_TOKEN
    });
    const r = await fetch(adminApi('admin_workers.php'), {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': ADMIN_CSRF_TOKEN },
      body: form.toString()
    });
    const j = await r.json();
    if (!j.ok) {
      if (msg) msg.textContent = 'Воркери: ' + (j.message || j.error || 'помилка');
      return;
    }
    if (msg) msg.textContent = j.message || 'Воркери перезапущено';
    await runPrelaunchChecks();
    await loadEngineMode();
  } catch (e) {
    if (msg) msg.textContent = 'Помилка перезапуску воркерів';
  }
}

async function runPrelaunchChecks(){
  const summary = document.getElementById('checksSummary');
  const list = document.getElementById('checksList');
  summary.textContent = 'Завантаження...';
  list.innerHTML = '';
  try {
    const r = await fetch(adminApi('admin_prelaunch_checks.php'), { credentials:'include', cache:'no-store' });
    const j = await r.json();
    if (!j.ok) {
      summary.innerHTML = '<span class="checkFail">Помилка: ' + (j.error || 'checks failed') + '</span>';
      await loadEngineMode();
      return;
    }
    const s = j.summary || {};
    summary.innerHTML = 'Перевірено: ' + escHtml(s.checked_at || '-') +
      ' | <span class="checkFail">проблем: ' + Number(s.critical_fail || 0) + '</span>' +
      ' | <span class="checkWarn">увага: ' + Number(s.warn || 0) + '</span>' +
      ' | <span class="checkOk">добре: ' + Number(s.ok || 0) + '</span>';
    renderCheckCards(list, j.checks || []);
  } catch (e) {
    summary.innerHTML = '<span class="checkFail">Помилка запиту перевірок</span>';
  }
  await loadEngineMode();
}

async function billingAction(userId, action){
  const r = await fetch(adminApi('admin_billing.php'), {
    method:'POST',
    credentials:'include',
    headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-Token':ADMIN_CSRF_TOKEN},
    body:new URLSearchParams({user_id:String(userId), action:String(action), csrf_token:ADMIN_CSRF_TOKEN})
  });
  const j = await r.json();
  if (!j.ok) return alert(j.error || 'Billing action failed');
  location.reload();
}

async function setMaxBots(userId, currentEffective, currentOverride){
  const hint = currentOverride == null
    ? ('зараз тарифний ліміт: ' + currentEffective)
    : ('зараз індивідуальний: ' + currentOverride + ' (ефективний ' + currentEffective + ')');
  const raw = prompt(
    'Ліміт одночасних торгових пар для юзера #' + userId + '\n' +
    '(' + hint + ')\n\n' +
    'Введіть число 1–100, або порожньо / 0 щоб скинути на тариф:',
    currentOverride != null ? String(currentOverride) : String(currentEffective)
  );
  if (raw === null) return;
  const r = await fetch(adminApi('admin_billing.php'), {
    method:'POST',
    credentials:'include',
    headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-Token':ADMIN_CSRF_TOKEN},
    body:new URLSearchParams({
      user_id:String(userId),
      action:'set_max_bots',
      max:String(raw).trim(),
      csrf_token:ADMIN_CSRF_TOKEN
    })
  });
  const j = await r.json();
  if (!j.ok) return alert(j.error || 'Не вдалося змінити ліміт');
  alert('Ліміт оновлено: ефективний = ' + (j.effective || '?') +
    (j.override == null ? ' (тариф)' : ' (override)'));
  location.reload();
}

async function withdrawAction(id, action){
  const r = await fetch(adminApi('admin_withdraw.php'), {
    method:'POST',
    credentials:'include',
    headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-Token':ADMIN_CSRF_TOKEN},
    body:new URLSearchParams({id:String(id), action:String(action), csrf_token:ADMIN_CSRF_TOKEN})
  });
  const j = await r.json();
  if (!j.ok) return alert(j.error || 'Withdraw action failed');
  location.reload();
}

function closeModal(){
  document.getElementById('userModal').classList.remove('open');
}

async function showUserDetails(userId){
  const modal = document.getElementById('userModal');
  const body = document.getElementById('modalBody');
  modal.classList.add('open');
  body.innerHTML = 'Завантаження...';
  const uid = Number(userId || 0);
  const detBtn = document.querySelector('[data-user-details="' + uid + '"]');
  const extra = detBtn ? detBtn.dataset : {};
  if (!Number.isFinite(uid) || uid <= 0) {
    body.innerHTML = '<div style="color:#ff9a9a">Помилка: invalid user_id</div>';
    return;
  }
  try{
    const qs = new URLSearchParams({ user_id: String(uid), id: String(uid) });
    const requestUrl = adminApi('admin_user_details.php', { user_id: String(uid), id: String(uid) });
    let r = await fetch(requestUrl, {credentials:'include', cache:'no-store'});
    let j = await r.json();
    if (!j.ok && String(j.error || '') === 'invalid user_id') {
      r = await fetch(adminApi('admin_user_details.php'), {
        method: 'POST',
        credentials: 'include',
        cache: 'no-store',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: new URLSearchParams({ user_id: String(uid), id: String(uid) })
      });
      j = await r.json();
    }
    if (!j.ok) {
      const dbg = j && j.debug ? '<pre class="small" style="white-space:pre-wrap;color:#fca5a5">' + JSON.stringify(j.debug, null, 2) + '</pre>' : '';
      body.innerHTML =
        '<div style="color:#ff9a9a">Помилка: ' + (j.error || 'details failed') + '</div>' +
        '<div class="small muted">uid=' + uid + '</div>' +
        '<div class="small muted">url=' + requestUrl + '</div>' + dbg;
      return;
    }
    const s = j.summary || {};
    const list = (j.strategies || []).map(x => {
      const lvlRaw = x.level;
      const lvlTxt = (lvlRaw === null || lvlRaw === undefined || lvlRaw === '')
        ? '-'
        : ('L' + Number(lvlRaw));
      return '<tr>' +
        '<td class="mono">' + Number(x.strategy_id || 0) + '</td>' +
        '<td>' + (x.symbol || '-') + '</td>' +
        '<td class="mono">' + lvlTxt + '</td>' +
        '<td>' + (x.status || '-') + '</td>' +
        '<td>' + (x.position_state || '-') + '</td>' +
        '<td class="mono">' + Number(x.qty || 0).toFixed(8) + '</td>' +
        '<td class="mono">' + Number(x.avg_price || 0).toFixed(8) + '</td>' +
        '<td class="mono">' + Number(x.mark_price || 0).toFixed(8) + '</td>' +
        '<td class="mono">' + Number(x.unrealized_pnl || 0).toFixed(2) + '</td>' +
      '</tr>';
    }).join('');

    const u = j.user || {};
    body.innerHTML =
      '<div style="margin-bottom:12px"><b>#' + (u.id || 0) + '</b> ' + escHtml(u.email || '-') +
        (u.nickname ? ' <span class="muted">(' + escHtml(u.nickname) + ')</span>' : '') + '</div>' +
      '<div class="grid mt">' +
        '<div class="kpi"><div class="small">Підписка</div><div><b>' + escHtml(extra.billing || '-') + '</b></div></div>' +
        '<div class="kpi"><div class="small">Боти</div><div><b>' + Number(s.strategies_running || 0) + '/' + Number(s.strategies_total || 0) + '</b></div></div>' +
        '<div class="kpi"><div class="small">Unrealized</div><div><b>' + Number(s.unrealized_pnl || 0).toFixed(2) + ' USDT</b></div></div>' +
        '<div class="kpi"><div class="small">Realized</div><div><b>' + Number(s.realized_pnl || 0).toFixed(2) + ' USDT</b></div></div>' +
      '</div>' +
      '<div class="adm-muted small mt" style="line-height:1.6">' +
        'Код: <span class="mono">' + escHtml(extra.refCode || '-') + '</span> · Referrer: ' + escHtml(extra.referrer || '-') + '<br>' +
        'Реферали: ' + escHtml(extra.refLinked || '0') + ' · доступно ' + escHtml(extra.refPending || '0') + ' / всього ' + escHtml(extra.refTotal || '0') + ' USDT<br>' +
        'Wallet: <span class="mono">' + escHtml(extra.wallet || '-') + '</span>' +
        (extra.binance ? (' · Binance: ' + escHtml(extra.binance) + ' USDT') : '') + '<br>' +
        'Активи: ' + escHtml(extra.assets || '—') +
      '</div>' +
      '<table class="adm-table mt" style="margin-top:12px"><thead><tr><th>ID</th><th>Пара</th><th>Рівень</th><th>Статус</th><th>Позиція</th><th>Qty</th><th>Avg</th><th>Mark</th><th>U.PnL</th></tr></thead><tbody>' +
      (list || '<tr><td colspan="9" class="muted">Немає стратегій</td></tr>') +
      '</tbody></table>';
  }catch(e){
    body.innerHTML = '<div style="color:#ff9a9a">Помилка запиту</div>';
  }
}
function admSwitchTab(tabId, pushHash){
  document.querySelectorAll('.adm-tab').forEach(function(btn){
    btn.classList.toggle('is-active', btn.getAttribute('data-tab') === tabId);
  });
  document.querySelectorAll('.adm-panel').forEach(function(panel){
    panel.classList.toggle('is-active', panel.id === 'tab-' + tabId);
  });
  try { localStorage.setItem('strim_admin_tab', tabId); } catch (_e) {}
  if (pushHash) {
    try { history.replaceState(null, '', '#'+tabId); } catch (_e2) {}
  }
}

function admInitTabs(){
  var hash = (location.hash || '').replace(/^#/, '');
  var stored = '';
  try { stored = localStorage.getItem('strim_admin_tab') || ''; } catch (_e) {}
  var initial = hash || stored || 'overview';
  if (!document.getElementById('tab-' + initial)) initial = 'overview';
  admSwitchTab(initial, false);
  document.querySelectorAll('.adm-tab').forEach(function(btn){
    btn.addEventListener('click', function(){
      admSwitchTab(btn.getAttribute('data-tab') || 'overview', true);
    });
  });
  if (hash && document.getElementById('tab-' + hash)) {
    admSwitchTab(hash, false);
  }
}

function admToggleMenu(btn, ev){
  if (ev && ev.stopPropagation) ev.stopPropagation();
  var menu = btn && btn.closest ? btn.closest('.adm-menu') : null;
  if (!menu) return;
  var willOpen = !menu.classList.contains('is-open');
  document.querySelectorAll('.adm-menu.is-open').forEach(function(m){
    m.classList.remove('is-open');
  });
  if (willOpen) {
    menu.classList.add('is-open');
  }
}

document.addEventListener('click', function(ev){
  if (ev.target.closest && ev.target.closest('.adm-menu')) return;
  document.querySelectorAll('.adm-menu.is-open').forEach(function(m){
    m.classList.remove('is-open');
  });
});

function admFilterUsers(){
  var q = (document.getElementById('userSearch')?.value || '').trim().toLowerCase();
  var rows = document.querySelectorAll('.adm-user-row');
  var shown = 0;
  rows.forEach(function(tr){
    var blob = tr.getAttribute('data-search') || '';
    var ok = !q || blob.indexOf(q) !== -1;
    tr.style.display = ok ? '' : 'none';
    if (ok) shown++;
  });
  var cnt = document.getElementById('userSearchCount');
  if (cnt) cnt.textContent = q ? ('Показано ' + shown + ' з ' + rows.length) : '';
}

document.getElementById('userSearch')?.addEventListener('input', admFilterUsers);

async function loadAdminUsersLive(){
  try {
    const r = await fetch(adminApi('admin_users_live.php'), { credentials:'include', cache:'no-store' });
    const j = await r.json();
    if (!j || !j.ok || !Array.isArray(j.users)) return;
    j.users.forEach(function(u){
      const el = document.querySelector('[data-live-binance="' + String(u.user_id) + '"]');
      if (!el) return;
      if (u.binance_usdt == null) {
        el.textContent = 'Binance: ' + (u.assets_text || '—');
      } else {
        el.textContent = 'Binance: ' + Number(u.binance_usdt).toFixed(2) + ' USDT';
      }
      const btn = document.querySelector('[data-user-details="' + String(u.user_id) + '"]');
      if (btn) {
        btn.setAttribute('data-assets', u.assets_text || '');
        btn.setAttribute('data-binance', u.binance_usdt == null ? '' : Number(u.binance_usdt).toFixed(2));
      }
    });
  } catch (_e) {}
}

admInitTabs();
loadAdminUsersLive();
runPrelaunchChecks();
if (document.getElementById('tab-ops').classList.contains('is-active')) {
  loadHostingStatus();
  loadAdminScripts();
} else {
  document.querySelector('.adm-tab[data-tab="ops"]')?.addEventListener('click', function once(){
    loadHostingStatus();
    loadAdminScripts();
  }, { once: true });
}
</script>
<p style="padding:18px"><a href="/admin/consumer.php">Звернення споживачів: перевіряти кожного робочого дня</a></p></body>
</html>
