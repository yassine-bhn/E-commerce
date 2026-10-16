#!/usr/bin/env bash
set -euo pipefail

if [ "$#" -lt 1 ]; then
  echo "usage: scope-guard.sh <agent-id> [changed-files...]" >&2
  exit 2
fi

hook_name="scope-guard"
agent_id=$1
shift

status=0

report_line() {
  printf '[HOOK:%s] %s:%s — %s %s\n' "$hook_name" "$1" "$2" "$3" "$4"
  status=1
}

repo_root=$(git rev-parse --show-toplevel 2>/dev/null || true)
if [ -n "$repo_root" ]; then
  cd "$repo_root"
fi

files=""
if [ "$#" -gt 0 ]; then
  files=$(printf '%s\n' "$@")
else
  files=$({ git diff --name-only HEAD 2>/dev/null || true; git diff --cached --name-only 2>/dev/null || true; git diff --name-only 2>/dev/null || true; } | sort -u)
fi

agent_file=".agents/agents/${agent_id}.md"
if [ ! -f "$agent_file" ]; then
  report_line "(global)" "0" "AGT-001" "unknown agent id '${agent_id}': no definition at ${agent_file}"
  exit "$status"
fi

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

while IFS= read -r file; do
  if [ -z "$file" ]; then
    continue
  fi
  if [ "$agent_id" = "orchestrator" ]; then
    case "$file" in
      .agents/state/*|.agents/audit/*) ;;
      *)
        report_line "$file" "0" "AGT-004" "orchestrator writes only .agents/state/** and .agents/audit/**"
        ;;
    esac
    continue
  fi
  if [ "${#globs[@]}" -eq 0 ]; then
    report_line "$file" "0" "AGT-001" "agent '${agent_id}' declares no write globs; read-only agents must not write files"
    continue
  fi
  matched=0
  for glob in "${globs[@]}"; do
    pattern="${glob//\*\*/*}"
    case "$file" in
      $pattern) matched=1 ;;
    esac
  done
  if [ "$matched" -eq 0 ]; then
    report_line "$file" "0" "AGT-001" "file outside agent '${agent_id}' write globs (allowed: ${allowed_desc% })"
  fi
done <<<"$files"

exit "$status"
