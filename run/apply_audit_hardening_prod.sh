#!/usr/bin/env bash
set -euo pipefail
cd /home/bot20/strim.in.ua
PHP=/usr/local/php84/bin/php

echo "== rotate master key =="
$PHP run/rotate_master_key.php
$PHP run/rotate_master_key.php --apply --write-env

echo "== ensure TG_WEBHOOK_SECRET =="
python3 <<'PY'
from pathlib import Path
import secrets
p = Path(".env")
raw = p.read_text()
lines = raw.splitlines()
found = False
out = []
for line in lines:
    if line.startswith("TG_WEBHOOK_SECRET="):
        found = True
        v = line.split("=", 1)[1].strip().strip("\"'")
        if not v:
            v = secrets.token_urlsafe(32)
            out.append("TG_WEBHOOK_SECRET=" + v)
            print("TG_WEBHOOK_SECRET_SET")
        else:
            out.append(line)
            print("TG_WEBHOOK_SECRET_ALREADY")
    else:
        out.append(line)
if not found:
    v = secrets.token_urlsafe(32)
    out.append("TG_WEBHOOK_SECRET=" + v)
    print("TG_WEBHOOK_SECRET_ADDED")
text = "\n".join(out)
if raw.endswith("\n"):
    text += "\n"
p.write_text(text)
PY

echo "== set trading tg webhook =="
$PHP run/set_trading_tg_webhook.php

echo "== verify keys =="
$PHP <<'PHP'
<?php
require "www/vendor/autoload.php";
require_once "www/api/_user_keys.php";
Dotenv\Dotenv::createImmutable(getcwd())->safeLoad();
$db = new mysqli($_ENV["DB_HOST"], $_ENV["DB_USER"], $_ENV["DB_PASS"], $_ENV["DB_NAME"]);
$db->set_charset("utf8mb4");
$id = (int)$db->query("SELECT id FROM users WHERE api_key_enc<>'' LIMIT 1")->fetch_assoc()["id"];
[$a, $b] = user_keys($db, $id);
echo "user_keys_uid=$id " . ($a ? "OK" : "FAIL") . PHP_EOL;
echo "MASTER_len=" . strlen((string)($_ENV["MASTER_KEY"] ?? "")) . PHP_EOL;
echo "TG_secret_len=" . strlen(trim((string)($_ENV["TG_WEBHOOK_SECRET"] ?? ""))) . PHP_EOL;
PHP

echo "== restart signal daemons =="
if [[ -x ./run/restart_v2_signal_daemons.sh ]]; then
  ./run/restart_v2_signal_daemons.sh
elif [[ -x ./run/restart_signal_daemons.sh ]]; then
  ./run/restart_signal_daemons.sh
else
  for f in services/signal/*.pid; do
    [[ -f "$f" ]] || continue
    pid=$(cat "$f" 2>/dev/null || true)
    if [[ -n "${pid:-}" ]]; then kill "$pid" 2>/dev/null || true; fi
    rm -f "$f"
  done
  echo "cleared signal pids; cron_ensure will respawn"
fi

echo "== done =="
