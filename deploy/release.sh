#!/usr/bin/env bash
# Release procedure (R16, D2/D6). Runs on the Docker host, never in CI.
#
#   ./deploy/release.sh <image-tag>
#   ./deploy/release.sh            # keep the IMAGE_TAG already pinned in .env.prod
#
# Validates .env.prod, records the running tag as PREVIOUS_IMAGE_TAG, pulls, migrates,
# rebuilds the search index, starts the stack with --wait and smoke-tests /readyz.
# Aborts on the first failure (set -euo pipefail). Migrations are forward-only (D6).
set -euo pipefail

root=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
cd "$root"

env_file=${ENV_FILE:-.env.prod}
compose_file=${COMPOSE_FILE:-compose.prod.yaml}
tag=${1:-}

die() {
	printf 'release: %s\n' "$*" >&2
	exit 1
}

[ -f "$compose_file" ] || die "missing $compose_file"
[ -f "$env_file" ] || die "missing $env_file (copy .env.prod.example)"

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

required=(APP_DOMAIN STOREFRONT_DOMAIN EDGE_EMAIL APP_SECRET POSTGRES_DB POSTGRES_USER POSTGRES_PASSWORD \
	MEILI_MASTER_KEY BACKEND_IMAGE IMAGE_TAG STOREFRONT_IMAGE STOREFRONT_CORS_ORIGIN STOREFRONT_APP_URL \
	MAILER_DSN BACKUP_RETENTION_DAYS WHATSAPP_PHONE_NUMBER)
missing=()
for key in "${required[@]}"; do
	value=$(get_key "$key")
	case "$value" in
	"" | change-me*) missing+=("$key") ;;
	esac
done
[ "${#missing[@]}" -eq 0 ] || die "unset or placeholder values in $env_file: ${missing[*]}"

# WHATSAPP_DEFAULT_MESSAGE may legitimately be empty (no prefilled text), so it only has to be
# declared: an absent key would silently reach the container as the empty string anyway, and this
# check is what tells the operator the key exists.
[ -n "$(grep '^WHATSAPP_DEFAULT_MESSAGE=' "$env_file" || true)" ] ||
	die "WHATSAPP_DEFAULT_MESSAGE is not declared in $env_file — declare it (it may be empty)"

# The number is judged exactly as the application will parse it: WhatsAppNumber strips one leading
# "+" and requires the rest to be 8-15 digits. Surrounding quotes are dropped, but internal spaces,
# dashes or parentheses are refused rather than normalised away — a gate that accepts a value the
# runtime cannot parse would green-light a stack whose every contact request answers 500.
whatsapp_number=$(get_key WHATSAPP_PHONE_NUMBER)
whatsapp_number=${whatsapp_number%\"}
whatsapp_number=${whatsapp_number#\"}
whatsapp_number=${whatsapp_number%\'}
whatsapp_number=${whatsapp_number#\'}
whatsapp_number=${whatsapp_number#+}
[[ "$whatsapp_number" =~ ^[0-9]{8,15}$ ]] ||
	die "WHATSAPP_PHONE_NUMBER must be 8 to 15 digits (optionally prefixed with +), got: $(get_key WHATSAPP_PHONE_NUMBER)"

# A syntactically valid placeholder would still ship a link nobody answers: refuse it explicitly.
[ "$whatsapp_number" != "21600000000" ] ||
	die "WHATSAPP_PHONE_NUMBER is still the 21600000000 placeholder — set the real WhatsApp Business number in $env_file"

# Ship exactly what was validated. A variable inherited from the shell would otherwise win over
# --env-file at compose interpolation time and bypass every check above.
unset WHATSAPP_PHONE_NUMBER WHATSAPP_DEFAULT_MESSAGE
export WHATSAPP_PHONE_NUMBER=$whatsapp_number

compose=(docker compose --env-file "$env_file" -f "$compose_file")
"${compose[@]}" config --quiet || die "invalid compose configuration"

# The tag currently serving defines the rollback target; a first deploy has none.
previous=""
app_id=$("${compose[@]}" ps -q app 2>/dev/null | head -n1 || true)
if [ -n "$app_id" ]; then
	current_image=$(docker inspect --format '{{.Config.Image}}' "$app_id")
	previous=${current_image##*:}
fi

if [ -n "$tag" ]; then
	set_key IMAGE_TAG "$tag" "$env_file"
fi
target=$(get_key IMAGE_TAG)
[ -n "$target" ] || die "IMAGE_TAG is empty"

if [ -n "$previous" ] && [ "$previous" != "$target" ]; then
	set_key PREVIOUS_IMAGE_TAG "$previous" "$env_file"
	printf 'release: previous tag recorded: %s\n' "$previous"
fi

printf 'release: pulling %s\n' "$target"
"${compose[@]}" pull app worker outbox storefront

printf 'release: applying migrations\n'
"${compose[@]}" run --rm app php bin/console doctrine:migrations:migrate -n --allow-no-migration

# MIG-002: the approval migration is schema-only, so the rows that predate the workflow are approved
# here, by the operator path, not by a migration that would redo it on every re-run. Idempotent: an
# already-approved catalogue reports 0 and writes nothing.
printf 'release: approving the catalogue that predates the approval workflow\n'
"${compose[@]}" run --rm app php bin/console app:catalog:backfill-approval --apply

printf 'release: rebuilding the storefront search index\n'
"${compose[@]}" run --rm app php bin/console app:storefront:index-search

printf 'release: starting the stack\n'
"${compose[@]}" up -d --wait app database meilisearch storefront
"${compose[@]}" up -d edge worker outbox

smoke_ready() {
	local attempt code
	for attempt in $(seq 1 30); do
		code=$("${compose[@]}" exec -T app curl -s -o /tmp/readyz.json -w '%{http_code}' http://127.0.0.1:8080/readyz 2>/dev/null || true)
		if [ "$code" = "200" ]; then
			"${compose[@]}" exec -T app cat /tmp/readyz.json
			printf '\n'
			return 0
		fi
		sleep 2
	done
	return 1
}

if ! smoke_ready; then
	printf 'release: /readyz did not answer 200\n' >&2
	"${compose[@]}" logs --no-color --tail=50 app || true
	exit 1
fi

printf 'release: https://%s is live (tag %s)\n' "$(get_key APP_DOMAIN)" "$target"
