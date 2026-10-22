#!/usr/bin/env bash
# Database restore (R18). Destructive: refuses without --yes and, by default, while the app is serving.
#
#   ./scripts/restore-db.sh backups/db-20261022T120000Z.dump --yes
#   ./scripts/restore-db.sh backups/db-...dump --yes --force   # app still up, use with care
#   COMPOSE_FILE=compose.prod.yaml ENV_FILE=.env.prod ./scripts/restore-db.sh ... --yes
#
# The dump is restored with --clean --if-exists: existing schema objects are dropped first.
# Migrations are forward-only (D6): after restoring an older dump, run the release again.
set -euo pipefail

root=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
cd "$root"

compose_file=${COMPOSE_FILE:-compose.yaml}
env_file=${ENV_FILE:-}

usage() {
	printf 'usage: %s <backup.dump> --yes [--force]\n' "$0" >&2
}

die() {
	printf 'restore: %s\n' "$*" >&2
	exit 1
}

file=""
assume_yes=0
force=0
for arg in "$@"; do
	case "$arg" in
	--yes) assume_yes=1 ;;
	--force) force=1 ;;
	-h | --help)
		usage
		exit 0
		;;
	-*)
		usage
		exit 2
		;;
	*) file=$arg ;;
	esac
done

[ -n "$file" ] || {
	usage
	exit 2
}
[ -f "$file" ] || die "no such dump: $file"
[ "$assume_yes" -eq 1 ] || die "refusing without --yes: this replaces the current database contents"

compose=(docker compose -f "$compose_file")
if [ -n "$env_file" ]; then
	compose+=(--env-file "$env_file")
fi

if [ "$force" -eq 0 ]; then
	if "${compose[@]}" ps --status running --services 2>/dev/null | grep -qx app; then
		die "the app container is still serving; stop it first (app, worker, outbox) or pass --force"
	fi
fi

db_user=$("${compose[@]}" exec -T database sh -lc 'printf %s "$POSTGRES_USER"')
db_name=$("${compose[@]}" exec -T database sh -lc 'printf %s "$POSTGRES_DB"')
[ -n "$db_user" ] && [ -n "$db_name" ] || die "could not read POSTGRES_USER/POSTGRES_DB from the database container"

"${compose[@]}" exec -T database pg_restore -U "$db_user" -d "$db_name" \
	--clean --if-exists --no-owner --no-privileges --single-transaction <"$file"

printf 'restore: %s restored into %s. Start the stack, then release so migrations catch up.\n' "$file" "$db_name"
