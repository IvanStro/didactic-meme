#!/usr/bin/env bash
# Налаштування webhook для StrIM AI бота (читає .env з кореня репо).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ENV_FILE="${ROOT}/.env"

if [[ ! -f "$ENV_FILE" ]]; then
  echo "Немає файлу .env у $ROOT" >&2
  exit 1
fi

# shellcheck disable=SC1090
source <(grep -E '^(STRIM_AI_TG_BOT_TOKEN|STRIM_AI_WEBHOOK_SECRET)=' "$ENV_FILE" | sed 's/^/export /')

TOKEN="${STRIM_AI_TG_BOT_TOKEN:-}"
SECRET="${STRIM_AI_WEBHOOK_SECRET:-}"
BASE_URL="${STRIM_AI_WEBHOOK_URL:-https://strim.in.ua/api/tg_ai_webhook.php}"

if [[ -z "$TOKEN" || -z "$SECRET" ]]; then
  echo "Задайте STRIM_AI_TG_BOT_TOKEN і STRIM_AI_WEBHOOK_SECRET у .env" >&2
  exit 1
fi

ENC_URL="$(python3 -c "import urllib.parse; print(urllib.parse.quote('''$BASE_URL'''))")"
ENC_SECRET="$(python3 -c "import urllib.parse; print(urllib.parse.quote('''$SECRET'''))")"
ALLOWED='["message"]'
ENC_ALLOWED="$(python3 -c "import urllib.parse; print(urllib.parse.quote('''$ALLOWED'''))")"

URL="https://api.telegram.org/bot${TOKEN}/setWebhook?url=${ENC_URL}&secret_token=${ENC_SECRET}&allowed_updates=${ENC_ALLOWED}"

echo "→ $BASE_URL"
curl -sS "$URL" | python3 -m json.tool
