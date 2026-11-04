#!/usr/bin/env bash
# Functional test on a fresh instance of the dev stack (extension bind-mounted):
# seed, setup, dry run, copy only, move, then the integration and API access checks.
# Usage: DB=mariadb|mysql|postgres dev/ci/functional.sh   (after `make reset DB=...`)
set -euo pipefail
export LC_ALL=C

cd "$(dirname "$0")/../.."
. dev/lib.sh

SCALE=${SCALE:-0.05}

step() { echo; echo "== $*"; }
die() { echo "FAIL $*"; exit 1; }

# Prints the value of "<key>: <number>" from an archive-emails report line.
field() { grep -oE "$1: [0-9]+" | head -1 | awk '{ print $2 }'; }

step "wait for EspoCRM ($DB)"
for _ in $(seq 100); do
    curl -sf -o /dev/null "$BASE/" && break
    sleep 3
done
curl -sf -o /dev/null "$BASE/" || die "EspoCRM did not come up"
espo bin/command rebuild >/dev/null

step "seed (scale $SCALE)"
espo bin/command dev-seed --scale="$SCALE" | tail -1

step "setup"
espo bin/command archive-emails --setup

step "dry run"
report=$(espo bin/command archive-emails | head -1)
echo "$report"
candidates=$(field candidates <<<"$report")
[ "$candidates" -gt 0 ] || die "no candidates found"
[ "$(sql "SELECT COUNT(*) FROM archived_email")" = 0 ] || die "dry run wrote archive records"

step "copy only"
report=$(espo bin/command archive-emails --mode=copyOnly --limit=300 | grep mode:)
echo "$report"
[ "$(field copied <<<"$report")" = 300 ] || die "expected 300 copies"
[ "$(field failed <<<"$report")" = 0 ] || die "copy failures"
[ "$(espo bin/command archive-emails | head -1 | field candidates)" = "$candidates" ] ||
    die "copy only removed originals"

step "move"
report=$(espo bin/command archive-emails --mode=move --limit=100000 --batch-size=250 | grep mode:)
echo "$report"
[ "$(field failed <<<"$report")" = 0 ] || die "move failures"
[ "$(field moved <<<"$report")" = "$candidates" ] || die "expected $candidates moved"
[ "$(espo bin/command archive-emails | head -1 | field candidates)" = 0 ] || die "candidates left after move"
[ "$(sql "SELECT COUNT(*) FROM archived_email")" = "$candidates" ] || die "archive count differs"

step "integration check"
espo bin/command clear-cache >/dev/null
espo bin/command dev-archive-check

step "API access check"
dev/measure/acl-check.sh

step "archive search works"
hits=$(curl -s -H "$(auth_header admin "$ADMIN_PASSWORD")" "$API/ArchivedEmail?maxSize=1&textFilter=renewal" |
    python3 -c 'import sys, json; print(json.load(sys.stdin)["total"])')
echo "text search hits: $hits"
[ "$hits" -gt 0 ] || die "full-text search found nothing"

echo
echo "functional test passed ($DB)"
