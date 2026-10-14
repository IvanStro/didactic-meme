<?php
declare(strict_types=1);

/**
 * Wallet billing (percent model): FX, deposits, 10% fee, shortfall, referral accrual.
 * Spec: docs/BILLING_PERCENT_PLAN_UA.md
 */

require_once __DIR__ . '/_billing_access.php';

const BILLING_MIN_DEPOSIT_UAH = 500.0;
const BILLING_FEE_PERCENT = 10.0;
const BILLING_REFERRAL_PERCENT = 20.0;
const BILLING_TRIAL_MAX_RUNNING = 3;
const BILLING_FUNDED_MAX_RUNNING = 50;
const BILLING_TRIAL_MAX_FBA_USDT = 10.0;

function billing_model_is_percent(mysqli $db): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $r = $db->query("SELECT v FROM app_settings WHERE k='billing_model' LIMIT 1");
    $v = ($r && ($row = $r->fetch_assoc())) ? strtolower(trim((string)($row['v'] ?? ''))) : '';
    $cached = ($v === 'percent' || $v === '');
    // Empty → percent after cutover migration; subscription only if explicitly set.
    if ($v === 'subscription') {
        $cached = false;
    } elseif ($v === '') {
        $cached = true;
    }
    return $cached;
}

/**
 * Binance public ticker USDTUAH → UAH per 1 USDT.
 * amount_usdt = amount_uah / rate
 *
 * @return array{ok:bool, rate?:float, error?:string}
 */
function billing_binance_usdt_uah_rate(): array
{
    $url = 'https://api.binance.com/api/v3/ticker/price?symbol=USDTUAH';
    $ctx = stream_context_create([
        'http' => ['timeout' => 8, 'ignore_errors' => true],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false || $raw === '') {
        return ['ok' => false, 'error' => 'fx_http'];
    }
    $j = json_decode($raw, true);
    $rate = is_array($j) ? (float)($j['price'] ?? 0) : 0.0;
    if ($rate < 10.0 || $rate > 200.0) {
        return ['ok' => false, 'error' => 'fx_range', 'rate' => $rate];
    }
    return ['ok' => true, 'rate' => $rate];
}

function billing_uah_to_usdt(float $uah, float $rate): float
{
    if ($uah <= 0 || $rate <= 0) {
        return 0.0;
    }
    return round($uah / $rate, 8);
}

/**
 * @return array{balance_usdt:float, fee_shortfall_usdt:float, wallet_activated_at:?string, billing_status:string}|null
 */
function billing_wallet_load_user(mysqli $db, int $userId): ?array
{
    if ($userId <= 0) {
        return null;
    }
    $cols = 'id, billing_status, trial_until, paid_until';
    if (billing_users_has_wallet_cols($db)) {
        $cols .= ', balance_usdt, fee_shortfall_usdt, wallet_activated_at';
    }
    $r = $db->query('SELECT ' . $cols . ' FROM users WHERE id=' . (int)$userId . ' LIMIT 1');
    $row = $r ? $r->fetch_assoc() : null;
    if (!$row) {
        return null;
    }
    return [
        'balance_usdt' => (float)($row['balance_usdt'] ?? 0),
        'fee_shortfall_usdt' => (float)($row['fee_shortfall_usdt'] ?? 0),
        'wallet_activated_at' => isset($row['wallet_activated_at']) && $row['wallet_activated_at'] !== null && $row['wallet_activated_at'] !== ''
            ? (string)$row['wallet_activated_at']
            : null,
        'billing_status' => (string)($row['billing_status'] ?? 'trial'),
        'trial_until' => isset($row['trial_until']) ? (string)$row['trial_until'] : null,
        'paid_until' => isset($row['paid_until']) ? (string)$row['paid_until'] : null,
    ];
}

function billing_users_has_wallet_cols(mysqli $db): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    $r = @$db->query("SHOW COLUMNS FROM users LIKE 'balance_usdt'");
    $ok = $r && $r->num_rows > 0;
    return $ok;
}

/**
 * Percent-model effective status: trial | active | exhausted | blocked
 */
