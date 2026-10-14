#!/usr/bin/env bash
# SEO meta audit for public StrIM pages.
# Usage: bash run/seo_audit.sh [BASE_URL]
set -euo pipefail
BASE="${1:-https://strim.in.ua}"
pages=(
  "/"
  "/about.html"
  "/tariffs.html"
  "/instruction.html"
  "/faq.html"
  "/offer.html"
  "/privacy.html"
  "/returns.html"
  "/risks.html"
  "/aml.html"
  "/auth/login.php"
  "/auth/register.php"
)

echo "=== SEO audit BASE_URL=$BASE ==="
python3 - "$BASE" "${pages[@]}" <<'PY'
import re, sys, html, subprocess
base = sys.argv[1]
pages = sys.argv[2:]
issues = []
titles, descs = {}, {}
for p in pages:
    url = base.rstrip('/') + p
    raw = subprocess.check_output(["curl", "-sL", url]).decode("utf-8", "replace")
    title_m = re.search(r"<title[^>]*>(.*?)</title>", raw, re.I|re.S)
    desc_m = re.search(r'<meta name="description" content="([^"]*)"', raw, re.I)
    canon_m = re.search(r'<link rel="canonical" href="([^"]*)"', raw, re.I)
    robots_m = re.search(r'<meta name="robots" content="([^"]*)"', raw, re.I)
    h1s = re.findall(r"<h1[^>]*>(.*?)</h1>", raw, re.I|re.S)
    title = html.unescape(title_m.group(1).strip()) if title_m else None
    desc = desc_m.group(1) if desc_m else None
    canon = canon_m.group(1) if canon_m else None
    if not title:
        issues.append(f"MISSING title: {p}")
    if not desc:
        issues.append(f"MISSING description: {p}")
    if not canon and p not in ("/auth/forgot_password.php", "/auth/reset_password.php"):
        issues.append(f"MISSING canonical: {p}")
    if title:
        if title in titles:
            issues.append(f"DUP title '{title}': {titles[title]} and {p}")
        titles[title] = p
        if len(title) < 20 or len(title) > 60:
            issues.append(f"title length {len(title)}: {p}")
    if desc:
        if desc in descs:
            issues.append(f"DUP description: {descs[desc]} and {p}")
        descs[desc] = p
        if len(desc) < 70:
            issues.append(f"short description ({len(desc)}): {p}")
        if len(desc) > 160:
            issues.append(f"long description ({len(desc)}): {p}")
    if len(h1s) == 0:
        issues.append(f"MISSING h1: {p}")
    if len(h1s) > 1:
        issues.append(f"MULTIPLE h1 ({len(h1s)}): {p}")
    exp_canon = base.rstrip('/') + (p if p != '/' else '/')
    if canon and canon.rstrip('/') != exp_canon.rstrip('/'):
        issues.append(f"canonical mismatch {p}: {canon} != {exp_canon}")
    print(f"{p}\n  title({len(title or '')}): {title}\n  desc({len(desc or '')}): {(desc or '')[:90]}{'...' if desc and len(desc)>90 else ''}\n  canon: {canon or '-'}\n  robots: {robots_m.group(1) if robots_m else '-'}\n  h1: {len(h1s)}\n")

print("--- HSTS ---")
for probe in [base, base.replace('https://', 'http://')]:
    hdr = subprocess.check_output(["curl", "-sI", probe]).decode("utf-8", "replace")
    hsts = [ln.strip() for ln in hdr.splitlines() if ln.lower().startswith('strict-transport-security:')]
    print(f"{probe}: {hsts[0] if hsts else 'NO HSTS'}")

if issues:
    print("\nISSUES:")
    for i in issues:
        print(" -", i)
    sys.exit(1)
print("\nOK: no SEO issues in checked pages")
PY
