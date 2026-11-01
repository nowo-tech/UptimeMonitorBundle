#!/usr/bin/env bash
# REQ-SPECKIT-001 — fail when specs/001-baseline/code-inventory.md does not map 100% of the files under src/.
#
# Checks:
#   1. every file under src/ has a row (its path relative to src/, in backticks);
#   2. every inventory path still exists under src/ (no stale rows);
#   3. every number of the "Total" summary row and the "Expected: N" audit line equal the src/ file count.
#
# INVENTORY_EXCLUDE: optional extended regex of src/ paths that are out of the inventory scope (e.g. co-located tests).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

INVENTORY="${INVENTORY:-specs/001-baseline/code-inventory.md}"
if [[ ! -f "$INVENTORY" ]]; then
  echo "ERROR: $INVENTORY not found (REQ-SPECKIT-001)" >&2
  exit 1
fi

EXCLUDE="${INVENTORY_EXCLUDE:-}"
list_src() {
  if [[ -n "$EXCLUDE" ]]; then
    find src -type f | grep -Ev "$EXCLUDE" | LC_ALL=C sort
  else
    find src -type f | LC_ALL=C sort
  fi
}

errors=0
actual="$(list_src | wc -l | tr -d ' ')"

# 1. Every src/ file has a row.
while IFS= read -r file; do
  rel="${file#src/}"
  if ! grep -qF "\`$rel\`" "$INVENTORY" && ! grep -qF "\`src/$rel\`" "$INVENTORY"; then
    echo "ERROR: src/$rel has no row in $INVENTORY" >&2
    errors=$((errors + 1))
  fi
done < <(list_src)

# 2. No stale rows: backticked paths with a file extension that point at nothing under src/.
while IFS= read -r path; do
  rel="${path#src/}"
  if [[ ! -f "src/$rel" ]]; then
    echo "ERROR: $INVENTORY lists \`$path\`, which is not under src/" >&2
    errors=$((errors + 1))
  fi
done < <(grep -E '^\| `[^`]+\.[A-Za-z0-9]+`' "$INVENTORY" | sed -E 's/^\| `([^`]+)`.*/\1/' | LC_ALL=C sort -u)

# 3. Totals.
total_row="$(grep -E '^\|.*Total' "$INVENTORY" | head -n 1 || true)"
if [[ -z "$total_row" ]]; then
  echo "ERROR: no 'Total' summary row in $INVENTORY" >&2
  errors=$((errors + 1))
else
  while IFS= read -r n; do
    if [[ "$n" != "$actual" ]]; then
      echo "ERROR: $INVENTORY Total row says $n, src/ has $actual files in scope" >&2
      errors=$((errors + 1))
    fi
  done < <(echo "$total_row" | grep -oE '[0-9]+')
fi

expected="$(grep -oE 'Expected: [0-9]+' "$INVENTORY" | head -n 1 | grep -oE '[0-9]+' || true)"
if [[ -n "$expected" && "$expected" != "$actual" ]]; then
  echo "ERROR: $INVENTORY audit command expects $expected, src/ has $actual files" >&2
  errors=$((errors + 1))
fi

if [[ $errors -gt 0 ]]; then
  echo "check-spec-inventory: $errors problem(s) — update $INVENTORY (REQ-SPECKIT-001)" >&2
  exit 1
fi

echo "check-spec-inventory: OK ($actual/$actual files under src/ mapped)"
