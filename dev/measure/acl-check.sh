#!/usr/bin/env bash
# Checks archive access through the API with a restricted user (role "Archive Reader (test)":
# archive read, own accounts/cases/contacts only; created on first run). Expects archived emails
# with attachments to exist (make seed, then archive-emails --mode=copyOnly or move).
set -euo pipefail
export LC_ALL=C

cd "$(dirname "$0")/../.."
. dev/lib.sh

ADMIN=$(auth_header admin "$ADMIN_PASSWORD")
USER_AUTH=$(auth_header archtest "$TEST_USER_PASSWORD")
fail=0

check() {
    local label=$1 expected=$2 actual=$3

    if [ "$expected" = "$actual" ]; then
        echo "PASS $label (${actual:0:60})"
    else
        echo "FAIL $label (expected ${expected:0:200}, got ${actual:0:200})"
        fail=1
    fi
}

code() {
    curl -s -o /dev/null -w '%{http_code}' "$@"
}

json() {
    python3 -c "import sys, json; d = json.load(sys.stdin); print($1)"
}

user_id=$(sql "SELECT id FROM ${Q}user${Q} WHERE user_name = 'archtest' AND deleted = FALSE")

if [ -z "$user_id" ]; then
    # Role: archive read, only own accounts/cases/contacts. User owns a few accounts with archived emails.
    own='{"create":"no","read":"own","edit":"no","delete":"no","stream":"no"}'
    role_id=$(curl -s -H "$ADMIN" -H 'Content-Type: application/json' -X POST "$API/Role" \
        -d "{\"name\":\"Archive Reader (test)\",\"data\":{\"ArchivedEmail\":{\"read\":\"all\"},\"Account\":$own,\"Case\":$own,\"Contact\":$own,\"Lead\":false,\"Opportunity\":false}}" |
        json 'd["id"]')
    user_id=$(curl -s -H "$ADMIN" -H 'Content-Type: application/json' -X POST "$API/User" \
        -d "{\"userName\":\"archtest\",\"firstName\":\"Archive\",\"lastName\":\"Tester\",\"type\":\"regular\",\"password\":\"$TEST_USER_PASSWORD\",\"passwordConfirm\":\"$TEST_USER_PASSWORD\",\"rolesIds\":[\"$role_id\"]}" |
        json 'd["id"]')
    sql "UPDATE account SET assigned_user_id = '$user_id' WHERE id IN (SELECT account_id FROM (
             SELECT a.account_id FROM archived_email a JOIN attachment t ON t.parent_id = a.id AND t.parent_type = 'ArchivedEmail'
             WHERE a.account_id IS NOT NULL GROUP BY a.account_id ORDER BY a.account_id LIMIT 3) x)" >/dev/null
    echo "created role, user archtest and assigned 3 accounts"
fi

visible_sql="SELECT a.id FROM archived_email a WHERE a.deleted = FALSE AND (
    a.account_id IN (SELECT id FROM account WHERE assigned_user_id = '$user_id' AND deleted = FALSE)
    OR (a.parent_type = 'Case' AND a.parent_id IN (SELECT id FROM ${Q}case${Q} WHERE assigned_user_id = '$user_id' AND deleted = FALSE))
    OR (a.parent_type = 'Contact' AND a.parent_id IN (SELECT id FROM contact WHERE assigned_user_id = '$user_id' AND deleted = FALSE)))"

expected=$(sql "SELECT COUNT(*) FROM ($visible_sql) t")
total=$(sql "SELECT COUNT(*) FROM archived_email WHERE deleted = FALSE")
visible_ids=$(sql "$visible_sql" | sort | tr '\n' ' ')
visible_id=$(sql "$visible_sql LIMIT 1")
hidden_id=$(sql "SELECT id FROM archived_email WHERE deleted = FALSE AND id NOT IN ($visible_sql) LIMIT 1")
visible_att=$(sql "SELECT id FROM attachment WHERE deleted = FALSE AND parent_type = 'ArchivedEmail' AND parent_id IN ($visible_sql) LIMIT 1")
hidden_att=$(sql "SELECT id FROM attachment WHERE deleted = FALSE AND parent_type = 'ArchivedEmail' AND parent_id NOT IN ($visible_sql) LIMIT 1")

echo "archive: $total records, $expected readable by archtest"

check "user list total" "$expected" "$(curl -s -H "$USER_AUTH" "$API/ArchivedEmail?maxSize=1" | json 'd["total"]')"
check "user list ids match" "$visible_ids" \
    "$(curl -s -H "$USER_AUTH" "$API/ArchivedEmail?maxSize=200&select=id" | json '" ".join(sorted(i["id"] for i in d["list"])) + " "')"
check "user text search stays filtered" "0" \
    "$(curl -s -H "$USER_AUTH" "$API/ArchivedEmail?maxSize=200&textFilter=renewal&select=id" |
        VISIBLE="$visible_ids" json 'len([i for i in d["list"] if i["id"] not in __import__("os").environ["VISIBLE"].split()])')"
check "user detail readable" 200 "$(code -H "$USER_AUTH" "$API/ArchivedEmail/$visible_id")"
check "user detail hidden" 403 "$(code -H "$USER_AUTH" "$API/ArchivedEmail/$hidden_id")"

if [ -n "$visible_att" ]; then
    check "user attachment readable" 200 "$(code -H "$USER_AUTH" "$BASE/?entryPoint=download&id=$visible_att")"
fi

check "user attachment hidden" 403 "$(code -H "$USER_AUTH" "$BASE/?entryPoint=download&id=$hidden_att")"
check "user cannot create" 403 "$(code -H "$USER_AUTH" -H 'Content-Type: application/json' -X POST "$API/ArchivedEmail" -d '{"name":"x"}')"
check "user cannot edit" 403 "$(code -H "$USER_AUTH" -H 'Content-Type: application/json' -X PUT "$API/ArchivedEmail/$visible_id" -d '{"name":"x"}')"
check "user cannot delete" 403 "$(code -H "$USER_AUTH" -X DELETE "$API/ArchivedEmail/$visible_id")"
check "admin sees all" "$total" "$(curl -s -H "$ADMIN" "$API/ArchivedEmail?maxSize=1" | json 'd["total"]')"
check "admin cannot create" 403 "$(code -H "$ADMIN" -H 'Content-Type: application/json' -X POST "$API/ArchivedEmail" -d '{"name":"x"}')"
check "admin cannot edit" 403 "$(code -H "$ADMIN" -H 'Content-Type: application/json' -X PUT "$API/ArchivedEmail/$visible_id" -d '{"name":"x"}')"

exit $fail
