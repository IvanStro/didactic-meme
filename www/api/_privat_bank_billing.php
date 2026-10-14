<?php
declare(strict_types=1);

require_once __DIR__ . '/_privat_acp_client.php';
require_once __DIR__ . '/_billing_extend.php';

/** Мінімум депозиту UAH (percent model: будь-яка сума ≥ цього порогу). */
function privat_bank_amount_min(): float
{
    $v = getenv('PRIVAT_ACP_AMOUNT_UAH') ?: ($_ENV['PRIVAT_ACP_AMOUNT_UAH'] ?? '500');
    return max(1.0, (float) $v);
}

/** @deprecated alias — мінімум, не фіксована сума */
function privat_bank_amount_expected(): float
{
    return privat_bank_amount_min();
}

function privat_bank_amount_tolerance(): float
{
    $v = getenv('PRIVAT_ACP_AMOUNT_TOLERANCE') ?: ($_ENV['PRIVAT_ACP_AMOUNT_TOLERANCE'] ?? '2');
    return max(0.5, (float) $v);
}

function privat_bank_auto_extend_enabled(): bool
{
    $raw = getenv('PRIVAT_ACP_AUTO_EXTEND') ?: ($_ENV['PRIVAT_ACP_AUTO_EXTEND'] ?? '');
    return $raw === '1' || strcasecmp((string) $raw, 'true') === 0;
}

function privat_bank_dry_run(): bool
{
    $raw = getenv('PRIVAT_ACP_DRY_RUN') ?: ($_ENV['PRIVAT_ACP_DRY_RUN'] ?? '');
    return $raw === '1' || strcasecmp((string) $raw, 'true') === 0;
}

/** @return array{user_id:int, match_type:string}|null */
function privat_bank_match_user_from_osnd(mysqli $db, string $osnd): ?array
{
    $osnd = trim($osnd);
    if ($osnd === '') {
        return null;
    }

    if (preg_match('/\bid\s*:\s*(\d{1,10})\b/ui', $osnd, $m)) {
        $uid = (int) $m[1];
        if ($uid > 0) {
            $q = $db->query('SELECT id FROM users WHERE id=' . $uid . ' LIMIT 1');
            if ($q && $q->fetch_assoc()) {
                return ['user_id' => $uid, 'match_type' => 'id'];
            }
        }
    }

    $hay = strtoupper($osnd);
    $found = [];
    $rq = $db->query(
        "SELECT id, ref_code FROM users WHERE ref_code IS NOT NULL AND TRIM(ref_code) <> ''"
    );
    while ($rq && ($row = $rq->fetch_assoc())) {
        $ref = strtoupper(trim((string) ($row['ref_code'] ?? '')));
        if ($ref === '' || !preg_match('/^[A-Z0-9]{4,32}$/', $ref)) {
            continue;
        }
        if (str_contains($hay, $ref)) {
            $found[(int) $row['id']] = $ref;
        }
    }
    if (count($found) === 1) {
        $uid = (int) array_key_first($found);
        return ['user_id' => $uid, 'match_type' => 'ref:' . $found[$uid]];
    }

    return null;
}

function privat_bank_is_incoming_credit(array $tx): bool
{
    $tt = strtoupper(trim((string) ($tx['TRANTYPE'] ?? '')));
    if ($tt !== 'C') {
        return false;
    }
    $pr = strtolower(trim((string) ($tx['PR_PR'] ?? '')));
    if ($pr !== '' && $pr !== 'r') {
        return false;
    }
    $ccy = strtoupper(trim((string) ($tx['CCY'] ?? 'UAH')));
    if ($ccy !== '' && $ccy !== 'UAH') {
        return false;
    }
    $sum = (float) ($tx['SUM_E'] ?? $tx['SUM'] ?? 0);
    if ($sum <= 0) {
        return false;
    }
    // Percent billing: будь-яка сума ≥ мін. депозиту (з невеликим мінусовим допуском на копійки банку).
    $min = privat_bank_amount_min();
    $tol = privat_bank_amount_tolerance();
    return $sum + $tol >= $min;
}

