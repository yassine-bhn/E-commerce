---
id: add-query
used_by: [test-planner, implementer, projection-test-agent]
---
# Add a query + read handler

## When
New read-side use case or API response.

## Steps
1. Query DTO in `Application/Query` with requester identity (QRY-004).
2. Handler in `Infrastructure/ReadModel` using DBAL/SQL only (QRY-003).
3. Return `*View` DTOs, never entities (QRY-002); no persist/flush/dispatch (QRY-001).
4. Functional test for authorization (customer A cannot read B).

## Rules
QRY-001..004, SEC-002
