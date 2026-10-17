# ADR-0003 — UX consistency (write vs read)

Status: accepted. Relies on: CMD-003, ECO-007.

## Decision

Command handlers return **void**; UIs poll or subscribe to read models (`*_view`, Meilisearch) for confirmation — no ORM entities on the API boundary.

## Rejected alternatives

- Return aggregate from command handler (rejected: couples UI to write model).
