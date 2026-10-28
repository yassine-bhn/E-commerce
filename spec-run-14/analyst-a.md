# Spec: Role model (admin, super admin, user, guest) and admin product approval workflow
Version: 1

Analyst: A (stance: literal — every requirement traces to the prompt's words; nothing is added beyond them.)

## Goal
The backend shall define admin, super-admin, user and guest (unauthenticated) principals, and a product added by an admin shall be stored in the backend in a state waiting for super-admin approval.

## Corrected prompt
When running many agents against the backend: (1) determine whether the backend already has roles for admin, super admin, user and guest user; (2) implement the admin/super-admin authorization logic so that when an admin adds a product, that product waits in the backend for super-admin approval.

## Requirements
| ID | Requirement | Type | Source in prompt | Priority |
|----|-------------|------|------------------|----------|
| R1 | The backend defines an admin role (`ROLE_ADMIN`) used to gate admin endpoints. | functional | "- now do i have role admin , super admin , user , guest user ?" | must |
| R2 | The backend defines a super-admin role (`ROLE_SUPER_ADMIN`) that includes admin privileges and approval authority. | functional | "- now do i have role admin , super admin , user , guest user ?" | must |
| R3 | The backend defines a user role (`ROLE_USER`) for authenticated customers. | functional | "- now do i have role admin , super admin , user , guest user ?" | must |
| R4 | The backend supports a guest (unauthenticated) principal for public endpoints without a persisted account or role. | functional | "- now do i have role admin , super admin , user , guest user ?" | must |
| R5 | When an admin who is not a super admin adds/creates a product, the backend persists it in a pending-for-super-admin-approval state. | functional | "- do i implement admin , super admin logic : admin add product it will be waiting for super admin approval in backend" | must |
| R6 | A product added by an admin is not automatically approved or published by that creation action. | constraint | "admin add product it will be waiting for super admin approval in backend" | must |
| R7 | Only a super admin can move an admin-added product from pending to approved. | constraint | "super admin approval in backend" | must |
| R8 | The pending-for-approval state of an admin-added product is enforced by the backend, not by the request payload or client. | constraint | "in backend" | must |
| R9 | The role and approval behaviour above works in the backend (server-side) without frontend involvement. | constraint | "(when i invoke this many agents should works in backend)" | must |

## Assumptions
| # | Assumption | Risk if wrong (low / med / high) |
|---|------------|----------------------------------|
| 1 | "Guest user" means an unauthenticated/public principal, not necessarily a persisted `ROLE_GUEST` role. | med |
| 2 | The admin product-creation endpoint already exists and is the "add product" action referred to. | low |
| 3 | The product approval workflow may already be partially implemented; this spec audits and completes gaps rather than mandating a rewrite. | med |
| 4 | Approval applies to products only; categories and other catalog entities are out of scope unless separately specified. | med |

## Open questions
- [minor] Should a guest be represented as a persisted role (`ROLE_GUEST`) or remain an unauthenticated principal? Default if unanswered: unauthenticated principal (no persisted role).
- [minor] Does admin product creation auto-create a pending submission, or is an explicit submit step required? Default if unanswered: auto-pending on creation, per "waiting for approval".
- [minor] Should categories also follow the same pending-approval rule? Default if unanswered: out of scope; products only.

## Out of scope
- Frontend or storefront changes.
- Categories, brands, banners and other catalog entities (product only, per the prompt).
- Payment, ordering, shipping and inventory workflows.
- Redefining the existing role hierarchy beyond what the four named roles require.
- Guest account creation or guest checkout behaviour.

## Work packages
| WP | Goal | Covers | Inputs | Deliverable | Owns | Depends on | Done when |
|----|------|--------|--------|-------------|------|------------|-----------|
| WP1 | Verify or implement the four named principal kinds (admin, super admin, user, guest) in the identity module. | R1, R2, R3, R4 | `src/Identity/`, `config/packages/security.yaml` | Roles/constants and access-control rules defining the four principals | `src/Identity/**`, `config/packages/security.yaml` | none | Admin/super-admin/user roles exist and guest access works on public endpoints; `/api/v1/admin` requires `ROLE_ADMIN`. |
| WP2 | Ensure an admin-added product is persisted pending super-admin approval in the catalog module. | R5, R6, R7, R8 | `src/Catalog/`, `ApprovalStatus` | Product creation by non-super admin stores a pending approval state enforced server-side | `src/Catalog/**` | none | A non-super-admin POST creates a product with pending approval, not approved/published; only super admin can approve. |
| WP3 | Backend tests covering roles and the admin product approval flow. | R9 | WP1, WP2 outputs, `tests/` | Tests for role gating and pending-approval behaviour | `tests/**` | WP1, WP2 | Relevant tests are executed and reported green. |
