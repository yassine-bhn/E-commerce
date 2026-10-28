# Spec: Role model (admin, super admin, user, guest) and admin product approval in the backend

Version: 1

## Goal
The backend shall expose admin, super-admin, user and guest (unauthenticated) principals, and any product created by an admin shall be stored server-side in a state pending super-admin approval, with that state and all role checks enforced by the backend.

## Corrected prompt
When running many agents against the backend: (1) determine whether the backend already has roles for admin, super admin, user and guest user; (2) implement the admin/super-admin authorization logic so that when an admin adds a product, that product waits in the backend for super admin approval.

## Requirements
| ID | Requirement | Type | Source in prompt | Priority |
|----|-------------|------|------------------|----------|
| R1 | The backend defines the authenticated roles `ROLE_ADMIN`, `ROLE_SUPER_ADMIN` and `ROLE_USER` used to gate backend endpoints. | functional | "- now do i have role admin , super admin , user , guest user ?" | must |
| R2 | The role hierarchy grants `ROLE_SUPER_ADMIN` the privileges of `ROLE_ADMIN`, and `ROLE_ADMIN` the privileges of `ROLE_USER`. | functional | "- now do i have role admin , super admin , user , guest user ?" | must |
| R3 | A guest is an unauthenticated principal handled by the backend: no `ROLE_GUEST` is persisted, unauthenticated requests reach only public endpoints, and authenticated endpoints return the unauthenticated response (`401`) to them. | constraint | "- now do i have role admin , super admin , user , guest user ?" and "(when i invoke this many agents should works in backend)" | must |
| R4 | When a non-super-admin admin creates a product through the backend admin product-creation path, the product is persisted with approval status `PENDING_APPROVAL`. | functional | "- do i implement admin , super admin logic : admin add product it will be waiting for super admin approval in backend" | must |
| R5 | A product created by an admin is not automatically approved or published by that creation action. | constraint | "admin add product it will be waiting for super admin approval in backend" | must |
| R6 | Only a super admin can move an admin-created pending product to an approved state; the approval action is guarded by `ROLE_SUPER_ADMIN`. | constraint | "super admin approval in backend" | must |
| R7 | The pending-for-approval state and any approval metadata of an admin-created product are set by backend logic and cannot be overridden through the request payload or client input. | constraint | "in backend" | must |
| R8 | All role checks and approval checks for the above are enforced server-side, without frontend involvement. | constraint | "(when i invoke this many agents should works in backend)" | must |

## Assumptions
| # | Assumption | Risk if wrong (low / med / high) |
|---|------------|----------------------------------|
| 1 | "Guest user" means an unauthenticated/public principal, not a persisted `ROLE_GUEST` role. | med |
| 2 | "Admin" maps to the existing `ROLE_ADMIN` and "super admin" to `ROLE_SUPER_ADMIN` in the security configuration. | low |
| 3 | "Add product" refers to the existing admin product-creation HTTP endpoint, not to the `RegisterProduct` command, fixtures or seed data. | med |
| 4 | The approval requirement applies to products only; categories and other catalog entities are not implied by the prompt. | med |
| 5 | The backend may already implement part or all of this workflow; this spec verifies and fills gaps rather than mandating a rewrite. | med |
| 6 | A persisted customer profile stores one of `ROLE_USER`, `ROLE_ADMIN` or `ROLE_SUPER_ADMIN`; guest is never persisted as a role. | low |
| 7 | Recording the creating admin as submitter is retained where the existing schema already supports it; it is not newly required by the prompt. | low |
| 8 | Super-admin-created products keep any existing reviewer/self-approval behaviour; the prompt only constrains products created by an admin. | med |
| 9 | No frontend, template, asset or storefront changes are in scope; this is backend only. | low |
| 10 | Existing rejection, resubmission and publication behaviour is left unchanged unless separately specified. | med |

## Open questions
- [minor] Should a guest be represented as a persisted `ROLE_GUEST` or remain an unauthenticated principal? Default if unanswered: unauthenticated principal, no persisted guest role.
- [minor] Is the task to audit the existing backend implementation or to implement the workflow from scratch? Default if unanswered: audit first, then implement only identified gaps.
- [minor] Does `ROLE_ADMIN` need to be assignable/creatable as a persisted account role, or is it only an inherited security level below `ROLE_SUPER_ADMIN`? Default if unanswered: no change to existing assignability.
- [minor] Does "add product" also cover the `RegisterProduct` command and fixture/seed paths, or only the admin HTTP endpoint? Default if unanswered: admin HTTP endpoint only.
- [minor] Does pending approval also require hiding the product from public/storefront endpoints? Default if unanswered: not required by this prompt; specify separately if desired.
- [minor] Should a product created by a super admin also wait for approval, or keep existing reviewer/self-approval behaviour? Default if unanswered: keep existing behaviour.
- [minor] Must the creating admin's identity be recorded on the pending product? Default if unanswered: record it only where the existing schema already supports it.

