---
id: add-domain-event
used_by: [implementer, integration-test-agent, projection-test-agent]
---
# Add a domain event + outbox

## When
Aggregate state change must be visible to other contexts asynchronously.

## Steps
1. Past-tense `final readonly` event in `Domain/Event` with IDs/scalars only (EVT-001).
2. Aggregate records event; handler appends to outbox — never `EventBusInterface` in handler (EVT-002).
3. Integration test: outbox row in same transaction; rollback leaves no row.
4. Subscriber/projection claims `processed_events` first (EVT-003).

## Rules
EVT-001..003, AGG-001
