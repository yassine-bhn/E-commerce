---
id: process-manager-saga
used_by: [pattern-selector, implementer, integration-test-agent]
---
# Process-manager saga (checkout)

## When
Multi-step workflow spans contexts with compensations (ECO-003).

## Steps
1. Confirm ADR-0004 flow: PlaceOrder + stock SYNC → payment ASYNC → confirm ASYNC.
2. Saga in `Application/Saga`; compensations ReleaseStock / VoidPayment on failure.
3. No event bus in saga constructor (EVT-002); outbox for domain events.
4. Integration tests: happy path + payment failure compensation.

## Rules
ECO-003, EVT-002, CMD-005, SEC-001
