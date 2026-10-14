# Go-Live 48h Checklist

Goal: release safely before active promotion.  
Scope: billing, referrals, withdrawals, trading runtime, admin operations, observability.

## T-48h to T-24h (stabilization)

### 1) Security hardening
- [x] Admin write endpoints protected by admin auth (`admin_billing.php`, `admin_withdraw.php`).
- [x] CSRF protection enabled for admin write actions (header/body token + cookie validation).
- [ ] You: rotate all production secrets older than 90 days (`DB_PASS`, API keys, telegram bot token, ops secret).
- [ ] You: verify least-privilege DB user (no DROP/ALTER in runtime credentials).

### 2) Financial correctness
- [ ] You: run manual E2E with 2 test users:
  1. set referrer relationship,
  2. apply paid month (`+30 days / 10 USDT`),
  3. confirm reward is exactly `2.00 USDT`,
  4. create withdraw,
  5. mark paid/cancel and verify final balances.
- [ ] You: SQL reconciliation check:
  - `SUM(referral_rewards.status='pending')` equals dashboard "available".
  - paid withdrawals are excluded from pending balance.
- [ ] You: validate no duplicate rewards for repeated billing clicks.

### 3) Trading reliability
- [ ] You: verify workers uptime for >=24h (no restart loop, no queue deadlock).
- [ ] You: check `ops_status` for `failed_last_hour`, `oldest_queued_age_seconds`, `engine.alive`.
- [ ] You: verify at least one active strategy user has correct unrealized P/L and order flow.

## T-24h to T-4h (pre-launch rehearsals)

### 4) Release rehearsal
- [x] Added smoke script `run/prelaunch_smoke.sh`.
- [ ] You: run:
  - `BASE_URL=https://strim.in.ua OPS_STATUS_SECRET=... ./run/prelaunch_smoke.sh`
- [ ] You: capture output in launch notes.

### 5) UX and support readiness
- [ ] You: prepare user-facing FAQ:
  - what "zero balance" means,
  - what "access error" means for Binance keys,
  - how referral payouts are calculated.
- [ ] You: add support macro templates for 5 common incidents.

### 6) Legal/comms
- [ ] You: verify Terms/Privacy/Risk disclosure pages are publicly reachable.
- [ ] You: add explicit “not financial advice” and exchange risk statements.

## T-4h to T+0h (launch gate)

### 7) Hard gate checks (must be green)
- [ ] `api/health.php` => `ok=true`, `db_ok=true`.
- [ ] `api/ops_status.php` => auth works, engine alive, failed jobs acceptable.
- [ ] Admin page:
  - Users list loads,
  - Billing actions apply,
  - Withdraw actions apply,
  - Details modal opens for at least 3 users.
- [ ] User dashboard:
  - Balance visible,
  - Active pair state visible,
  - No JS/runtime errors in browser console.

### 8) Rollback readiness
- [ ] You: backup current prod files before each deploy.
- [ ] You: keep last known good deploy snapshot path in notes.
- [ ] You: define rollback owner + max rollback decision time (e.g. 10 min).

## T+0h to T+48h (hypercare)

### 9) Monitoring cadence
- [ ] You: every 30-60 min check:
  - health/ops endpoints,
  - failed jobs/hour,
  - new paid users,
  - pending withdrawals growth.
- [ ] You: create incident channel (Telegram) for alerts.

### 10) Incident playbook
- [ ] You: if billing/referral mismatch appears:
  1. pause promo traffic,
  2. freeze payout actions,
  3. reconcile SQL by user,
  4. patch and re-open.

---

## What was implemented by agent in this cycle
- Admin auth + CSRF on write endpoints.
- Admin details endpoint robustness fixes.
- Hidden `TEMPLATE` from admin analytics display.
- Added reproducible smoke script (`run/prelaunch_smoke.sh`).
- Added admin actions audit logging (`admin_action_audit` migration + API writes).
- Added read-only referral reconciliation SQL (`run/sql_referral_audit_readonly.sql`).

## Recommended next engineering tasks (post-launch)
- Add DB-level unique/idempotency key for referral rewards by payment event.
- Add audit table for admin actions (who/when/what/ip/ua).
- Add rate limiting for admin write endpoints.