function privat_bank_parse_tran_at(array $tx): ?string
{
    $raw = trim((string) ($tx['DATE_TIME_DAT_OD_TIM_P'] ?? ''));
    if ($raw === '') {
        $d = trim((string) ($tx['DAT_OD'] ?? ''));
        $t = trim((string) ($tx['TIM_P'] ?? ''));
        if ($d !== '') {
            $raw = $d . ($t !== '' ? ' ' . $t : '');
        }
    }
    if ($raw === '') {
        return null;
    }
    $ts = strtotime(str_replace('.', '-', $raw));
    if ($ts === false) {
        return null;
    }
    return date('Y-m-d H:i:s', $ts);
}

/** @return array{ok:bool, stats:array, error?:string} */
function privat_bank_poll_and_process(mysqli $db, int $lookbackDays = 3): array
{
    $stats = [
        'fetched' => 0,
        'inserted' => 0,
        'matched' => 0,
        'applied' => 0,
        'ignored' => 0,
        'ambiguous' => 0,
        'skipped' => 0,
        'dry_run' => privat_bank_dry_run(),
        'auto_extend' => privat_bank_auto_extend_enabled(),
    ];

    $cfg = privat_acp_config();
    if ($cfg['token'] === '') {
        return ['ok' => false, 'stats' => $stats, 'error' => 'missing_token'];
    }

    $settings = privat_acp_statement_settings();
    if (!$settings['ok']) {
        return ['ok' => false, 'stats' => $stats, 'error' => 'settings:' . ($settings['error'] ?? '?')];
    }
    $set = $settings['settings'] ?? [];
    if (($set['phase'] ?? '') !== '' && ($set['phase'] ?? '') !== 'WRK') {
        return ['ok' => false, 'stats' => $stats, 'error' => 'phase=' . (string) ($set['phase'] ?? '')];
    }
    if (($set['work_balance'] ?? 'N') === 'Y') {
        return ['ok' => false, 'stats' => $stats, 'error' => 'work_balance=Y'];
    }

    $lookbackDays = max(1, min(14, $lookbackDays));
    $endDate = date('d-m-Y');
    $startDate = date('d-m-Y', strtotime('-' . $lookbackDays . ' days'));

    $fetch = privat_acp_fetch_transactions($cfg['iban'], $startDate, $endDate, 100);
    if (!$fetch['ok']) {
        return ['ok' => false, 'stats' => $stats, 'error' => $fetch['error'] ?? 'transactions_fail'];
    }

    $txs = $fetch['transactions'];
    $stats['fetched'] = count($txs);

    foreach ($txs as $tx) {
        if (!is_array($tx) || !privat_bank_is_incoming_credit($tx)) {
            $stats['skipped']++;
            continue;
        }

        $ref = trim((string) ($tx['REF'] ?? ''));
        $refn = trim((string) ($tx['REFN'] ?? '1'));
        if ($ref === '') {
            $stats['skipped']++;
            continue;
        }

        $refEsc = $db->real_escape_string($ref);
        $refnEsc = $db->real_escape_string($refn !== '' ? $refn : '1');
        $dup = $db->query(
            "SELECT id, status, matched_user_id FROM bank_incoming_payments
             WHERE privat_ref='{$refEsc}' AND privat_refn='{$refnEsc}' LIMIT 1"
        );
        if ($dup && ($existing = $dup->fetch_assoc())) {
            if (($existing['status'] ?? '') === 'applied') {
                $stats['skipped']++;
                continue;
            }
            $rowId = (int) $existing['id'];
        } else {
            $sum = (float) ($tx['SUM_E'] ?? $tx['SUM'] ?? 0);
            $osnd = (string) ($tx['OSND'] ?? '');
            $payer = (string) ($tx['AUT_CNTR_NAM'] ?? '');
            $tranAt = privat_bank_parse_tran_at($tx);
            $tranSql = $tranAt !== null ? "'" . $db->real_escape_string($tranAt) . "'" : 'NULL';
            $accEsc = $db->real_escape_string($cfg['iban']);
            $osndEsc = $db->real_escape_string(mb_substr($osnd, 0, 512));
            $payerEsc = $db->real_escape_string(mb_substr($payer, 0, 255));
            $ttEsc = $db->real_escape_string(substr((string) ($tx['TRANTYPE'] ?? 'C'), 0, 1));
            $prEsc = $db->real_escape_string(substr((string) ($tx['PR_PR'] ?? 'r'), 0, 8));

            $db->query(
                "INSERT INTO bank_incoming_payments
                    (privat_ref, privat_refn, acc, amount_uah, ccy, osnd, trantype, pr_pr, tran_at, payer_name, status, created_at, updated_at)
                 VALUES
                    ('{$refEsc}', '{$refnEsc}', '{$accEsc}', {$sum}, 'UAH', '{$osndEsc}', '{$ttEsc}', '{$prEsc}', {$tranSql},
                     '{$payerEsc}', 'new', NOW(), NOW())"
            );
            $rowId = (int) $db->insert_id;
            if ($rowId > 0) {
                $stats['inserted']++;
            } else {
                $stats['skipped']++;
                continue;
            }
        }

        $apply = privat_bank_match_and_maybe_apply(
            $db,
            $rowId,
            (string) ($tx['OSND'] ?? ''),
            $ref,
            $refn,
            $stats
        );
        foreach ($apply as $k => $v) {
            if (isset($stats[$k]) && is_int($stats[$k]) && is_int($v)) {
                $stats[$k] += $v;
            }
        }
    }

    // Добити matched/new, які вже в БД (наприклад AUTO_EXTEND щойно увімкнули),
    // навіть якщо транзакція випала з lookback API.
    $backfill = privat_bank_apply_pending_matched($db, 14);
    foreach (['matched', 'applied', 'ignored', 'ambiguous'] as $k) {
        if (isset($backfill[$k])) {
            $stats[$k] = (int) ($stats[$k] ?? 0) + (int) $backfill[$k];
        }
    }
    $stats['backfill_scanned'] = (int) ($backfill['scanned'] ?? 0);

    return ['ok' => true, 'stats' => $stats];
}

