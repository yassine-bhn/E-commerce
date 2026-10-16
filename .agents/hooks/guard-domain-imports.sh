#!/usr/bin/env bash
set -euo pipefail

hook_name="guard-domain-imports"
status=0

repo_root=$(git rev-parse --show-toplevel 2>/dev/null || true)
if [ -n "$repo_root" ]; then
  cd "$repo_root"
fi

report_line() {
  printf '[HOOK:%s] %s:%s — %s %s\n' "$hook_name" "$1" "$2" "$3" "$4"
  status=1
}

report_matches() {
  local flags=$1 target=$2 pattern=$3 rule=$4 message=$5 line
  while IFS= read -r line; do
    if [ -n "$line" ]; then
      report_line "$target" "${line%%:*}" "$rule" "$message"
    fi
  done < <(grep "$flags" "$pattern" "$target" || true)
}

if [ "$#" -gt 0 ]; then
  files=$(printf '%s\n' "$@")
else
  files=$({ git diff --name-only HEAD 2>/dev/null || true; git diff --cached --name-only 2>/dev/null || true; git diff --name-only 2>/dev/null || true; } | sort -u)
fi

while IFS= read -r file; do
  if [ -z "$file" ] || [ ! -f "$file" ]; then
    continue
  fi
  case "$file" in
    src/*/Domain/*.php)
      report_matches -nE "$file" '^use +(Symfony|Doctrine|Psr|Meilisearch)|^use [^;]*\\Infrastructure\\' "LAY-001" "Domain must not import framework or infrastructure namespaces"
      ;;
    src/*/Application/*.php)
      report_matches -nE "$file" '^use +(Symfony\\Component\\Http|Doctrine)|^use [^;]*\\Infrastructure\\' "LAY-002" "Application must not import Doctrine, Symfony HTTP, or infrastructure namespaces"
      ;;
  esac
done <<<"$files"

exit "$status"
