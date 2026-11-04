#!/usr/bin/env bash
# Measures table sizes and response times of the typical "slow" EspoCRM views.
# Run before and after archiving to compare. Output is plain text, easy to diff.
set -euo pipefail
export LC_ALL=C

cd "$(dirname "$0")/../.."
set -a; . ./.env; set +a

RUNS=${RUNS:-5}
BASE=http://127.0.0.1:${ESPO_PORT}
API=$BASE/api/v1
AUTH="Espo-Authorization: $(printf 'admin:%s' "$ADMIN_PASSWORD" | base64 | tr -d '\n')"

sql() {
    podman exec espo-archive_db_1 mariadb -uespocrm -p"$DB_PASSWORD" espocrm -N -e "$1"
}

echo "== Table sizes (top 12, exact row counts)"
printf "%-28s %12s %10s\n" table rows MB
sql "SELECT table_name, ROUND((data_length + index_length) / 1024 / 1024, 1)
     FROM information_schema.tables WHERE table_schema = 'espocrm'
     ORDER BY data_length + index_length DESC LIMIT 12" |
    while read -r table mb; do
        printf "%-28s %12s %10s\n" "$table" "$(sql "SELECT COUNT(*) FROM \`$table\`")" "$mb"
    done

echo
echo "== Upload directory"
podman exec espo-archive_espocrm_1 du -sh /var/www/html/data/upload | awk '{ print $1 }'

account=$(sql "SELECT super_parent_id FROM note WHERE super_parent_id IS NOT NULL
               GROUP BY super_parent_id ORDER BY COUNT(*) DESC LIMIT 1")
case_id=$(sql "SELECT parent_id FROM note WHERE parent_type = 'Case'
               GROUP BY parent_id ORDER BY COUNT(*) DESC LIMIT 1")
word=renewal

# Median of $RUNS calls, in milliseconds.
timed() {
    local label=$1 url=$2
    local times=()

    for _ in $(seq "$RUNS"); do
        times+=("$(curl -sg -o /dev/null -w '%{time_total}' -H "$AUTH" "$url")")
    done

    printf '%s\n' "${times[@]}" | sort -n |
        awk -v label="$label" '{ a[NR] = $1 } END { printf "%-40s %8.0f ms\n", label, a[int((NR + 1) / 2)] * 1000 }'
}

echo
echo "== Response times (median of $RUNS)"
timed "Email list (inbox order)" "$API/Email?maxSize=20&orderBy=dateSent&order=desc"
timed "Email text search" "$API/Email?maxSize=20&textFilter=$word"
timed "Account stream (busiest)" "$API/Account/$account/stream?maxSize=20"
timed "Account history panel" "$API/Activities/Account/$account/history?maxSize=20"
timed "Account emails panel" "$API/Account/$account/emails?maxSize=20"
timed "Case stream (busiest)" "$API/Case/$case_id/stream?maxSize=20"
timed "Case list" "$API/Case?maxSize=20&orderBy=createdAt&order=desc"
timed "Global search" "$API/GlobalSearch?q=$word&maxSize=10"

if [ "$(sql "SHOW TABLES LIKE 'archived_email'")" ]; then
    timed "Archive list" "$API/ArchivedEmail?maxSize=20"
    timed "Archive text search" "$API/ArchivedEmail?maxSize=20&textFilter=$word"
    timed "Account archive panel" "$API/Account/$account/archivedEmails?maxSize=5"
fi
