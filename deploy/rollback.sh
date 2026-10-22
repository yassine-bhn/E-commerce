#!/usr/bin/env bash
# Rollback (R17, D6): revert the image tags, restart with --wait, smoke-test readiness.
# Schema is NOT reverted — migrations are forward-only. Volumes are never removed.
set -euo pipefail

root=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
cd "$root"

env_file=${ENV_FILE:-.env.prod}
compose_file=${COMPOSE_FILE:-compose.prod.yaml}

die() {
	printf 'rollback: %s\n' "$*" >&2
	exit 1
}

[ -f "$env_file" ] || die "missing $env_file"

get_key() {
	sed -n "s/^$1=//p" "$env_file" | tail -n1
}

set_key() {
	local key=$1 value=$2 tmp
	tmp=$(mktemp)
	awk -v key="$key" -v value="$value" '
		BEGIN { found = 0 }
		index($0, key "=") == 1 { print key "=" value; found = 1; next }
		{ print }
		END { if (!found) print key "=" value }
	' "$env_file" >"$tmp"
	mv "$tmp" "$env_file"
}

target=$(get_key PREVIOUS_IMAGE_TAG)
[ -n "$target" ] || die "no PREVIOUS_IMAGE_TAG in $env_file — there is no recorded release to go back to"
current=$(get_key IMAGE_TAG)

compose=(docker compose --env-file "$env_file" -f "$compose_file")
"${compose[@]}" config --quiet || die "invalid compose configuration"

printf 'rollback: %s -> %s\n' "$current" "$target"
set_key IMAGE_TAG "$target"
set_key PREVIOUS_IMAGE_TAG "$current"

"${compose[@]}" pull app worker outbox storefront
"${compose[@]}" up -d --wait app database meilisearch storefront
"${compose[@]}" up -d edge worker outbox

smoke_ready() {
	local attempt code
	for attempt in $(seq 1 30); do
		code=$("${compose[@]}" exec -T app curl -s -o /tmp/readyz.json -w '%{http_code}' http://127.0.0.1:8080/readyz 2>/dev/null || true)
		if [ "$code" = "200" ]; then
			return 0
		fi
		sleep 2
	done
	return 1
}

if ! smoke_ready; then
	printf 'rollback: /readyz did not answer 200 on the previous image\n' >&2
	printf 'rollback: the schema may be ahead of the code — migrations are forward-only (D6)\n' >&2
	"${compose[@]}" logs --no-color --tail=50 app || true
	exit 1
fi

printf 'rollback: back on %s (was %s). Volumes untouched; schema unchanged.\n' "$target" "$current"
