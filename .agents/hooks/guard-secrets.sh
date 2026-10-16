#!/usr/bin/env bash
set -euo pipefail

hook_name="guard-secrets"
status=0

repo_root=$(git rev-parse --show-toplevel 2>/dev/null || true)
if [ -n "$repo_root" ]; then
  cd "$repo_root"
fi

luhn() {
  local digits=$1 i d alt=0 sum=0
  for (( i=${#digits}-1; i>=0; i-- )); do
    d=${digits:i:1}
    if [ "$alt" -eq 1 ]; then
      d=$(( d * 2 ))
      if [ "$d" -gt 9 ]; then
        d=$(( d - 9 ))
      fi
    fi
    sum=$(( sum + d ))
    alt=$(( 1 - alt ))
  done
  [ $(( sum % 10 )) -eq 0 ]
}

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
    .env.example|*/.env.example)
      continue
      ;;
    .env|*/.env|.env.local|*/.env.local|.env.prod|*/.env.prod|.env.production|*/.env.production|.env.staging|*/.env.staging|*.pem|*.key)
      report_line "$file" 0 "SEC-005" "secret-bearing file must never be committed"
      continue
      ;;
  esac
  if [ "$(wc -c <"$file")" -gt 1048576 ]; then
    continue
  fi
  if ! grep -Iq . "$file"; then
    continue
  fi
  while IFS= read -r hit; do
    if [ -n "$hit" ]; then
      digits=$(printf '%s' "${hit#*:}" | tr -cd '0-9')
      if [ "${#digits}" -ge 13 ] && [ "${#digits}" -le 19 ]; then
        if luhn "$digits"; then
          report_line "$file" "${hit%%:*}" "SEC-001" "possible PAN (Luhn-valid card number) must never be stored or logged"
        fi
      fi
    fi
  done < <(grep -noE '([0-9][ -]?){12,18}[0-9]' "$file" || true)
  report_matches -winE "$file" 'cvv|cvc' "SEC-001" "never store or log card verification values"
  case "$file" in
    tests/*) ;;
    *)
      report_matches -niE "$file" "(password|passwd|secret|api[_-]?key|access[_-]?key|private[_-]?key)['\"]?[[:space:]]*[:=]>?[[:space:]]*['\"][^'\"\$%]{8,}['\"]" "SEC-005" "possible hardcoded secret; use env var or vault"
      report_matches -niE "$file" 'AKIA[0-9A-Z]{16}|-----BEGIN [A-Z ]*PRIVATE KEY-----|sk_live_|ghp_|xox[baprs]-' "SEC-005" "possible credential committed"
      ;;
  esac
done <<<"$files"

exit "$status"
