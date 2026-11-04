#!/usr/bin/env bash
# Checks archive access through the API with a restricted user (role "Archive Reader (test)":
# archive read, own accounts/cases/contacts only; created on first run). Expects archived emails
# with attachments to exist (make seed, then archive-emails --mode=copyOnly or move).
set -euo pipefail
export LC_ALL=C

cd "$(dirname "$0")/../.."
set -a; . ./.env; set +a

BASE=http://127.0.0.1:${ESPO_PORT}
API=$BASE/api/v1
auth() { printf 'Espo-Authorization: %s' "$(printf '%s:%s' "$1" "$2" | base64 | tr -d '\n')"; }
ADMIN=$(auth admin "$ADMIN_PASSWORD")
USER_AUTH=$(auth archtest "$TEST_USER_PASSWORD")
fail=0

sql() {
    podman exec espo-archive_db_1 mariadb -uespocrm -p"$DB_PASSWORD" espocrm -N -e "$1"
}

check() {
    local label=$1 expected=$2 actual=$3

    if [ "$expected" = "$actual" ]; then
        echo "PASS $label ($actual)"
    else
        echo "FAIL $label (expected $expected, got $actual)"
        fail=1
    fi
}

code() {
    curl -s -o /dev/null -w '%{http_code}' "$@"
}

user_id=$(sql "SELECT id FROM user WHERE user_name = 'archtest' AND deleted = 0")

if [ -z "$user_id" ]; then
    # Role: archive read, only own accounts/cases/contacts. User owns a few accounts with archived emails.
    own='{"create":"no","read":"own","edit":"no","delete":"no","stream":"no"}'
    role_id=$(curl -s -H "$ADMIN" -H 'Content-Type: application/json' -X POST "$API/Role" \
        -d "{\"name\":\"Archive Reader (test)\",\"data\":{\"ArchivedEmail\":{\"read\":\"all\"},\"Account\":$own,\"Case\":$own,\"Contact\":$own,\"Lead\":false,\"Opportunity\":false}}" |
        python3 -c 'import sys,json; print(json.load(sys.stdin)["id"])')
    user_id=$(curl -s -H "$ADMIN" -H 'Content-Type: application/json' -X POST "$API/User" \
        -d "{\"userName\":\"archtest\",\"firstName\":\"Archive\",\"lastName\":\"Tester\",\"type\":\"regular\",\"password\":\"$TEST_USER_PASSWORD\",\"passwordConfirm\":\"$TEST_USER_PASSWORD\",\"rolesIds\":[\"$role_id\"]}" |
        python3 -c 'import sys,json; print(json.load(sys.stdin)["id"])')
    sql "UPDATE account SET assigned_user_id = '$user_id' WHERE id IN (SELECT account_id FROM (
             SELECT a.account_id FROM archived_email a JOIN attachment t ON t.parent_id = a.id AND t.parent_type = 'ArchivedEmail'
             WHERE a.account_id IS NOT NULL GROUP BY a.account_id ORDER BY a.account_id LIMIT 3) x)"
    echo "created role, user archtest and assigned 3 accounts"
fi

visible_sql="SELECT a.id FROM archived_email a WHERE a.deleted = 0 AND (
    a.account_id IN (SELECT id FROM account WHERE assigned_user_id = '$user_id' AND deleted = 0)
    OR (a.parent_type = 'Case' AND a.parent_id IN (SELECT id FROM \`case\` WHERE assigned_user_id = '$user_id' AND deleted = 0))
    OR (a.parent_type = 'Contact' AND a.parent_id IN (SELECT id FROM contact WHERE assigned_user_id = '$user_id' AND deleted = 0)))"

expected=$(sql "SELECT COUNT(*) FROM ($visible_sql) t")
total=$(sql "SELECT COUNT(*) FROM archived_email WHERE deleted = 0")
visible_id=$(sql "$visible_sql LIMIT 1")
hidden_id=$(sql "SELECT id FROM archived_email WHERE deleted = 0 AND id NOT IN ($visible_sql) LIMIT 1")
visible_att=$(sql "SELECT id FROM attachment WHERE deleted = 0 AND parent_type = 'ArchivedEmail' AND parent_id IN ($visible_sql) LIMIT 1")
hidden_att=$(sql "SELECT id FROM attachment WHERE deleted = 0 AND parent_type = 'ArchivedEmail' AND parent_id NOT IN ($visible_sql) LIMIT 1")

echo "archive: $total records, $expected readable by archtest"

check "user list total" "$expected" \
    "$(curl -s -H "$USER_AUTH" "$API/ArchivedEmail?maxSize=1" | python3 -c 'import sys,json; print(json.load(sys.stdin)["total"])')"
check "user list ids match" "$(sql "$visible_sql ORDER BY id" | tr '\n' ' ')" \
    "$(curl -s -H "$USER_AUTH" "$API/ArchivedEmail?maxSize=200&orderBy=id&order=asc&select=id" |
        python3 -c 'import sys,json; print(" ".join(i["id"] for i in json.load(sys.stdin)["list"]) + " ")')"
check "user text search stays filtered" "0" \
    "$(curl -s -H "$USER_AUTH" "$API/ArchivedEmail?maxSize=200&textFilter=renewal&select=id" |
        python3 -c 'import sys,json; print(len([i for i in json.load(sys.stdin)["list"] if i["id"] not in sys.argv[1].split()]))' "$(sql "$visible_sql")")"
check "user detail readable" 200 "$(code -H "$USER_AUTH" "$API/ArchivedEmail/$visible_id")"
check "user detail hidden" 403 "$(code -H "$USER_AUTH" "$API/ArchivedEmail/$hidden_id")"

if [ -n "$visible_att" ]; then
    check "user attachment readable" 200 "$(code -H "$USER_AUTH" "$BASE/?entryPoint=download&id=$visible_att")"
fi

check "user attachment hidden" 403 "$(code -H "$USER_AUTH" "$BASE/?entryPoint=download&id=$hidden_att")"
check "user cannot create" 403 "$(code -H "$USER_AUTH" -H 'Content-Type: application/json' -X POST "$API/ArchivedEmail" -d '{"name":"x"}')"
check "user cannot edit" 403 "$(code -H "$USER_AUTH" -H 'Content-Type: application/json' -X PUT "$API/ArchivedEmail/$visible_id" -d '{"name":"x"}')"
check "user cannot delete" 403 "$(code -H "$USER_AUTH" -X DELETE "$API/ArchivedEmail/$visible_id")"
check "admin sees all" "$total" \
    "$(curl -s -H "$ADMIN" "$API/ArchivedEmail?maxSize=1" | python3 -c 'import sys,json; print(json.load(sys.stdin)["total"])')"
check "admin cannot create" 403 "$(code -H "$ADMIN" -H 'Content-Type: application/json' -X POST "$API/ArchivedEmail" -d '{"name":"x"}')"
check "admin cannot edit" 403 "$(code -H "$ADMIN" -H 'Content-Type: application/json' -X PUT "$API/ArchivedEmail/$visible_id" -d '{"name":"x"}')"

exit $fail
