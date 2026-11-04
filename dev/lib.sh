# Shared helpers for the dev scripts. Source after cd-ing into the repository root.
#   DB       mariadb (default) | mysql | postgres
#   PROJECT  compose project name (default espo-archive)

set -a; . ./.env; set +a

DB=${DB:-mariadb}
PROJECT=${PROJECT:-espo-archive}
ESPO_CONTAINER=${PROJECT}_espocrm_1
DB_CONTAINER=${PROJECT}_db_1
BASE=${BASE:-http://127.0.0.1:${ESPO_PORT}}
API=$BASE/api/v1

# Identifier quote; needed for reserved table names like "case" and "user".
if [ "$DB" = postgres ]; then Q='"'; else Q='`'; fi

# Runs SQL, prints tab-separated rows without header.
sql() {
    case "$DB" in
        postgres)
            podman exec -e PGPASSWORD="$DB_PASSWORD" "$DB_CONTAINER" \
                psql -q -U espocrm -d espocrm -tA -F $'\t' -v ON_ERROR_STOP=1 -c "$1" ;;
        mysql)
            podman exec -e MYSQL_PWD="$DB_PASSWORD" "$DB_CONTAINER" mysql -uespocrm espocrm -N -e "$1" ;;
        *)
            podman exec -e MYSQL_PWD="$DB_PASSWORD" "$DB_CONTAINER" mariadb -uespocrm espocrm -N -e "$1" ;;
    esac
}

espo() {
    podman exec -u www-data -w /var/www/html "$ESPO_CONTAINER" "$@"
}

auth_header() {
    printf 'Espo-Authorization: %s' "$(printf '%s:%s' "$1" "$2" | base64 | tr -d '\n')"
}
