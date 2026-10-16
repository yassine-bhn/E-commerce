#!/usr/bin/env bash
set -euo pipefail

hook_name="pre-finish"
status=0

report_line() {
  printf '[HOOK:%s] %s:%s — %s %s\n' "$hook_name" "$1" "$2" "$3" "$4"
  status=1
}

run_required() {
  local rule=$1 label=$2 bin=$3
  shift 3
  if [ ! -x "$bin" ]; then
    report_line "(global)" "0" "$rule" "${label}: ${bin} not installed (run composer install)"
    return 0
  fi
  if ! "$bin" "$@" >/dev/null 2>&1; then
    report_line "(global)" "0" "$rule" "${label}: ${bin} failed"
  fi
}

run_required "LSP-002" "static analysis" vendor/bin/phpstan analyse --no-progress
run_required "LAY-004" "layer boundaries" vendor/bin/deptrac analyse --config-file=deptrac.yaml
run_required "LSP-001" "unit tests" vendor/bin/phpunit --testsuite=Unit
run_required "LSP-001" "integration tests" vendor/bin/phpunit --testsuite=Integration
run_required "LSP-001" "projection tests" vendor/bin/phpunit --testsuite=Projection
run_required "LSP-001" "functional tests" vendor/bin/phpunit --testsuite=Functional
run_required "LSP-001" "contract tests" vendor/bin/phpunit --testsuite=Contract
run_required "TDD-010" "mutation testing" vendor/bin/infection --threads=max --min-msi=85 --min-covered-msi=90

exit "$status"
