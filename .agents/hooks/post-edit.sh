#!/usr/bin/env bash
set -euo pipefail

hook_name="post-edit"
status=0

report_line() {
  printf '[HOOK:%s] %s:%s — %s %s\n' "$hook_name" "$1" "$2" "$3" "$4"
  status=1
}

candidates=""
if [ "$#" -gt 0 ]; then
  candidates=$(printf '%s\n' "$@")
else
  candidates=$({ git diff --name-only HEAD 2>/dev/null || true; git diff --cached --name-only 2>/dev/null || true; git diff --name-only 2>/dev/null || true; } | sort -u)
fi

files=()
while IFS= read -r candidate; do
  case "$candidate" in
    *.php) files+=("$candidate") ;;
  esac
done <<<"$candidates"

if [ "${#files[@]}" -eq 0 ]; then
  exit 0
fi

repo_root=$(git rev-parse --show-toplevel 2>/dev/null || true)
if [ -n "$repo_root" ]; then
  cd "$repo_root"
fi

if [ -x vendor/bin/php-cs-fixer ]; then
  fixer_status=0
  vendor/bin/php-cs-fixer fix --path-mode=intersection -- "${files[@]}" >/dev/null || fixer_status=$?
  if [ "$fixer_status" -ne 0 ] && [ "$fixer_status" -ne 8 ]; then
    report_line "(global)" "0" "NAM-001" "php-cs-fixer failed (exit ${fixer_status})"
  fi
else
  echo "php-cs-fixer not installed; skipping (run composer install)" >&2
fi

if [ -x vendor/bin/phpstan ]; then
  if ! vendor/bin/phpstan analyse --no-progress --error-format=raw -- "${files[@]}"; then
    report_line "(global)" "0" "LSP-002" "phpstan reported errors in edited files"
  fi
else
  echo "phpstan not installed; skipping (run composer install)" >&2
fi

exit "$status"
