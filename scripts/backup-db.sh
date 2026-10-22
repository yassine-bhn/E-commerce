#!/usr/bin/env bash
# Database + uploads backup (R18, D5).
#
#   ./scripts/backup-db.sh                                  # dev stack (compose.yaml)
#   COMPOSE_FILE=compose.prod.yaml ENV_FILE=.env.prod ./scripts/backup-db.sh
#
# Writes backups/db-<UTC>.dump (pg_dump custom format) and backups/uploads-<UTC>.tar,
# then prunes files older than BACKUP_RETENTION_DAYS (default 7).
set -euo pipefail
umask 077

root=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
cd "$root"

compose_file=${COMPOSE_FILE:-compose.yaml}
env_file=${ENV_FILE:-}
retention=${BACKUP_RETENTION_DAYS:-7}

die() {
	printf 'backup: %s\n' "$*" >&2
	exit 1
}

compose=(docker compose -f "$compose_file")
if [ -n "$env_file" ]; then
	compose+=(--env-file "$env_file")
fi

running=$("${compose[@]}" ps --status running --services 2>/dev/null || true)
printf '%s\n' "$running" | grep -qx database || die "the database service is not running"
printf '%s\n' "$running" | grep -qx app || die "the app service is not running (needed to archive the uploads volume)"

mkdir -p backups
timestamp=$(date -u +%Y%m%dT%H%M%SZ)

db_user=$("${compose[@]}" exec -T database sh -lc 'printf %s "$POSTGRES_USER"')
db_name=$("${compose[@]}" exec -T database sh -lc 'printf %s "$POSTGRES_DB"')
[ -n "$db_user" ] && [ -n "$db_name" ] || die "could not read POSTGRES_USER/POSTGRES_DB from the database container"

dump="backups/db-${timestamp}.dump"
"${compose[@]}" exec -T database pg_dump -U "$db_user" -d "$db_name" -Fc >"$dump"

uploads="backups/uploads-${timestamp}.tar"
"${compose[@]}" exec -T app tar -C /app/public/uploads -cf - . >"$uploads"

find backups -maxdepth 1 -type f \( -name 'db-*.dump' -o -name 'uploads-*.tar' \) -mtime "+${retention}" -print -delete

printf 'backup: %s (%s bytes) + %s (%s bytes); kept for %s days\n' \
	"$dump" "$(wc -c <"$dump")" "$uploads" "$(wc -c <"$uploads")" "$retention"