function billing_percent_effective_status(?array $walletRow): string
{
    if (!$walletRow) {
        return 'expired';
    }
    $st = (string)($walletRow['billing_status'] ?? 'trial');
    if ($st === 'blocked') {
        return 'blocked';
    }
    $activated = !empty($walletRow['wallet_activated_at']);
    if (!$activated) {
        return 'trial';
    }
    $bal = (float)($walletRow['balance_usdt'] ?? 0);
    if ($bal > 1e-8) {
        return 'active';
    }
    return 'exhausted';
}

/**
 * Insert ledger row and update users.balance_usdt (caller holds transaction ideally).
 *
 * @param array<string,mixed> $extra
 * @return array{ok:bool, ledger_id?:int, balance_after?:float, error?:string}
 */
function billing_wallet_credit(
    mysqli $db,
    int $userId,
    string $entryType,
    float $amountUsdt,
    ?float $amountUah = null,
    ?float $fxRate = null,
    ?string $paymentRef = null,
    ?int $tradeLedgerId = null,
    ?int $referralAccrualId = null,
    array $extra = []
): array {
    if ($userId <= 0 || $amountUsdt <= 0) {
        return ['ok' => false, 'error' => 'invalid_amount'];
    }
    if (!billing_users_has_wallet_cols($db)) {
        return ['ok' => false, 'error' => 'wallet_not_migrated'];
    }

    $db->begin_transaction();
    try {
        $r = $db->query('SELECT balance_usdt, fee_shortfall_usdt, wallet_activated_at FROM users WHERE id=' . (int)$userId . ' FOR UPDATE');
        $u = $r ? $r->fetch_assoc() : null;
        if (!$u) {
            $db->rollback();
            return ['ok' => false, 'error' => 'user_not_found'];
        }

        $balance = (float)$u['balance_usdt'];
        $shortfall = (float)$u['fee_shortfall_usdt'];
        $remaining = $amountUsdt;
        $appliedToShortfall = 0.0;

        // Auto-pay shortfall from any credit (deposit / admin / migration / referral settle)
        if ($shortfall > 1e-12 && in_array($entryType, ['deposit', 'admin_credit', 'migration_credit', 'referral_credit'], true)) {
            $pay = min($shortfall, $remaining);
            if ($pay > 1e-12) {
                $shortfall = round($shortfall - $pay, 8);
                $remaining = round($remaining - $pay, 8);
                $appliedToShortfall = $pay;
                $sfAfter = $balance; // shortfall pay doesn't increase spendable until remaining credited
                $metaSf = json_encode(
                    array_merge($extra, ['from_entry' => $entryType, 'shortfall_paid' => $pay]),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                );
                $db->query(
                    "INSERT INTO billing_ledger
                      (user_id, entry_type, amount_uah, fx_rate, amount_usdt, balance_after_usdt, payment_ref, meta_json)
                     VALUES (
                      " . (int)$userId . ",
                      'shortfall_pay',
                      NULL, NULL,
                      " . number_format($pay, 8, '.', '') . ",
                      " . number_format($sfAfter, 8, '.', '') . ",
                      " . ($paymentRef !== null ? ("'" . $db->real_escape_string($paymentRef) . "'") : 'NULL') . ",
                      '" . $db->real_escape_string((string)$metaSf) . "'
                     )"
                );
            }
        }

        $balance = round($balance + $remaining, 8);
        $uahSql = $amountUah !== null ? number_format($amountUah, 2, '.', '') : 'NULL';
        $fxSql = $fxRate !== null ? number_format($fxRate, 8, '.', '') : 'NULL';
        $refSql = $paymentRef !== null ? ("'" . $db->real_escape_string(substr($paymentRef, 0, 191)) . "'") : 'NULL';
        $tlSql = $tradeLedgerId !== null && $tradeLedgerId > 0 ? (string)(int)$tradeLedgerId : 'NULL';
        $raSql = $referralAccrualId !== null && $referralAccrualId > 0 ? (string)(int)$referralAccrualId : 'NULL';
        $meta = $extra;
        if ($appliedToShortfall > 0) {
            $meta['shortfall_paid_usdt'] = $appliedToShortfall;
            $meta['credited_to_balance_usdt'] = $remaining;
        }
        $metaSql = $meta
            ? ("'" . $db->real_escape_string(json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . "'")
            : 'NULL';
        $typeEsc = $db->real_escape_string($entryType);

        if ($remaining > 1e-12 || $entryType === 'deposit' || $amountUsdt > 0) {
            // Always record main credit line for the operation (amount_usdt = full gross for audit)
            $gross = $amountUsdt;
            $db->query(
                "INSERT INTO billing_ledger
                  (user_id, entry_type, amount_uah, fx_rate, amount_usdt, balance_after_usdt,
                   trade_ledger_id, payment_ref, referral_accrual_id, meta_json)
                 VALUES (
                  " . (int)$userId . ",
                  '{$typeEsc}',
                  {$uahSql}, {$fxSql},
                  " . number_format($gross, 8, '.', '') . ",
                  " . number_format($balance, 8, '.', '') . ",
                  {$tlSql}, {$refSql}, {$raSql}, {$metaSql}
                 )"
            );
        }
        if ($db->errno) {
            $err = $db->error;
            $db->rollback();
            return ['ok' => false, 'error' => 'ledger_insert:' . $err];
        }
        $ledgerId = (int)$db->insert_id;

        $actSql = '';
        if (empty($u['wallet_activated_at']) && in_array($entryType, ['deposit', 'admin_credit', 'migration_credit'], true)) {
            $actSql = ', wallet_activated_at=IFNULL(wallet_activated_at, NOW()), billing_status=\'active\'';
        } elseif ($balance > 1e-8) {
            $actSql = ', billing_status=IF(billing_status=\'blocked\',\'blocked\',\'active\')';
        }

        $db->query(
            'UPDATE users SET balance_usdt=' . number_format($balance, 8, '.', '') .
            ', fee_shortfall_usdt=' . number_format(max(0.0, $shortfall), 8, '.', '') .
            $actSql .
            ' WHERE id=' . (int)$userId . ' LIMIT 1'
        );

        $db->commit();
        return [
            'ok' => true,
            'ledger_id' => $ledgerId,
            'balance_after' => $balance,
            'shortfall_after' => max(0.0, $shortfall),
            'shortfall_paid' => $appliedToShortfall,
        ];
    } catch (Throwable $e) {
        $db->rollback();
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Credit UAH deposit: FX → USDT, activate wallet, referral accrual.
 *
 * @return array{ok:bool, ledger_id?:int, amount_usdt?:float, fx_rate?:float, error?:string}
 */
function billing_wallet_deposit_uah(
    mysqli $db,
    int $userId,
    float $amountUah,
    string $paymentSource,
    string $paymentRef = '',
    ?float $fxOverride = null
): array {
    if ($amountUah + 1e-9 < BILLING_MIN_DEPOSIT_UAH) {
        return ['ok' => false, 'error' => 'min_deposit_uah'];
    }
    $rate = $fxOverride;
    if ($rate === null || $rate <= 0) {
        $fx = billing_binance_usdt_uah_rate();
        if (empty($fx['ok'])) {
            return ['ok' => false, 'error' => (string)($fx['error'] ?? 'fx')];
        }
        $rate = (float)$fx['rate'];
    }
    $usdt = billing_uah_to_usdt($amountUah, $rate);
    if ($usdt <= 0) {
        return ['ok' => false, 'error' => 'fx_zero'];
    }

    $res = billing_wallet_credit(
        $db,
        $userId,
        'deposit',
        $usdt,
        $amountUah,
        $rate,
        $paymentRef !== '' ? $paymentRef : ($paymentSource . '_' . date('YmdHis')),
        null,
        null,
        ['payment_source' => $paymentSource]
    );
    if (empty($res['ok'])) {
        return $res;
    }

    billing_referral_accrue_on_deposit(
        $db,
        $userId,
        (int)($res['ledger_id'] ?? 0),
        $usdt
    );

    require_once __DIR__ . '/_billing_access.php';
    $resumed = billing_resume_strategies_after_renewal($db, $userId);
    $res['amount_usdt'] = $usdt;
    $res['fx_rate'] = $rate;
    $res['resumed_strategies'] = $resumed;
    return $res;
}

/**
 * Admin / migration: credit 500 UAH at live Binance FX.
 */
function billing_wallet_credit_uah_pack(mysqli $db, int $userId, float $uah, string $entryType, string $ref): array
{
    $fx = billing_binance_usdt_uah_rate();
    if (empty($fx['ok'])) {
        return ['ok' => false, 'error' => (string)($fx['error'] ?? 'fx')];
    }
    $rate = (float)$fx['rate'];
    $usdt = billing_uah_to_usdt($uah, $rate);
    $res = billing_wallet_credit($db, $userId, $entryType, $usdt, $uah, $rate, $ref);
    if (!empty($res['ok'])) {
        require_once __DIR__ . '/_billing_access.php';
        $res['resumed_strategies'] = billing_resume_strategies_after_renewal($db, $userId);
        $res['amount_usdt'] = $usdt;
        $res['fx_rate'] = $rate;
        if ($entryType === 'deposit' || $entryType === 'admin_credit') {
            // admin_credit does not create referral accrual (not a user deposit)
        }
    }
    return $res;
}

/**
 * Charge 10% of positive realized PnL. Idempotent per trade_ledger_id.
 *
 * @return array{ok:bool, skipped?:bool, fee?:float, error?:string}
 */
function billing_wallet_charge_profit_fee(mysqli $db, int $userId, int $tradeLedgerId, float $pnlUsdt): array
{
    if (!billing_model_is_percent($db) || !billing_users_has_wallet_cols($db)) {
        return ['ok' => true, 'skipped' => true];
    }
    if ($userId <= 0 || $tradeLedgerId <= 0 || $pnlUsdt <= 1e-12) {
        return ['ok' => true, 'skipped' => true];
    }

    $fee = round($pnlUsdt * (BILLING_FEE_PERCENT / 100.0), 8);
    if ($fee <= 0) {
        return ['ok' => true, 'skipped' => true];
    }

    $db->begin_transaction();
    try {
        $dup = $db->query(
            "SELECT id FROM billing_ledger
             WHERE trade_ledger_id=" . (int)$tradeLedgerId . " AND entry_type='fee' LIMIT 1"
        );
        if ($dup && $dup->fetch_assoc()) {
            $db->commit();
            return ['ok' => true, 'skipped' => true, 'fee' => $fee];
        }

        $r = $db->query('SELECT balance_usdt, fee_shortfall_usdt, wallet_activated_at, billing_status FROM users WHERE id=' . (int)$userId . ' FOR UPDATE');
        $u = $r ? $r->fetch_assoc() : null;
        if (!$u) {
            $db->rollback();
            return ['ok' => false, 'error' => 'user_not_found'];
        }

        // Trial without wallet: no fee yet (optional product choice — charge 0 until funded)
        if (empty($u['wallet_activated_at'])) {
            $db->commit();
            return ['ok' => true, 'skipped' => true, 'reason' => 'trial_no_wallet'];
        }

        $balance = (float)$u['balance_usdt'];
        $shortfall = (float)$u['fee_shortfall_usdt'];
        $take = min($balance, $fee);
        $newShort = round($shortfall + max(0.0, $fee - $take), 8);
        $newBal = round($balance - $take, 8);

        $db->query(
            "INSERT INTO billing_ledger
              (user_id, entry_type, amount_usdt, balance_after_usdt, trade_ledger_id, meta_json)
             VALUES (
              " . (int)$userId . ",
              'fee',
              " . number_format(-$fee, 8, '.', '') . ",
              " . number_format($newBal, 8, '.', '') . ",
              " . (int)$tradeLedgerId . ",
              '" . $db->real_escape_string(json_encode([
                  'pnl_usdt' => $pnlUsdt,
                  'fee_percent' => BILLING_FEE_PERCENT,
                  'taken_usdt' => $take,
                  'shortfall_added_usdt' => max(0.0, $fee - $take),
              ], JSON_UNESCAPED_UNICODE)) . "'
             )"
        );
        if ($db->errno) {
            // Duplicate unique → already charged
            $db->rollback();
            if (str_contains($db->error, 'Duplicate') || (int)$db->errno === 1062) {
                return ['ok' => true, 'skipped' => true];
            }
            return ['ok' => false, 'error' => $db->error];
        }

        $statusSql = '';
        if ($newBal <= 1e-8) {
            $statusSql = ", billing_status=IF(billing_status='blocked','blocked','expired')";
        }

        $db->query(
            'UPDATE users SET balance_usdt=' . number_format($newBal, 8, '.', '') .
            ', fee_shortfall_usdt=' . number_format($newShort, 8, '.', '') .
            $statusSql .
            ' WHERE id=' . (int)$userId . ' LIMIT 1'
        );

        $db->commit();

        if ($newBal <= 1e-8) {
            require_once __DIR__ . '/_billing_access.php';
            billing_pause_user_running_strategies($db, $userId);
        }

        return ['ok' => true, 'fee' => $fee, 'taken' => $take, 'balance_after' => $newBal, 'shortfall_after' => $newShort];
    } catch (Throwable $e) {
        $db->rollback();
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function billing_pause_user_running_strategies(mysqli $db, int $userId): int
{
    if ($userId <= 0) {
        return 0;
    }
    $db->query(
        "UPDATE strategies SET status='paused'
         WHERE status='running' AND (owner_id=" . (int)$userId . " OR (owner_id IS NULL AND user_id=" . (int)$userId . "))"
    );
    return (int)$db->affected_rows;
}

function billing_referral_accrue_on_deposit(mysqli $db, int $referredUserId, int $depositLedgerId, float $depositUsdt): void
{
    if ($referredUserId <= 0 || $depositLedgerId <= 0 || $depositUsdt <= 0) {
        return;
    }
    $r = $db->query('SELECT referred_by_user_id FROM users WHERE id=' . (int)$referredUserId . ' LIMIT 1');
    $row = $r ? $r->fetch_assoc() : null;
    $referrer = (int)($row['referred_by_user_id'] ?? 0);
    if ($referrer <= 0) {
        return;
    }
    $reward = round($depositUsdt * (BILLING_REFERRAL_PERCENT / 100.0), 8);
    $db->query(
        "INSERT IGNORE INTO referral_deposit_accruals
          (referrer_user_id, referred_user_id, deposit_ledger_id, deposit_usdt, reward_percent, reward_usdt, status)
         VALUES (
          {$referrer}, " . (int)$referredUserId . ", {$depositLedgerId},
          " . number_format($depositUsdt, 8, '.', '') . ",
          " . number_format(BILLING_REFERRAL_PERCENT, 2, '.', '') . ",
          " . number_format($reward, 8, '.', '') . ",
          'pending'
         )"
    );
}

/**
 * Settle pending referral accruals created before $beforeDatetime (exclusive end of period).
 *
 * @return array{settled:int, amount_usdt:float}
 */
function billing_referral_settle_pending(mysqli $db, ?string $beforeDatetime = null): array
{
    $before = $beforeDatetime ?: date('Y-m-d H:i:s');
    $beforeEsc = $db->real_escape_string($before);
    $q = $db->query(
        "SELECT id, referrer_user_id, reward_usdt FROM referral_deposit_accruals
         WHERE status='pending' AND created_at < '{$beforeEsc}'
         ORDER BY id ASC LIMIT 500"
    );
    $n = 0;
    $sum = 0.0;
    while ($q && ($row = $q->fetch_assoc())) {
        $id = (int)$row['id'];
        $uid = (int)$row['referrer_user_id'];
        $amt = (float)$row['reward_usdt'];
        if ($amt <= 0) {
            $db->query("UPDATE referral_deposit_accruals SET status='cancelled', settled_at=NOW() WHERE id={$id} LIMIT 1");
            continue;
        }
        $cred = billing_wallet_credit(
            $db,
            $uid,
            'referral_credit',
            $amt,
            null,
            null,
            'referral_settle_' . $id,
            null,
            $id,
            ['accrual_id' => $id]
        );
        if (empty($cred['ok'])) {
            continue;
        }
        $lid = (int)($cred['ledger_id'] ?? 0);
        $db->query(
            "UPDATE referral_deposit_accruals
             SET status='settled', settled_at=NOW(), settle_ledger_id=" . ($lid > 0 ? $lid : 'NULL') . "
             WHERE id={$id} AND status='pending' LIMIT 1"
        );
        $n++;
        $sum += $amt;
    }
    return ['settled' => $n, 'amount_usdt' => $sum];
}
