# Spec: Role model and admin product approval in the backend

Version: 1

Analyst: C (stance: skeptic).

## Goal
Determine whether the backend already distinguishes admin, super admin, user and guest principals, and ensure that any product added by an admin is retained in the backend in a state awaiting super-admin approval, with all such behaviour enforced server-side.

## Corrected prompt
When running many agents against the backend: (1) determine whether the backend already has roles for admin, super admin, user and guest user; (2) implement the admin/super-admin authorization logic so that when an admin adds a product, that product waits in the backend for super-admin approval.

## Requirements
| ID | Requirement | Type | Source in prompt | Priority |
|----|-------------|------|------------------|----------|
| R1 | The backend's authorization model accounts for the four named principal kinds: admin, super admin, user, and guest. | functional | "- now do i have role admin , super admin , user , guest user ?" | must |
| R2 | The role identifiers for admin and super admin are distinct in backend security configuration (`ROLE_ADMIN` vs `ROLE_SUPER_ADMIN`). | functional | "- now do i have role admin , super admin , user , guest user ?" | must |
| R3 | Guest (unauthenticated) access to backend endpoints is handled by the backend itself, without frontend involvement. | constraint | "(when i invoke this many agents should works in backend)" and "guest user" | must |
| R4 | When an admin who is not a super admin creates a product through the backend admin product-creation path, the product is persisted in a pending-for-super-admin-approval state. | functional | "- do i implement admin , super admin logic : admin add product it will be waiting for super admin approval in backend" | must |
| R5 | The pending-for-approval state of an admin-created product is set by backend logic and cannot be overridden by request payload or client input. | constraint | "in backend" | must |
| R6 | Creating a product as an admin does not by itself approve or publish that product. | constraint | "admin add product it will be waiting for super admin approval" | must |
| R7 | Only a super admin can move an admin-created pending product to an approved state. | constraint | "super admin approval in backend" | must |
| R8 | Role checks and approval checks for the above are enforced server-side for backend operations. | constraint | "(when i invoke this many agents should works in backend)" | must |

## Assumptions
| # | Assumption | Risk if wrong (low / med / high) |
|---|------------|----------------------------------|
| 1 | "Guest user" means an unauthenticated/public principal, not necessarily a persisted `ROLE_GUEST` role. | med |
| 2 | "Admin" maps to the existing `ROLE_ADMIN` and "super admin" to `ROLE_SUPER_ADMIN` in the security configuration. | low |
| 3 | "Add product" refers to the existing admin product-creation endpoint, not to the `RegisterProduct` domain command, fixtures, or seed data. | med |
| 4 | The approval requirement applies to products only; categories and other catalog entities are not implied by the prompt. | med |
| 5 | The backend may already implement part or all of this workflow; this spec verifies and fills gaps rather than mandating a rewrite. | med |
| 6 | The prompt requires a backend approval state; it does not by itself require changes to public/storefront visibility, search indexing, or publication behaviour. | med |
| 7 | No frontend or storefront changes are in scope. | low |

## Open questions
- [BLOCKING] Should "guest user" be a persisted role (`ROLE_GUEST`) or remain an unauthenticated/public principal? Default if unanswered: unauthenticated public principal, no persisted guest role.
- [BLOCKING] Is the task to audit/verify the existing backend implementation or to implement the workflow from scratch? Default if unanswered: audit first, then implement only identified gaps.
- [minor] Does `ROLE_ADMIN` need to be assignable/creatable as a persisted account role, or is it only an inherited security level below `ROLE_SUPER_ADMIN`? Default if unanswered: no change to existing assignability.
- [minor] Does "add product" also cover the `RegisterProduct` command and fixture/seed paths, or only the admin HTTP endpoint? Default if unanswered: admin HTTP endpoint only.
- [minor] Does pending approval also require hiding the product from public/storefront endpoints? Default if unanswered: not required by this prompt; specify separately if desired.
- [minor] Should a product created by a super admin also wait for approval, or keep any existing reviewer/self-approval behaviour? Default if unanswered: keep existing behaviour.
- [minor] Is approval limited to products, or should categories follow the same rule? Default if unanswered: products only.
- [minor] Must the creating admin's identity be recorded on the pending product? Default if unanswered: record it only where the existing schema already supports it; not required by the prompt's wording.

## Out of scope
- Frontend, templates, assets or storefront client changes.
- Categories, brands, banners, promotions, orders, payments, shipping, inventory and other non-product entities.
- Guest account creation, guest checkout or guest-specific permissions beyond public access.
- Redefining the existing role hierarchy beyond the four named principal kinds.
- Public visibility, search indexing, counts or publication behaviour unless separately specified.
- Audit-trail extensions beyond what already exists.

## Work packages
| WP | Goal | Covers | Inputs | Deliverable | Owns | Depends on | Done when |
|----|------|--------|--------|-------------|------|------------|-----------|
| WP1 | Audit and document the four principal kinds and their backend enforcement. | R1, R2, R3 | `config/packages/security.yaml`, `src/Identity/Infrastructure/Security/CustomerUser.php`, `src/Identity/Domain/Model/AssignableCustomerRole.php` | Role matrix and tests confirming principal kinds and guest/unauthenticated behaviour | `config/packages/security.yaml`, `src/Identity/Infrastructure/Security/**`, `src/Identity/Domain/Model/AssignableCustomerRole.php` | none | Role kinds are documented and access-control tests pass for admin, super admin, user and guest. |
| WP2 | Audit or implement pending approval for admin-created products. | R4, R5, R6, R7 | `src/Catalog/Infrastructure/Http/AdminProductController.php`, `src/Catalog/Domain/Model/ApprovalStatus.php` | Product creation path persists pending approval for non-super admins, enforced server-side | `src/Catalog/Infrastructure/Http/AdminProductController.php`, `src/Catalog/Domain/Model/ApprovalStatus.php` | none | An admin creation persists a pending, non-approved product; only super admin can approve. |
| WP3 | Backend tests for roles and admin product approval. | R3, R4, R5, R6, R7, R8 | WP1 and WP2 outputs, `tests/` | Tests covering principal kinds, guest access, pending creation and approval gating | `tests/**` | WP1, WP2 | Relevant tests are executed and their results reported. |
| WP4 | Backend-only verification and reporting. | R8 | WP3 output | Verification report of changed files and test results | `tests/**` (read-only verification) | WP3 | Report states which requirements pass, fail or remain open. |