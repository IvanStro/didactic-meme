#!/usr/bin/env bash
# Безпечно завершити процеси за pattern (без process substitution — ADM bash).
# Usage: run/_pkill_safe.sh 'regex'
set -euo pipefail
pat="${1:?pattern}"
me=$$
pp=${PPID:-0}
tmp="/tmp/strim_pkill_$$.txt"
pgrep -f "$pat" >"$tmp" 2>/dev/null || true
killed=0
while read -r pid; do
  [[ -z "$pid" ]] && continue
  [[ "$pid" == "$me" || "$pid" == "$pp" ]] && continue
  cmd=$(ps -o args= -p "$pid" 2>/dev/null || true)
  [[ "$cmd" == *"_pkill_safe.sh"* ]] && continue
  [[ "$cmd" == *"fix_adm_supervisor"* ]] && continue
  [[ "$cmd" == *"apply_adm_supervisor"* ]] && continue
  kill "$pid" 2>/dev/null || true
  killed=$((killed + 1))
done < "$tmp"
sleep 1
pgrep -f "$pat" >"$tmp" 2>/dev/null || true
while read -r pid; do
  [[ -z "$pid" ]] && continue
  [[ "$pid" == "$me" || "$pid" == "$pp" ]] && continue
  cmd=$(ps -o args= -p "$pid" 2>/dev/null || true)
  [[ "$cmd" == *"_pkill_safe.sh"* ]] && continue
  kill -9 "$pid" 2>/dev/null || true
done < "$tmp"
rm -f "$tmp"
echo "pkill_safe $pat killed≈$killed"