## Out of scope
- Frontend, templates, assets or storefront client changes.
- Categories, brands, banners, promotions, orders, payments, shipping, inventory and other non-product entities.
- Guest account creation, guest checkout or guest-specific permissions beyond public access.
- Redefining the existing role hierarchy beyond the four named principal kinds.
- Public visibility, search indexing, counts or publication behaviour unless separately specified.
- Audit-trail extensions beyond what already exists.
- Changes to rejection, resubmission or publication flows beyond ensuring admin-created products start pending.

## Work packages
| WP | Goal | Covers | Inputs | Deliverable | Owns | Depends on | Done when |
|----|------|--------|--------|-------------|------|------------|-----------|
| WP1 | Verify or implement the four named principal kinds (admin, super admin, user, guest) and their backend enforcement in the identity module. | R1, R2, R3, R8 | `config/packages/security.yaml`, `src/Identity/` | Role hierarchy, access-control rules and tests for admin, super admin, user and guest principals | `config/packages/security.yaml`, `src/Identity/Infrastructure/Security/**`, `src/Identity/Domain/Model/AssignableCustomerRole.php` | none | Role kinds exist with the stated hierarchy, guest/unauthenticated access behaves as specified, and access-control tests pass. |
| WP2 | Ensure an admin-created product is persisted pending super-admin approval in the catalog module, enforced server-side. | R4, R5, R7, R8 | `src/Catalog/Infrastructure/Http/AdminProductController.php`, `src/Catalog/Domain/Model/ApprovalStatus.php` | Product creation path that persists `PENDING_APPROVAL` and cannot be overridden by payload or client | `src/Catalog/Infrastructure/Http/AdminProductController.php`, `src/Catalog/Domain/Model/ApprovalStatus.php` | WP1 | A non-super-admin creation persists a pending, non-approved, non-published product; approval state is set only by backend logic. |
| WP3 | Restrict approval of an admin-created pending product to super admins, enforced server-side. | R6, R7, R8 | `src/Catalog/Infrastructure/Http/AdminCatalogSubmissionController.php` | `ROLE_SUPER_ADMIN` guard on the approval action and server-side enforcement | `src/Catalog/Infrastructure/Http/AdminCatalogSubmissionController.php` | WP1 | `ROLE_ADMIN` receives 403 on approve; only `ROLE_SUPER_ADMIN` can transition a pending product to approved. |
| WP4 | Backend tests covering roles, guest access and the admin product approval flow. | R1, R2, R3, R4, R5, R6, R7, R8 | WP1, WP2, WP3 outputs, `tests/` | Tests for principal kinds, guest access, pending creation and approval gating | `tests/**` | WP1, WP2, WP3 | Relevant test suites are executed and reported green. |

## Decisions (synthesizer only)
| Topic | A | B | C | Chosen | Why |
|-------|---|---|---|--------|-----|
| Guest principal representation | Unauthenticated principal (minor question) | Unauthenticated principal, 401 on authenticated endpoints (minor question) | Unauthenticated principal (blocking question) | Unauthenticated principal, no persisted `ROLE_GUEST` | Matches existing security model and avoids a new auth concept; less scope creep. |
| Role hierarchy | Super admin includes admin privileges | Full hierarchy `ROLE_SUPER_ADMIN` ⊃ `ROLE_ADMIN` ⊃ `ROLE_USER` | Only distinct identifiers required | Full hierarchy as configured in `security.yaml` | Existing configuration and the natural meaning of "super admin" support it; low risk. |
| Audit vs implement from scratch | Audit and complete gaps | Audit and complete gaps | Audit first, then implement gaps (blocking) | Audit first, then implement gaps | Prompt asks "do i have ... ?" before "do i implement ..."; avoids destructive rewrites. |
| Scope of "add product" | Assumes existing admin endpoint | Admin product create flow | Admin HTTP endpoint only (open question) | Existing admin HTTP endpoint only | Prompt says "admin add product"; command/fixture paths are out of scope per default. |
| Persisted submitter recording | Silent | Required (`submitted_by`) | Open question, default retain existing | Retain existing where supported; not a new requirement | Not stated in the prompt; avoids scope creep. |
| Public storefront visibility of pending products | Silent | Required (hidden from storefront) | Out of scope unless specified | Out of scope unless separately specified | Prompt only requires backend state; visibility is a separate concern. |
| Approval vs publication separation | Silent | Should (separate) | Out of scope | Out of scope / existing behaviour unchanged | Publication is not mentioned in the prompt. |
| Super-admin-created product behaviour | Silent | Keep existing self-approval | Keep existing behaviour | Keep existing reviewer/self-approval behaviour | Prompt only constrains products created by an admin. |
| Categories scope | Products only | Products only | Products only | Products only | All analysts agree; prompt names products. |

## Changelog (synthesizer and orchestrator only)
- v1: created