/**
 * Match OSND → user, optionally +30 днів.
 *
 * @param array<string,mixed> $stats
 * @return array{matched?:int, applied?:int, ignored?:int, ambiguous?:int}
 */
function privat_bank_match_and_maybe_apply(
    mysqli $db,
    int $rowId,
    string $osnd,
    string $ref,
    string $refn,
    array $stats = []
): array {
    $out = ['matched' => 0, 'applied' => 0, 'ignored' => 0, 'ambiguous' => 0];
    if ($rowId <= 0) {
        return $out;
    }

    $cur = $db->query(
        'SELECT status, matched_user_id, amount_uah FROM bank_incoming_payments WHERE id=' . (int) $rowId . ' LIMIT 1'
    );
    $curRow = $cur ? $cur->fetch_assoc() : null;
    if (!$curRow || (string) ($curRow['status'] ?? '') === 'applied') {
        return $out;
    }
    $amountUah = (float) ($curRow['amount_uah'] ?? 0);

    $uid = (int) ($curRow['matched_user_id'] ?? 0);
    $matchType = '';
    if ($uid <= 0 || (string) ($curRow['status'] ?? '') === 'new') {
        $match = privat_bank_match_user_from_osnd($db, $osnd);
        if ($match === null) {
            $db->query(
                "UPDATE bank_incoming_payments SET status='ignored', match_note='no_user_in_osnd', updated_at=NOW()
                 WHERE id=" . (int) $rowId . " AND status<>'applied'"
            );
            $out['ignored'] = 1;
            return $out;
        }
        $uid = (int) $match['user_id'];
        $matchType = (string) $match['match_type'];
        $matchNote = $db->real_escape_string($matchType);
        $db->query(
            "UPDATE bank_incoming_payments SET status='matched', matched_user_id={$uid}, match_note='{$matchNote}', updated_at=NOW()
             WHERE id=" . (int) $rowId . " AND status<>'applied'"
        );
        $out['matched'] = 1;
    }

    if (!privat_bank_auto_extend_enabled()) {
        return $out;
    }

    if (privat_bank_dry_run()) {
        $out['applied'] = 1;
        return $out;
    }

    $db->begin_transaction();
    try {
        $lock = $db->query(
            'SELECT id, status, matched_user_id, amount_uah FROM bank_incoming_payments WHERE id=' . (int) $rowId . ' FOR UPDATE'
        );
        $locked = $lock ? $lock->fetch_assoc() : null;
        if (!$locked || (string) ($locked['status'] ?? '') !== 'matched') {
            $db->rollback();
            return $out;
        }
        $uid = (int) ($locked['matched_user_id'] ?? $uid);
        if ($uid <= 0) {
            $db->rollback();
            return $out;
        }
        $lockedAmt = (float) ($locked['amount_uah'] ?? $amountUah);
        if ($lockedAmt <= 0) {
            $lockedAmt = $amountUah > 0 ? $amountUah : privat_bank_amount_min();
        }

        $txKey = $ref . ':' . ($refn !== '' ? $refn : '1');
        $ext = billing_extend_subscription_30d($db, $uid, 'privat_bank', $txKey, 10.0, $lockedAmt);
        if (!($ext['ok'] ?? false)) {
            $errEsc = $db->real_escape_string((string) ($ext['error'] ?? 'extend_fail'));
            $db->query(
                "UPDATE bank_incoming_payments SET status='ambiguous', match_note='{$errEsc}', updated_at=NOW()
                 WHERE id=" . (int) $rowId
            );
            $db->commit();
            $out['ambiguous'] = 1;
            return $out;
        }

        $db->query(
            "UPDATE bank_incoming_payments SET status='applied', applied_at=NOW(), updated_at=NOW()
             WHERE id=" . (int) $rowId
        );
        $db->commit();
        $out['applied'] = 1;
        return $out;
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}

/**
 * Нарахувати +30 для уже зматчених рядків (коли AUTO_EXTEND щойно увімкнули).
 *
 * @return array{scanned:int, matched:int, applied:int, ignored:int, ambiguous:int}
 */
function privat_bank_apply_pending_matched(mysqli $db, int $maxAgeDays = 14): array
{
    $out = ['scanned' => 0, 'matched' => 0, 'applied' => 0, 'ignored' => 0, 'ambiguous' => 0];
    if (!privat_bank_auto_extend_enabled()) {
        return $out;
    }

    $maxAgeDays = max(1, min(60, $maxAgeDays));
    $q = $db->query(
        "SELECT id, osnd, privat_ref, privat_refn, status, matched_user_id
         FROM bank_incoming_payments
         WHERE status IN ('new','matched')
           AND created_at >= (NOW() - INTERVAL {$maxAgeDays} DAY)
         ORDER BY id ASC
         LIMIT 100"
    );
    while ($q && ($row = $q->fetch_assoc())) {
        $out['scanned']++;
        $delta = privat_bank_match_and_maybe_apply(
            $db,
            (int) $row['id'],
            (string) ($row['osnd'] ?? ''),
            (string) ($row['privat_ref'] ?? ''),
            (string) ($row['privat_refn'] ?? '1')
        );
        foreach (['matched', 'applied', 'ignored', 'ambiguous'] as $k) {
            $out[$k] += (int) ($delta[$k] ?? 0);
        }
    }

    return $out;
}
