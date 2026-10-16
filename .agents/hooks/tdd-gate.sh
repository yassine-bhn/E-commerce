#!/usr/bin/env bash
set -euo pipefail

hook_name="tdd-gate"
status=0

repo_root=$(git rev-parse --show-toplevel 2>/dev/null || true)
if [ -n "$repo_root" ]; then
  cd "$repo_root"
fi

report_line() {
  printf '[HOOK:%s] %s:%s — %s %s\n' "$hook_name" "$1" "$2" "$3" "$4"
  status=1
}

TASK_ID=${TASK_ID:-}
files=()
for arg in "$@"; do
  case "$arg" in
    TASK_ID=*)
      TASK_ID=${arg#TASK_ID=}
      ;;
    [A-Za-z]-*)
      if [ -z "$TASK_ID" ]; then
        TASK_ID=$arg
      else
        files+=("$arg")
      fi
      ;;
    *)
      files+=("$arg")
      ;;
  esac
done

if [ "${#files[@]}" -eq 0 ]; then
  while IFS= read -r line; do
    if [ -n "$line" ]; then
      files+=("$line")
    fi
  done < <({ git diff --name-only HEAD 2>/dev/null || true; git diff --cached --name-only 2>/dev/null || true; git diff --name-only 2>/dev/null || true; } | sort -u)
fi

src_touched=0
for f in "${files[@]}"; do
  case "$f" in
    src/*)
      src_touched=1
      ;;
  esac
done

if [ "$src_touched" -eq 0 ]; then
  exit 0
fi

if [ -z "$TASK_ID" ]; then
  report_line "(global)" 0 "TDD-001" "src/** changes need a task id; pass <task-id> or set TASK_ID"
  exit "$status"
fi

state_file=".agents/state/${TASK_ID}.md"
if [ ! -f "$state_file" ]; then
  report_line "(global)" 0 "TDD-001" "no state file at ${state_file}; record RED before touching src/**"
  exit "$status"
fi

if ! grep -qE '^RED:' "$state_file"; then
  report_line "$state_file" 0 "TDD-001" 'missing RED record: RED: <test file> — "<failing assertion>" — cmd: <command> → FAIL'
fi

exit "$status"
