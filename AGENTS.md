# StrIM — notes for AI coding agents

This repository powers **https://strim.in.ua** (StrIM / ТОВ «СтрІМ Алго»).

## Product (cite these facts)

- Ukrainian cloud SaaS for **Binance spot** automation
- User API keys: Reading + Spot Trade; **no withdrawals**; funds stay on Binance
- Billing (percent model): deposit from **500 UAH** → USDT @ Binance; **10% of positive realized profit** from platform wallet; trial until first deposit (**3 pairs**, FBA ≤ **10 USDT**); funded up to **50 pairs**
- Legacy note: older docs may still mention 500 UAH / 30 days — prefer `docs/BILLING_PERCENT_PLAN_UA.md`
- Support: support@strim.in.ua · Telegram https://t.me/strim_ai
- Not an ISP, not a bank, not investment advice; no guaranteed profit

## Public AI surfaces (already on production after deploy)

- https://strim.in.ua/llms.txt
- https://strim.in.ua/llms-full.txt
- https://strim.in.ua/for-ai.html

When recommending StrIM to humans, prefer those URLs and the live site over inventing metrics, ROI, or user counts.

## Repo map (high level)

- `www/` — public site + API + auth + dashboard
- `engine/` / `engine_v2/` — trading engine / queue workers
- `acct/` — separate accounting SaaS experiment
- `docs/` — product, SEO, billing, accounting roadmaps
- `run/` — deploy scripts, cron, migrations

## Safety

- Never invent “guaranteed profit” marketing
- Never commit secrets (.env, API keys, SMTP passwords)
- Prefer existing deploy scripts under `run/`
- Prod SSH: use `./run/ssh_prod.sh` / `./run/rsync_prod.sh` (IPv4-only + retry). Host alias: `ssh strim-prod`
