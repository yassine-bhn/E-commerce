#!/usr/bin/env bash
set -euo pipefail

hook_name="guard-cqrs"
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
  done < <(grep "$flags" -e "$pattern" "$target" || true)
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

  if [[ "$file" == src/* ]]; then
    report_matches -nE "$file" '->setAvailable\(|->setReserved\(' "ECO-004" "never read-then-write stock; use an atomic conditional update"
    report_matches -nE "$file" 'match\s*\(\s*true\s*\)' "OCP-002" "avoid match(true) flag switches; use explicit strategies"
    report_matches -nE "$file" '(\$container|->container)\s*->\s*get\(' "DIP-002" "never pull services from the container; inject them"
    report_matches -nE "$file" '\bsleep\(|\b(mt_)?rand\(|\buniqid\(|\btime\(\)|\bnew \\?DateTime' "TDD-005" "non-deterministic API in production code; use ClockInterface"
  fi

  if [[ "$file" == src/*/Domain/* ]]; then
    report_matches -nE "$file" ': \??float|float \$|\(float\)|floatval\(' "ECO-001" "money must never be a float; use Money (int minor units)"
  fi

  if [[ "$file" == src/*/Application/Command/*Command.php ]]; then
    if ! grep -qE '^final (readonly )?class ' "$file"; then
      class_line=$(grep -m1 -nE '^((final|readonly|abstract) )*class ' "$file" | cut -d: -f1 || true)
      if [ -z "$class_line" ]; then
        class_line=1
      fi
      report_line "$file" "$class_line" "CMD-002" "commands must be final immutable classes"
    fi
    report_matches -nE "$file" 'function set[A-Z]' "CMD-002" "commands must be immutable; no setters"
    report_matches -nE "$file" 'function get[A-Z]' "CMD-003" "commands must not expose getters; handlers read public properties"
  fi

  if [[ "$file" == src/*/Application/CommandHandler/*Handler.php ]]; then
    invoke_line=$(grep -m1 -nE 'function __invoke' "$file" | cut -d: -f1 || true)
    if [ -z "$invoke_line" ]; then
      report_line "$file" 1 "CMD-003" "command handler must declare __invoke with a void return type"
    elif ! awk -v start="$invoke_line" 'NR >= start && NR <= start + 8' "$file" | grep -qE '\)[[:space:]]*:[[:space:]]*void'; then
      report_line "$file" "$invoke_line" "CMD-003" "command handler __invoke must return void"
    fi
    report_matches -nE "$file" '->getResult\(' "CMD-003" "command handlers must return nothing, not a query result"
  fi

  if [[ "$file" == src/*/Application/QueryHandler/*Handler.php || "$file" == src/*/Infrastructure/ReadModel/*.php ]]; then
    report_matches -nE "$file" '->persist\(|->flush\(|beginTransaction|commandBus->dispatch|INSERT INTO |UPDATE |DELETE FROM' "QRY-001" "query side must never write, flush, or dispatch"
    report_matches -nE "$file" '^use .*Doctrine\\(ORM|Persistence)\\' "QRY-003" "query side must use Doctrine DBAL, not ORM"
  fi

  if [[ "$file" == src/*/Infrastructure/Http/*.php ]]; then
    report_matches -nE "$file" 'catch[[:space:]]*\(.*Throwable' "ERR-003" "never catch Throwable; catch specific exceptions"
  fi

  if [[ "$file" == tests/* ]]; then
    report_matches -nE "$file" '\bsleep\(|\b(mt_)?rand\(|\buniqid\(|\btime\(\)|\bnew \\?DateTime' "TDD-005" "non-deterministic API in tests; use ClockInterface and test doubles"
    report_matches -nE "$file" 'catch[[:space:]]*\(' "TDD-006" "tests must not swallow exceptions"
    while IFS= read -r mock_line; do
      if [ -n "$mock_line" ]; then
        cls=${mock_line#*createMock(}
        cls=${cls%%)*}
        cls=${cls// /}
        name=${cls##*\\}
        name=${name%%::*}
        if [ -n "$name" ]; then
          case "$name" in
            *Interface|*Repository|*Gateway|*Provider|*Generator|*Clock|*Bus|*Notifier|*Sender|*Client|*Finder|*Checker|*Authorizer|*Mailer|*Publisher|*Relay|*Scrubber|*Indexer|*Service) ;;
            *) report_line "$file" "${mock_line%%:*}" "TDD-008" "mock only port interfaces (or inline fakes); never mock domain implementations" ;;
          esac
        fi
      fi
    done < <(grep -nE 'createMock\(' "$file" || true)
  fi
done <<<"$files"

exit "$status"
