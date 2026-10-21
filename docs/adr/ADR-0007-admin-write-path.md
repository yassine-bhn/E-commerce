# ADR-0007 — Admin write path and the delete-user boundary

Status: accepted. Relies on: AGENTS.md 2/4/6, CMD-001..CMD-006, QRY-001..QRY-004, SEC-002, LAY-004,
spec R9/R10/R14/R16/R25/R28 (Q2 and Q4, locked 2026-10-20).

## Decision

**Every endpoint written under this spec uses the documented CQRS path (Q2).**

- Writes: one task-based, immutable command DTO (`final`, readonly constructor properties, no
  getters for results) in `src/<Context>/Application/Command/`, handled by exactly **one**
  `command.bus` handler in `src/<Context>/Application/CommandHandler/` declaring `: void`. The
  handler is the transaction boundary (`doctrine_transaction` middleware); domain events leave
  through the outbox in the same transaction, never inline (EVT-002).
- Reads: a query DTO carrying the requester identity, handled by a DBAL-only finder in
  `src/<Context>/Infrastructure/ReadModel/` that authorizes **inside** the handler (QRY-004) and
  returns `*View` DTOs (QRY-002) built from arrays/scalars. Never `persist`/`flush`/
  write-`executeStatement`/dispatch (QRY-001), never the ORM (QRY-003).
- Controllers stay thin: decode → build the command/query → dispatch on the right bus → map the
  read model to the existing `{"error": …}` / `{items: […]}` JSON shapes. No SQL in a controller.
- Deptrac and phpstan enforce the layering for these paths, so a deviation fails the build rather
  than passing review.

**Legacy is frozen, not extended.** `AdminProductController`, `AdminBrandController`,
`AdminCategoryController` and `AdminPromoCodeController` write and read through direct DBAL in the
controller, which contradicts AGENTS.md 2/4/6. They are declared **legacy**: they keep working, they
gain no new sibling, and no controller written under this spec extends their raw-SQL style. The
delete-category and delete-product routes already live there, so this spec reuses them as-is (R4,
R9, R26) instead of adding a second path; the one route file WP2 owns, `AdminCategoryController.php`,
is migrated to commands/queries only for the routes this spec adds, and its existing routes keep
their current contract.

**Delete a user (Q4).** `DELETE /api/v1/admin/customers/{id}` handled by
`DeleteCustomerWithAnonymizationHandler` is the only delete-user path and is verified, not
re-invented: delete when the user has no orders, anonymize when they do, refuse when it would remove
the last active super admin (RG3.6). Its known gaps — order PII scrubbing of
`customer_name`/`customer_phone`/`shipping_address`, orphan-row cleanup
(`customer_address`, `storefront_cart`, `storefront_wishlist_item`,
`customer_password_reset_token`, quote requests) and in-flight-order blocking (RC11) — move to
backlog; no parallel delete endpoint is added to "fix" them here.

## Rejected alternatives

- New admin endpoints following the existing raw-SQL-in-controller style (rejected: it would make
  AGENTS.md 2/4/6, phpstan and deptrac optional for the newest code while constraining the oldest;
  cost delta is one handler + one finder per endpoint across every WP, i.e. a rewrite, and each
  direct write skips the transaction/outbox boundary CMD-005 and EVT-002 exist to guarantee).
- Refactoring the four legacy admin controllers to CQRS inside this spec (rejected: R26 forbids
  touching working product endpoints, the refactor is unrelated to the requested features, and it
  would expand this spec's diff and test surface far beyond its own requirements).
- A shared `AbstractAdminController` that executes SQL for both styles (rejected: a "helper" that
  both paths call is the raw-SQL path by another name and unenforceable by deptrac).
- CRUD commands (`UpdateCategory` with a nullable field bag) (rejected by CMD-001: invariants end up
  in the controller and the handler loses the business intent).
- Authorization in the controller for the admin reads (rejected by QRY-004/SEC-002: the edge check is
  the one that gets forgotten when a second caller appears).
- A second, hardened delete-user endpoint alongside the existing one (rejected: two delete paths on
  one account table is exactly the "mixed paths inside one spec is a defect" case of R25, and the
  dashboard would have to choose).
- Fixing the delete handler's PII/orphan/in-flight gaps in this spec (rejected: not in the prompt's
  words, escalated as Q4 and answered "keep as-is"; touching a working handler is the damaging
  direction, so it became backlog instead of an assumption).
