#!/usr/bin/env python3
"""Refine B1 FN: cascade vs true miss (SUB_SELL_L{n} present, shadow silent)."""

import gzip
import re
import sys
from datetime import datetime
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SINCE = sys.argv[1] if len(sys.argv) > 1 else "2026-09-20 21:36:00"


def load_env(path):
    out = {}
    for line in path.read_text(errors="replace").splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        k, v = line.split("=", 1)
        out[k] = v.strip().strip("\"'")
    return out


def parse_ts(s):
    return datetime.strptime(s[:19], "%Y-%m-%d %H:%M:%S")


def iter_log_lines():
    arch_dir = ROOT / "logs" / "archive"
    arch = sorted(arch_dir.glob("thin_exec.*.log.gz")) if arch_dir.is_dir() else []
    for p in arch:
        with gzip.open(str(p), "rt") as f:
            for line in f:
                yield line
    cur = ROOT / "logs" / "thin_exec.log"
    if cur.is_file():
        with cur.open("r") as f:
            for line in f:
                yield line


def main():
    env = load_env(ROOT / ".env")
    try:
        import MySQLdb  # type: ignore
    except ImportError:
        import pymysql as MySQLdb  # type: ignore

    db = MySQLdb.connect(
        host=env.get("DB_HOST", "localhost"),
        user=env["DB_USER"],
        passwd=env.get("DB_PASS") or env.get("DB_PASSWORD", ""),
        db=env.get("DB_NAME") or env.get("DB_DATABASE", ""),
        charset="utf8mb4",
    )
    cur = db.cursor()
    cur.execute(
        """
        SELECT id, strategy_id, symbol, level_idx, created_at, meta
        FROM trade_ledger
        WHERE event_type='SELL_SUB' AND created_at>=%s
        ORDER BY id
        """,
        (SINCE,),
    )
    rows = cur.fetchall()

    would = set()
    would_events = []  # ts,sid,sp,sym,lvl
    cand = []  # ts,sid,signal,job
    skip_by_sp = {}

    re_ts = re.compile(r"^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]")
    re_would = re.compile(r"shadow WOULD_SELL sid=(\d+) (\S+) L(\d+) spId=(\d+)")
    re_skip = re.compile(
        r"shadow WOULD_SKIP sid=(\d+) (\S+) L(\d+) spId=(\d+) reason=(\S+)"
    )
    re_cand = re.compile(
        r"sid=(\d+) signal=(SUB_SELL_L\d+|SUB_SELL)\s+job=(\S+).*candidate=1"
    )

    for line in iter_log_lines():
        m = re_ts.match(line)
        if not m:
            continue
        ts_s = m.group(1)
        if ts_s < SINCE:
            continue
        ts = parse_ts(ts_s)
        mw = re_would.search(line)
        if mw:
            sid = int(mw.group(1))
            sym = mw.group(2)
            lvl = int(mw.group(3))
            sp = int(mw.group(4))
            would.add(sp)
            would_events.append((ts, sid, sp, sym, lvl))
            continue
        ms = re_skip.search(line)
        if ms:
            sp = int(ms.group(4))
            skip_by_sp.setdefault(sp, []).append(ms.group(5))
            continue
        if "candidate=1" in line and "SUB_SELL" in line:
            mc = re_cand.search(line)
            if mc:
                cand.append((ts, int(mc.group(1)), mc.group(2), mc.group(3)))

    def spid(meta):
        if not meta:
            return 0
        m = re.search(r'"sub_position_id"\s*:\s*(\d+)', meta)
        return int(m.group(1)) if m else 0

    cascade = 0
    true_miss = []
    no_signal = []
    matched = 0
    pending_fp = []

    led_by_sp = {}
    for r in rows:
        sp = spid(r[5])
        if sp:
            led_by_sp.setdefault(sp, []).append(r)

    first_would = {}
    for ts, sid, sp, sym, lvl in would_events:
        if sp not in first_would:
            first_would[sp] = ts

    for sp, first in first_would.items():
        sells = led_by_sp.get(sp, [])
        hit = False
        for r in sells:
            st = parse_ts(str(r[4]))
            if (st - first).total_seconds() >= -120:
                hit = True
                break
        if hit:
            matched += 1
        else:
            pending_fp.append((sp, first))

    for r in rows:
        led_id, sid, sym, lvl, created, meta = r
        sp = spid(meta)
        t = parse_ts(str(created))
        if sp in would:
            continue
        sig = "SUB_SELL_L%d" % lvl
        near_lvl = [
            c
            for c in cand
            if c[1] == sid
            and c[2] == sig
            and abs((c[0] - t).total_seconds()) <= 300
        ]
        near_any = [
            c
            for c in cand
            if c[1] == sid
            and c[2].startswith("SUB_SELL")
            and abs((c[0] - t).total_seconds()) <= 300
        ]
        if near_lvl:
            reasons = skip_by_sp.get(sp, [])
            true_miss.append(
                {
                    "led_id": led_id,
                    "sid": sid,
                    "sym": sym,
                    "lvl": lvl,
                    "sp": sp,
                    "at": str(created),
                    "skips": reasons[-5:],
                }
            )
        elif near_any:
            cascade += 1
        else:
            no_signal.append(
                {
                    "led_id": led_id,
                    "sid": sid,
                    "sym": sym,
                    "lvl": lvl,
                    "sp": sp,
                    "at": str(created),
                }
            )

    uniq_would = len(first_would)
    match_rate = round(100.0 * matched / uniq_would, 3) if uniq_would else 0.0
    print("=== Refined FN classification ===")
    print("since=%s" % SINCE)
    print("ledger_SELL_SUB=%d unique_WOULD_SELL_spId=%d" % (len(rows), uniq_would))
    print("matched_spId=%d/%d = %s%%" % (matched, uniq_would, match_rate))
    print("FP_pending(no ledger yet)=%d" % len(pending_fp))
    print(
        "FN_raw(no WOULD_SELL ever)=%d"
        % (cascade + len(true_miss) + len(no_signal))
    )
    print(
        "  cascade_or_other_SUB_SELL_near=%d  (not thin contract for that L)"
        % cascade
    )
    print(
        "  true_miss(matching SUB_SELL_Ln near, shadow silent)=%d" % len(true_miss)
    )
    print(
        "  no_SUB_SELL_candidate_near=%d  (sold via reconcile/full path)"
        % len(no_signal)
    )
    print("")
    print("--- TRUE MISS (critical) ---")
    for x in true_miss[:40]:
        print(
            "led=%s sid=%s %s L%s sp=%s at=%s skips=%s"
            % (x["led_id"], x["sid"], x["sym"], x["lvl"], x["sp"], x["at"], x["skips"])
        )
    print("")
    print("--- no_signal sample (max 15) ---")
    for x in no_signal[:15]:
        print(
            "led=%s sid=%s %s L%s sp=%s at=%s"
            % (x["led_id"], x["sid"], x["sym"], x["lvl"], x["sp"], x["at"])
        )
    print("")
    print("--- FP pending ---")
    for sp, first in pending_fp[:10]:
        last = [e for e in would_events if e[2] == sp][-1]
        print(
            "sp=%s sid=%s %s L%s first=%s last=%s"
            % (sp, last[1], last[3], last[4], first, last[0])
        )

    critical_fn = len(true_miss)
    if match_rate >= 99.5 and critical_fn == 0 and len(pending_fp) == 0:
        gate = "GO_TECH"
    elif match_rate >= 99.0 and critical_fn == 0 and len(pending_fp) <= 2:
        gate = "GO_WITH_NOTES"
    else:
        gate = "HOLD"
    print("")
    print(
        "REFINED_VERDICT=%s critical_FN=%d match=%s%% FP=%d"
        % (gate, critical_fn, match_rate, len(pending_fp))
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
