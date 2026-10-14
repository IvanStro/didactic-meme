<?php
declare(strict_types=1);

/**
 * Cabinet wallet API: balance, FX preview, ledger history.
 * GET ?action=status|fx|ledger
 */

require_once __DIR__ . '/../auth/_session_user.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_billing_access.php';
require_once __DIR__ . '/_billing_wallet.php';

use Dotenv\Dotenv;

header('Content-Type: application/json; charset=utf-8');
Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();

$db = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
if ($db->connect_errno) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'db']);
    exit;
}
$db->set_charset('utf8mb4');

$uid = current_user_id($db);
if ($uid <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'auth']);
    exit;
}

$action = trim((string)($_GET['action'] ?? 'status'));

if ($action === 'fx') {
    $uah = (float)($_GET['uah'] ?? BILLING_MIN_DEPOSIT_UAH);
    if ($uah < BILLING_MIN_DEPOSIT_UAH) {
        $uah = BILLING_MIN_DEPOSIT_UAH;
    }
    $fx = billing_binance_usdt_uah_rate();
    if (empty($fx['ok'])) {
        echo json_encode(['ok' => false, 'error' => $fx['error'] ?? 'fx'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $rate = (float)$fx['rate'];
    $usdt = billing_uah_to_usdt($uah, $rate);
    echo json_encode([
        'ok' => true,
        'uah' => $uah,
        'fx_rate' => $rate,
        'usdt' => $usdt,
        'pair' => 'USDTUAH',
        'source' => 'binance',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'ledger') {
    $limit = max(1, min(100, (int)($_GET['limit'] ?? 50)));
    $typeFilter = strtolower(trim((string)($_GET['type'] ?? 'all')));
    $allowedTypes = [
        'deposit', 'fee', 'fee_shortfall', 'admin_credit',
        'migration_credit', 'referral_credit',
    ];
    $rows = [];
    if (billing_users_has_wallet_cols($db)) {
        $uidSql = (int)$uid;
        $whereBl = "bl.user_id={$uidSql}";
        $wherePlain = "user_id={$uidSql}";
        if ($typeFilter === 'deposits') {
            $whereBl .= " AND bl.entry_type IN ('deposit','admin_credit','migration_credit')";
            $wherePlain .= " AND entry_type IN ('deposit','admin_credit','migration_credit')";
        } elseif ($typeFilter === 'fees') {
            $whereBl .= " AND bl.entry_type IN ('fee','fee_shortfall')";
            $wherePlain .= " AND entry_type IN ('fee','fee_shortfall')";
        } elseif ($typeFilter === 'bonuses') {
            $whereBl .= " AND bl.entry_type IN ('referral_credit')";
            $wherePlain .= " AND entry_type IN ('referral_credit')";
        } elseif (in_array($typeFilter, $allowedTypes, true)) {
            $esc = $db->real_escape_string($typeFilter);
            $whereBl .= " AND bl.entry_type='{$esc}'";
            $wherePlain .= " AND entry_type='{$esc}'";
        }
        // ledger enrichment v20260924b: symbol + pnl_usdt from trade_ledger / meta_json
        try {
            $q = $db->query(
                "SELECT bl.id, bl.entry_type, bl.amount_uah, bl.fx_rate, bl.amount_usdt, bl.balance_after_usdt,
                        bl.payment_ref, bl.trade_ledger_id, bl.meta_json, bl.created_at,
                        tl.symbol AS trade_symbol, tl.pnl_usdt AS trade_pnl_usdt
                 FROM billing_ledger bl
                 LEFT JOIN trade_ledger tl
                   ON tl.id = bl.trade_ledger_id AND tl.user_id = bl.user_id
                 WHERE {$whereBl}
                 ORDER BY bl.id DESC LIMIT {$limit}"
            );
        } catch (Throwable $e) {
            $q = false;
        }
        if (!$q) {
            // older schema without trade_ledger_id / meta_json
            $q = $db->query(
                "SELECT id, entry_type, amount_uah, fx_rate, amount_usdt, balance_after_usdt, payment_ref, trade_ledger_id, created_at
                 FROM billing_ledger WHERE {$wherePlain}
                 ORDER BY id DESC LIMIT {$limit}"
            );
            if (!$q) {
                $q = $db->query(
                    "SELECT id, entry_type, amount_uah, fx_rate, amount_usdt, balance_after_usdt, payment_ref, created_at
                     FROM billing_ledger WHERE {$wherePlain}
                     ORDER BY id DESC LIMIT {$limit}"
                );
            }
        }
        while ($q && ($r = $q->fetch_assoc())) {
            $etype = (string)($r['entry_type'] ?? '');
            $r['label_ua'] = match ($etype) {
                'deposit' => 'Поповнення',
                'fee' => 'Комісія 10%',
                'fee_shortfall' => 'Борг комісії',
                'admin_credit' => 'Нарахування',
                'migration_credit' => 'Міграція балансу',
                'referral_credit' => 'Реферальний бонус',
                default => $etype !== '' ? $etype : 'Операція',
            };

            $symbol = strtoupper(trim((string)($r['trade_symbol'] ?? '')));
            $pnl = null;
            $metaRaw = (string)($r['meta_json'] ?? '');
            if ($metaRaw !== '') {
                $meta = json_decode($metaRaw, true);
                if (is_array($meta) && isset($meta['pnl_usdt']) && is_numeric($meta['pnl_usdt'])) {
                    $pnl = (float)$meta['pnl_usdt'];
                }
            }
            if ($pnl === null && isset($r['trade_pnl_usdt']) && $r['trade_pnl_usdt'] !== null && $r['trade_pnl_usdt'] !== '') {
                $pnl = (float)$r['trade_pnl_usdt'];
            }
            $r['symbol'] = $symbol !== '' ? $symbol : null;
            $r['pnl_usdt'] = $pnl;
            unset($r['meta_json'], $r['trade_symbol'], $r['trade_pnl_usdt']);

            $rows[] = $r;
        }
    }
    echo json_encode(['ok' => true, 'items' => $rows, 'filter' => $typeFilter], JSON_UNESCAPED_UNICODE);
    exit;
}

// status
$w = billing_wallet_load_user($db, $uid);
$eff = billing_user_row_effective_status($w);
$pendingRef = 0.0;
$pr = @$db->query(
    "SELECT COALESCE(SUM(reward_usdt),0) s FROM referral_deposit_accruals
     WHERE referrer_user_id=" . (int)$uid . " AND status='pending'"
);
if ($pr && ($prow = $pr->fetch_assoc())) {
    $pendingRef = (float)$prow['s'];
}

echo json_encode([
    'ok' => true,
    'billing_model' => billing_percent_mode_active($db) ? 'percent' : 'subscription',
    'effective' => $eff,
    'balance_usdt' => $w['balance_usdt'] ?? 0,
    'fee_shortfall_usdt' => $w['fee_shortfall_usdt'] ?? 0,
    'wallet_activated' => !empty($w['wallet_activated_at']),
    'referral_pending_usdt' => $pendingRef,
    'min_deposit_uah' => BILLING_MIN_DEPOSIT_UAH,
    'fee_percent' => BILLING_FEE_PERCENT,
    'trial_max_pairs' => BILLING_TRIAL_MAX_RUNNING,
    'trial_max_fba' => BILLING_TRIAL_MAX_FBA_USDT,
    'funded_max_pairs' => BILLING_FUNDED_MAX_RUNNING,
], JSON_UNESCAPED_UNICODE);
