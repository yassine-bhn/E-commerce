---
id: add-command
used_by: [test-planner, unit-test-writer, implementer, integration-test-agent]
---
# Add a command + handler

## When
New write-side business task in a bounded context.

## Steps
1. Name `<Verb><Noun>` task command (CMD-001); `final readonly` DTO (CMD-002).
2. Unit-test aggregate/handler behavior; RED record.
3. Handler in `Application/CommandHandler`, `: void` __invoke (CMD-003); one handler on `command.bus` (CMD-004).
4. Transaction boundary in handler; outbox in same TX (CMD-005, EVT-002).
5. Integration test: outbox row + idempotency if retryable (CMD-006, EVT-003).

## Rules
CMD-001..006, EVT-002, BUS-001
