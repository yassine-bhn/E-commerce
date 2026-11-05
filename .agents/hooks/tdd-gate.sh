#!/usr/bin/env bash
# TDD-001/TDD-002 gate: src/** edits require a recorded, well-formed RED entry
# in .agents/state/<TASK_ID>.md whose failing test is part of the current change.
#
# Invocation: tdd-gate.sh [TASK_ID] [files...] — TASK_ID may also come from the
# environment. Without explicit file arguments the RED test file must be
# modified/added in the working tree or index (needs git); with explicit file
# arguments it must exist and be one of them (no git needed — hook tests).
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
    *)
      if [ -z "$TASK_ID" ]; then
        TASK_ID=$arg
      else
        files+=("$arg")
      fi
      ;;
  esac
done

# the gate only guards production changes
src_touched=0
for f in "${files[@]:-}"; do
  case "$f" in
    src/*) src_touched=1 ;;
  esac
done
if [ "${#files[@]}" -gt 0 ] && [ "$src_touched" -eq 0 ]; then
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

# RED: tests/…Test.php — "<quoted failing assertion>" — cmd: <command> → FAIL
RED_LINE_RE='^RED:[[:space:]]+(tests/[^[:space:]]+Test\.php)[[:space:]]+—[[:space:]]+"[^"]+"[[:space:]]+—[[:space:]]+cmd:[[:space:]]+[^[:space:]].*[[:space:]]+→[[:space:]]+FAIL[[:space:]]*$'

have_valid_red=0
red_path=""

while IFS= read -r line; do
  [ -n "$line" ] || continue
  case "$line" in
    RED:*) ;;
    *) continue ;;
  esac
  if printf '%s' "$line" | grep -qE "$RED_LINE_RE"; then
    if [ "$have_valid_red" -eq 0 ]; then
      red_path=$(printf '%s' "$line" | sed -E 's/^RED:[[:space:]]+(tests\/[^[:space:]]+Test\.php).*/\1/')
      have_valid_red=1
    fi
  else
    report_line "$state_file" 0 "TDD-002" "malformed RED line (need: RED: tests/…Test.php — \"<assertion>\" — cmd: <command> → FAIL): $line"
  fi
done <"$state_file"

if [ "$have_valid_red" -eq 0 ]; then
  if [ "$status" -eq 0 ]; then
    report_line "$state_file" 0 "TDD-001" 'missing RED record: RED: <test file> — "<failing assertion>" — cmd: <command> → FAIL'
  fi
  exit "$status"
fi

if [ "${#files[@]}" -gt 0 ]; then
  found=0
  for f in "${files[@]}"; do
    if [ "$f" = "$red_path" ]; then
      found=1
      break
    fi
  done
  if [ "$found" -eq 0 ] || [ ! -f "$red_path" ]; then
    report_line "$state_file" 0 "TDD-002" "RED test ${red_path} must exist and be one of the changed files"
  fi
  exit "$status"
fi

# no explicit files: the RED test must be modified/added in the working tree or index
if ! { git diff --name-only HEAD -- "$red_path" 2>/dev/null | grep -q . \
    || git diff --cached --name-only -- "$red_path" 2>/dev/null | grep -q . \
    || git ls-files --others --exclude-standard -- "$red_path" 2>/dev/null | grep -q .; }; then
  report_line "$state_file" 0 "TDD-002" "RED test ${red_path} is not modified or added in the working tree or index"
fi

exit "$status"
