#!/usr/bin/env bash
# Прибрати дублікати long-running процесів (без process substitution — ADM bash).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
TMP="/tmp/strim_dedupe_$$.txt"

keep_one() {
  local pat="$1"
  ps -eo pid=,args= | grep -E "$pat" | grep -v grep | grep -v dedupe_long_running | awk '{print $1}' >"$TMP" || true
  local first="" n=0 p
  while read -r p; do
    [[ -z "$p" ]] && continue
    n=$((n + 1))
    if [[ -z "$first" ]]; then
      first=$p
      continue
    fi
    echo "kill_extra $pat pid=$p"
    kill "$p" 2>/dev/null || true
  done < "$TMP"
  if [[ -n "$first" ]]; then
    echo "keep $pat pid=$first (had=$n)"
  else
    echo "none $pat"
  fi
}

keep_n_newest() {
  local pat="$1"
  local maxn="$2"
  # etime descending ≈ newer first for short-lived; for long-running, keep lowest etimes (newest)
  ps -eo pid=,etime=,args= | grep -E "$pat" | grep -v grep | grep -v dedupe_long_running | sort -k2 >"$TMP" || true
  local n=0 p
  while read -r p etime rest; do
    [[ -z "$p" ]] && continue
    n=$((n + 1))
    if [[ "$n" -le "$maxn" ]]; then
      echo "keep $pat pid=$p etime=$etime"
      continue
    fi
    echo "kill_extra $pat pid=$p etime=$etime"
    kill "$p" 2>/dev/null || true
  done < "$TMP"
}

keep_one 'services/ws/ws_price_cache\.py'
keep_one 'engine_v2/signal_daemon\.php'
keep_n_newest 'engine_v2/exec_worker\.php' 4
keep_one 'run/trading_ops_daemon\.php'

sleep 1
rm -f "$TMP"
echo -n "ws="; ps -eo args= | grep 'ws_price_cache\.py' | grep -vc grep || true
echo -n "signal="; ps -eo args= | grep 'signal_daemon\.php' | grep -vc grep || true
echo -n "exec="; ps -eo args= | grep 'exec_worker\.php' | grep -vc grep || true
echo -n "trading="; ps -eo args= | grep 'trading_ops_daemon\.php' | grep -vc grep || true
echo "dedupe_long_running done"
