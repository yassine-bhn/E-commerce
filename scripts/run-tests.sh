#!/usr/bin/env bash
# Run every PHPUnit suite, keep going after a failure, then list the failing tests.
#
#   ./scripts/run-tests.sh                  # all six suites, in order
#   ./scripts/run-tests.sh Unit Contract    # a subset, same report at the end
#   COMPOSE_FILE=compose.prod.yaml ./scripts/run-tests.sh
#
# Suites stay sequential and in this order because Integration resets the schema
# and must never race Functional (R3). The exit status is the reporter's:
# non-zero as soon as one suite is red, but only after every suite had its chance.
#
# Artifacts (both under the gitignored var/):
#   var/test-reports/<run>/<suite>.xml   written by phpunit inside the container
#   var/test-logs/<suite>.log            written by this script, plus status.tsv
# The two trees are kept apart on purpose: the dev container runs as root, so a
# directory it creates is not writable from the host. A fresh <run> directory per
# invocation also means a stale report can never stand in for a crashed suite.
set -uo pipefail

root=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
cd "$root"

compose_file=${COMPOSE_FILE:-compose.yaml}
reports=var/test-reports/$(date -u +%Y%m%dT%H%M%SZ)
logs=var/test-logs
status=$logs/status.tsv

die() {
	printf 'run-tests: %s\n' "$*" >&2
	exit 1
}

command -v python3 >/dev/null 2>&1 || die "python3 is required to render the report (scripts/test-report.py)"

suites=("$@")
[ ${#suites[@]} -gt 0 ] || suites=(Unit Integration Projection Functional Contract Architecture)

mkdir -p "$logs"
rm -f "$logs"/*.log "$status"
: >"$status"
docker compose -f "$compose_file" exec -T app mkdir -p "$reports" || die "cannot create $reports inside the app container"

for suite in "${suites[@]}"; do
	slug=$(printf '%s' "$suite" | tr '[:upper:]' '[:lower:]')
	xml=$reports/$slug.xml
	log=$logs/$slug.log

	printf '\n===== %s =====\n' "$suite"
	docker compose -f "$compose_file" exec -T app \
		vendor/bin/phpunit --testsuite "$suite" --log-junit "$xml" 2>&1 | tee "$log"
	code=${PIPESTATUS[0]}

	printf '%s\t%s\t%s\n' "$suite" "$code" "$log" >>"$status"
done

printf '\n'
python3 scripts/test-report.py --reports "$reports" --status "$status"
