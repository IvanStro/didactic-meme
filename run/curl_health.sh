#!/usr/bin/env bash
# curl до /api/health.php з HEALTH_TOKEN з .env (корінь проєкту).
# Використання:
#   bash run/curl_health.sh
#   bash run/curl_health.sh | python3 -m json.tool

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BASE_URL="${BASE_URL:-https://strim.in.ua}"
ENV_FILE="${ENV_FILE:-$ROOT/.env}"

if [[ -f "$ENV_FILE" ]]; then
  HEALTH_TOKEN="$(grep -E '^HEALTH_TOKEN=' "$ENV_FILE" | head -1 | cut -d= -f2- | sed 's/\r$//' | tr -d '"' | tr -d "'")"
  export HEALTH_TOKEN
fi

URL="${BASE_URL}/api/health.php"
ARGS=(-sS -f)

if [[ -n "${HEALTH_TOKEN:-}" ]]; then
  ARGS+=(-H "X-Strim-Health-Token: ${HEALTH_TOKEN}")
fi

curl "${ARGS[@]}" "$URL"
