#!/usr/bin/env python3
"""Classify ledger SELL_SUB vs shadow observe: WOULD / skip-race / silent."""
import gzip
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SINCE = "2026-09-20 21:36:00"


def load_env(path):
    out = {}
    for line in path.read_text().splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        k, v = line.split("=", 1)
        out[k] = v.strip().strip("\"'")
    return out


def iter_lines():
    arch = ROOT / "logs" / "archive"
    for p in sorted(arch.glob("thin_exec.*.log.gz")):
        with gzip.open(str(p), "rt") as f:
            for line in f:
                yield line
    with (ROOT / "logs" / "thin_exec.log").open() as f:
        for line in f:
            yield line


def main():
    obs = {}
    for line in iter_lines():
        m = re.match(r"^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]", line)
        if not m or m.group(1) < SINCE:
            continue
        if "shadow WOULD_SELL" in line:
            mm = re.search(r"spId=(\d+)", line)
            if mm:
                obs.setdefault(int(mm.group(1)), set()).add("WOULD_SELL")
        elif "shadow WOULD_SKIP" in line and "spId=" in line:
            mm = re.search(r"spId=(\d+) reason=(\S+)", line)
            if mm:
                obs.setdefault(int(mm.group(1)), set()).add(mm.group(2))

    env = load_env(ROOT / ".env")
    try:
        import MySQLdb
    except ImportError:
        import pymysql as MySQLdb

    db = MySQLdb.connect(
        host=env.get("DB_HOST", "localhost"),
        user=env["DB_USER"],
        passwd=env["DB_PASS"],
        db=env["DB_NAME"],
        charset="utf8mb4",
    )
    cur = db.cursor()
    cur.execute(
        "SELECT id,strategy_id,symbol,level_idx,created_at,meta FROM trade_ledger "
        "WHERE event_type=%s AND created_at>=%s",
        ("SELL_SUB", SINCE),
    )
    rows = cur.fetchall()

    def spid(meta):
        m = re.search(r'"sub_position_id"\s*:\s*(\d+)', meta or "")
        return int(m.group(1)) if m else 0

    sold_would = sold_skip = sold_silent = 0
    silent_rows = []
    for r in rows:
        sp = spid(r[5])
        kinds = obs.get(sp, set())
        if "WOULD_SELL" in kinds:
            sold_would += 1
        elif kinds:
            sold_skip += 1
        else:
            sold_silent += 1
            silent_rows.append((r, sp, kinds))

    would_sps = set(sp for sp, k in obs.items() if "WOULD_SELL" in k)
    led_sps = set(spid(r[5]) for r in rows)
    matched = len(would_sps & led_sps)
    pending = sorted(would_sps - led_sps)

    print("=== Observe coverage ===")
    print("since=%s SELL_SUB=%d" % (SINCE, len(rows)))
    print("sold_with_WOULD_SELL=%d" % sold_would)
    print("sold_with_only_SKIP(race)=%d" % sold_skip)
    print("sold_SILENT=%d" % sold_silent)
    print(
        "coverage_observed=%.2f%%"
        % ((sold_would + sold_skip) / max(1, len(rows)) * 100.0)
    )
    print(
        "WOULD_SELL unique=%d matched=%d pending_FP=%s"
        % (len(would_sps), matched, pending)
    )
    print("match_rate WOULD->ledger=%.3f%%" % (100.0 * matched / max(1, len(would_sps))))
    print("--- silent ---")
    for r, sp, _ in silent_rows[:25]:
        print(
            "led=%s sid=%s %s L%s sp=%s at=%s"
            % (r[0], r[1], r[2], r[3], sp, r[4])
        )

    # Verdict
    silent_ok = sold_silent == 0
    match_ok = (100.0 * matched / max(1, len(would_sps))) >= 99.0
    if silent_ok and match_ok and len(pending) <= 1:
        verdict = "GO_WITH_NOTES"
    elif silent_ok and matched >= 0:
        verdict = "HOLD_REVIEW_SILENT_OR_FP"
    else:
        verdict = "HOLD"
    if sold_silent > 0:
        verdict = "HOLD_SILENT_MISS"
    print("VERDICT=%s" % verdict)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
