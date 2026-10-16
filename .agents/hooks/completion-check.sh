#!/usr/bin/env bash
set -euo pipefail

if [ "$#" -lt 1 ]; then
  echo "usage: completion-check.sh <agent-id> [handoff-file|-]" >&2
  exit 2
fi

hook_name="completion-check"
agent_id=$1
handoff_file=${2:-}

status=0

report_line() {
  printf '[HOOK:%s] %s:%s — %s %s\n' "$hook_name" "$1" "$2" "$3" "$4"
  status=1
}

repo_root=$(git rev-parse --show-toplevel 2>/dev/null || true)
if [ -n "$repo_root" ]; then
  cd "$repo_root"
fi

cleanup=""
trap 'if [ -n "$cleanup" ]; then rm -f "$cleanup"; fi' EXIT

if [ -z "$handoff_file" ] || [ "$handoff_file" = "-" ]; then
  handoff=$(mktemp)
  cleanup="$handoff"
  cat >"$handoff"
else
  if [ ! -f "$handoff_file" ]; then
    echo "handoff file not found: ${handoff_file}" >&2
    exit 2
  fi
  handoff="$handoff_file"
fi

agent_known=1
agent_file=".agents/agents/${agent_id}.md"
if [ ! -f "$agent_file" ]; then
  report_line "(handoff)" "0" "AGT-005" "unknown agent id '${agent_id}': no definition at ${agent_file}"
  agent_known=0
fi

if ! grep -qE '^## Handoff' "$handoff"; then
  report_line "(handoff)" "0" "AGT-005" "missing required section '## Handoff'"
fi

fields=("Task:" "Agent:" "Gate:" "Summary:" "Artifacts:" "Rules applied:" "Rules violated:" "Commands run:" "Open questions:" "Next agent:")
for field in "${fields[@]}"; do
  if ! grep -qE "^- ${field}" "$handoff"; then
    report_line "(handoff)" "0" "AGT-005" "missing required field '${field}'"
  fi
done

agent_value=$(grep -m1 -E '^- Agent:' "$handoff" | sed 's/^- Agent:[[:space:]]*//; s/[[:space:]]*$//' || true)
if [ -n "$agent_value" ] && [ "$agent_value" != "$agent_id" ]; then
  report_line "(handoff)" "0" "AGT-005" "handoff Agent '${agent_value}' does not match dispatched agent '${agent_id}'"
fi

gate_value=$(grep -m1 -E '^- Gate:' "$handoff" | sed 's/^- Gate:[[:space:]]*//' || true)
gate_token="${gate_value%% *}"
if [ -n "$gate_token" ]; then
  case "$gate_token" in
    PLAN|PATTERN|RED|GREEN|REFACTOR|SPEC-TESTS|REVIEW|FIXES|INVESTIGATE|DONE|ESCALATE|BLOCKED) ;;
    *)
      report_line "(handoff)" "0" "AGT-005" "invalid gate '${gate_token}'; expected one of PLAN, PATTERN, RED, GREEN, REFACTOR, SPEC-TESTS, REVIEW, FIXES, INVESTIGATE, DONE, ESCALATE, BLOCKED"
      ;;
  esac
fi

while IFS=: read -r lineno line_content; do
  if ! grep -qE '^\[(CRITICAL|HIGH|MEDIUM|LOW)\]\[[A-Z]+-[0-9]{3}\] [^ ]+:[0-9]+ — .+$' <<<"$line_content"; then
    report_line "(handoff)" "$lineno" "AGT-002" "malformed finding line: ${line_content}"
  fi
done < <(grep -nE '^\[' "$handoff" || true)

if [ "$agent_known" -eq 1 ]; then
  globs=()
  while IFS= read -r glob; do
    if [ -n "$glob" ]; then
      globs+=("$glob")
    fi
  done < <(sed -nE 's/^writes:[[:space:]]*\[(.*)\].*$/\1/p' "$agent_file" | grep -oE '"[^"]+"' | tr -d '"' || true)

  allowed_desc=""
  if [ "${#globs[@]}" -gt 0 ]; then
    allowed_desc=$(printf '%s ' "${globs[@]}")
  fi

  while IFS= read -r hit; do
    lineno=${hit%%:*}
    content=${hit#*: }
    if [[ "$content" =~ ^-[[:space:]](.+)[[:space:]]\((created|modified|read)\)$ ]]; then
      artifact_path="${BASH_REMATCH[1]}"
      artifact_action="${BASH_REMATCH[2]}"
      if [ "$artifact_action" = "read" ]; then
        continue
      fi
      matched=0
      for glob in "${globs[@]}"; do
        pattern="${glob//\*\*/*}"
        case "$artifact_path" in
          $pattern) matched=1 ;;
        esac
      done
      if [ "${#globs[@]}" -eq 0 ] || [ "$matched" -eq 0 ]; then
        report_line "(handoff)" "$lineno" "AGT-003" "artifact '${artifact_path}' (${artifact_action}) outside agent '${agent_id}' write globs (allowed: ${allowed_desc% })"
      fi
    else
      report_line "(handoff)" "$lineno" "AGT-005" "malformed artifact line: ${content}"
    fi
  done < <(awk '/^- Artifacts:/{inblock=1;next} /^- Rules applied:/{inblock=0} inblock && length($0)>0 {print NR": "$0}' "$handoff")
fi

exit "$status"
