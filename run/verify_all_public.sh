#!/usr/bin/env bash
# Перевірка публічно доступних URL (без сесії / з очікуваним 401).
# Використання: bash run/verify_all_public.sh
#   BASE_URL=https://strim.in.ua bash run/verify_all_public.sh

set -euo pipefail

BASE_URL="${BASE_URL:-https://strim.in.ua}"
FAIL=0

expect_code() {
  local url="$1"
  local want="$2"
  local code
  code=$(curl -sS -o /tmp/verify_all_body.txt -w "%{http_code}" "$url" || true)
  if [[ "$code" != "$want" ]]; then
    echo "FAIL: $code (expected $want)  $url"
    FAIL=1
  else
    echo "OK  $code  $url"
  fi
}

echo "=== verify_all_public BASE_URL=$BASE_URL ==="

expect_code "${BASE_URL}/api/health.php" 403
expect_code "${BASE_URL}/dashboard.php" 302
expect_code "${BASE_URL}/about.html" 200
expect_code "${BASE_URL}/instruction.html" 200
expect_code "${BASE_URL}/offer.html" 200
expect_code "${BASE_URL}/auth/register.php" 200
expect_code "${BASE_URL}/pair_details.html" 200
# UK root pages must not serve EN canonical (prod drift guard)
for _p in about.html tariffs.html instruction.html; do
  body=$(curl -sS "${BASE_URL}/${_p}" || true)
  if echo "$body" | grep -q 'lang="uk"' && echo "$body" | grep -q "canonical\" href=\"https://strim.in.ua/${_p}\""; then
    echo "OK  uk  ${BASE_URL}/${_p}"
  else
    echo "FAIL: ${_p} missing lang=uk or wrong canonical"
    FAIL=1
  fi
done

expect_code "${BASE_URL}/auth/api_gate.php?path=me.php" 401
expect_code "${BASE_URL}/auth/api_gate.php?path=saas_core.php" 401
expect_code "${BASE_URL}/admin/users.php" 302

echo ""
echo "=== health.php (локально з токеном, якщо є .env) ==="
if [[ -f "$(dirname "$0")/../.env" ]] && grep -q '^HEALTH_TOKEN=' "$(dirname "$0")/../.env" 2>/dev/null; then
  bash "$(dirname "$0")/curl_health.sh" | python3 -c "
import json,sys
j=json.load(sys.stdin)
assert j.get('ok') is True, 'health ok=false'
print('ok:', j.get('ok'))
print('db_ok:', j.get('db_ok'))
print('engine_alive:', j.get('engine_alive'))
print('lag_seconds:', j.get('lag_seconds'))
"
else
  echo "SKIP: немає HEALTH_TOKEN у .env — див. docs/SEO_GSC_SETUP.md"
fi

echo ""
if [[ "$FAIL" -ne 0 ]]; then
  echo "=== SUMMARY: Є помилки HTTP-кодів ==="
  exit 1
fi
echo "=== SUMMARY: Усі перевірені URL відповідають очікуванням ==="
exit 0
