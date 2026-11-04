#!/usr/bin/env bash
# Package lifecycle test on a separate, plain instance (port 8091):
#   install previous version -> archive some emails, customize settings, schedule the job
#   -> upgrade to the current package -> uninstall -> reinstall.
# The previous version is the latest git tag (or PREV_REF); without one, the current code is
# packaged as version 0.0.0 so the upgrade path is still exercised.
# Usage: make lifecycle
set -euo pipefail
export LC_ALL=C

cd "$(dirname "$0")/../.."
PROJECT=espo-lifecycle
BASE=http://127.0.0.1:8091
DB=mariadb
. dev/lib.sh

COMPOSE="podman-compose --env-file .env -f dev/compose/lifecycle.yaml"
ADMIN=$(auth_header admin "$ADMIN_PASSWORD")
NAME="Email Archive"
VERSION=$(python3 -c "import json; print(json.load(open('extension/src/manifest.json'))['version'])")
CURRENT_ZIP=build/email-archive-$VERSION.zip
WORK=$(mktemp -d)
fail=0

cleanup() {
    rm -rf "$WORK"
    [ -n "${KEEP:-}" ] || $COMPOSE down -v >/dev/null 2>&1 || true
}
trap cleanup EXIT

step() { echo; echo "== $*"; }

check() {
    local label=$1 expected=$2 actual=$3

    if [ "$expected" = "$actual" ]; then
        echo "PASS $label ($actual)"
    else
        echo "FAIL $label (expected $expected, got $actual)"
        fail=1
    fi
}

install() {
    podman cp "$1" "$ESPO_CONTAINER:/tmp/package.zip"
    espo bin/command extension --file=/tmp/package.zip | tail -1
}

installed_version() {
    sql "SELECT version FROM extension WHERE name = '$NAME' AND is_installed = TRUE AND deleted = FALSE"
}

config() {
    espo php -r "\$c = include 'data/config.php'; echo json_encode(\$c['$1'] ?? null);"
}

# Number of "archivedEmails" entries in the effective layout.
panel_count() {
    curl -s -H "$ADMIN" "$API/$1/layout/$2" | python3 -c '
import sys, json
d = json.load(sys.stdin)
items = d.keys() if isinstance(d, dict) else [i if isinstance(i, str) else (i or {}).get("name") for i in (d or [])]
print(sum(1 for i in items if i == "archivedEmails"))'
}

# Index of the archive panel in the Account bottom panel layout.
panel_index() {
    curl -s -H "$ADMIN" "$API/Account/layout/bottomPanelsDetail" |
        python3 -c 'import sys, json; print(json.load(sys.stdin).get("archivedEmails", {}).get("index"))'
}

job_status() {
    sql "SELECT status FROM scheduled_job WHERE id = '$job_id'"
}

step "build previous package"
prev_ref=${PREV_REF:-$(git tag --sort=-v:refname --no-contains HEAD 2>/dev/null | head -1)}
mkdir -p "$WORK/prev"

if [ -n "$prev_ref" ]; then
    git archive "$prev_ref" extension/src | tar -x -C "$WORK/prev"
    echo "previous version: $prev_ref"
else
    mkdir -p "$WORK/prev/extension"
    cp -R extension/src "$WORK/prev/extension/src"
    python3 - "$WORK/prev/extension/src/manifest.json" <<'PY'
import json, sys
p = sys.argv[1]; d = json.load(open(p)); d["version"] = "0.0.0"; open(p, "w").write(json.dumps(d, indent=4))
PY
    echo "previous version: current code as 0.0.0 (no earlier tag)"
fi

(cd "$WORK/prev/extension/src" && zip -qr "$WORK/prev.zip" manifest.json files scripts -x '.*')
prev_version=$(unzip -p "$WORK/prev.zip" manifest.json | python3 -c 'import sys, json; print(json.load(sys.stdin)["version"])')

step "start plain instance"
$COMPOSE down -v >/dev/null 2>&1 || true
$COMPOSE up -d >/dev/null 2>&1
for _ in $(seq 100); do curl -sf -o /dev/null "$BASE/" && break; sleep 3; done
curl -sf -o /dev/null "$BASE/" || { echo "FAIL instance did not come up"; exit 1; }

