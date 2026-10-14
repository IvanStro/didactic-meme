#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${BASE_URL:-https://strim.in.ua}"
OPS_SECRET="${OPS_STATUS_SECRET:-}"

echo "==> Prelaunch smoke for ${BASE_URL}"

echo "-- health"
curl -fsS "${BASE_URL}/api/health.php" >/tmp/prelaunch_health.json
python3 - <<'PY'
import json
j=json.load(open('/tmp/prelaunch_health.json'))
assert j.get('ok') is True, "health ok=false"
if 'db_ok' in j:
    assert j.get('db_ok') is True, "db_ok=false"
print("health: ok")
print("engine_alive:", j.get('engine_alive'))
PY

if [ -n "${OPS_SECRET}" ]; then
  echo "-- ops_status"
  curl -fsS -H "X-OPS-SECRET: ${OPS_SECRET}" "${BASE_URL}/api/ops_status.php" >/tmp/prelaunch_ops.json
  python3 - <<'PY'
import json
j=json.load(open('/tmp/prelaunch_ops.json'))
assert j.get('ok') is True, "ops ok=false"
print("ops_status: ok")
print("engine_alive:", j.get('engine',{}).get('alive'))
print("job_queue:", j.get('job_queue',{}))
PY
else
  echo "-- ops_status skipped (OPS_STATUS_SECRET not set)"
fi

echo "-- dashboard redirect"
curl -sI "${BASE_URL}/dashboard.php" >/tmp/prelaunch_dashboard_headers.txt
python3 - <<'PY'
h=open('/tmp/prelaunch_dashboard_headers.txt').read().lower()
assert 'location:' in h, "no location header on dashboard.php"
print("dashboard redirect: ok")
PY

echo "==> Smoke completed"