step "install previous version ($prev_version)"
install "$WORK/prev.zip"
check "installed version" "$prev_version" "$(installed_version)"
check "Account panel added" 1 "$(panel_count Account bottomPanelsDetail)"
check "Contact panel added" 1 "$(panel_count Contact relationships)"
check "default mode" '"dryRun"' "$(config archiveEmailMode)"

step "use it"
espo bin/command dev-seed --scale=0.02 | tail -1
espo bin/command archive-emails --mode=copyOnly --limit=200 | grep mode:
archived=$(sql "SELECT COUNT(*) FROM archived_email")
check "archived before upgrade" 200 "$archived"
espo bin/command config:set archiveEmailOlderThanDays 999 --type=int >/dev/null
job_id=$(curl -s -H "$ADMIN" -H 'Content-Type: application/json' -X POST "$API/ScheduledJob" \
    -d '{"name":"Archive Emails","job":"ArchiveEmails","status":"Active","scheduling":"15 2 * * *"}' |
    python3 -c 'import sys, json; print(json.load(sys.stdin)["id"])')
check "scheduled job active" Active "$(job_status)"
# An admin moves the panel to the front.
curl -s -H "$ADMIN" "$API/Account/layout/bottomPanelsDetail" |
    python3 -c 'import sys, json; d = json.load(sys.stdin); d["archivedEmails"]["index"] = 0; print(json.dumps(d))' |
    curl -s -o /dev/null -H "$ADMIN" -H 'Content-Type: application/json' -X PUT "$API/Account/layout/bottomPanelsDetail" -d @-
check "panel moved by admin" 0 "$(panel_index)"

step "upgrade to $VERSION"
install "$CURRENT_ZIP"
check "installed version" "$VERSION" "$(installed_version)"
check "archive kept" "$archived" "$(sql "SELECT COUNT(*) FROM archived_email")"
check "custom setting kept" 999 "$(config archiveEmailOlderThanDays)"
check "scheduled job still active" Active "$(job_status)"
check "Account panel not duplicated" 1 "$(panel_count Account bottomPanelsDetail)"
check "panel position kept" 0 "$(panel_index)"
check "Contact panel not duplicated" 1 "$(panel_count Contact relationships)"
espo bin/command clear-cache >/dev/null
espo bin/command dev-archive-check | tail -1 | grep -q "All checks passed" &&
    check "integration check after upgrade" ok ok || check "integration check after upgrade" ok failed

step "uninstall"
espo bin/command extension -u --name="$NAME" | tail -1
check "not installed" "" "$(installed_version)"
check "module files removed" no "$(espo test -d custom/Espo/Modules/Archive && echo yes || echo no)"
check "app responds" 200 "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/")"
check "account API works" 200 "$(curl -s -o /dev/null -w '%{http_code}' -H "$ADMIN" "$API/Account?maxSize=1")"
check "archive API gone" 404 "$(curl -s -o /dev/null -w '%{http_code}' -H "$ADMIN" "$API/ArchivedEmail?maxSize=1")"
check "scheduled job deactivated" Inactive "$(job_status)"
check "Account panel removed" 0 "$(panel_count Account bottomPanelsDetail)"
check "Contact panel removed" 0 "$(panel_count Contact relationships)"
check "archived data kept" "$archived" "$(sql "SELECT COUNT(*) FROM archived_email")"
check "settings kept" 999 "$(config archiveEmailOlderThanDays)"
espo php cron.php >/dev/null 2>&1 || true
check "no errors after uninstall" 0 \
    "$(espo sh -c 'cat data/logs/*.log 2>/dev/null' | grep -c 'ERROR.*\(Archive\|archived\)' || true)"

step "reinstall $VERSION"
install "$CURRENT_ZIP"
check "installed version" "$VERSION" "$(installed_version)"
check "archive still there" "$archived" "$(curl -s -H "$ADMIN" "$API/ArchivedEmail?maxSize=1" |
    python3 -c 'import sys, json; print(json.load(sys.stdin)["total"])')"
check "custom setting not reset" 999 "$(config archiveEmailOlderThanDays)"
check "Account panel back once" 1 "$(panel_count Account bottomPanelsDetail)"
check "panel back at its position" 0 "$(panel_index)"
check "scheduled job reactivated" Active "$(job_status)"

echo
[ $fail = 0 ] && echo "lifecycle test passed" || echo "lifecycle test FAILED"
exit $fail
