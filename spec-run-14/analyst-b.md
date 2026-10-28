# Spec: Role model and product approval gate in the backend

Version: 1

## Goal

The backend exposes a clear role model (admin, super admin, user, and guest as an unauthenticated shopper) and any product created by an admin enters the system as a pending submission that becomes visible to shoppers only after explicit super-admin approval.

## Corrected prompt

This is the backend of an e-commerce site. When many agents work on the backend, the following must hold:

- The backend must support the roles admin, super admin, user and guest user.
- Admin/super-admin logic must be implemented: when an admin adds a product, that product waits in the backend for super-admin approval before it is usable/published.

## Requirements

| ID | Requirement | Type | Source in prompt | Priority |
|----|-------------|------|------------------|----------|
| R1 | The backend defines and enforces the authenticated roles `ROLE_ADMIN`, `ROLE_SUPER_ADMIN` and `ROLE_USER`, with the hierarchy `ROLE_SUPER_ADMIN` ⊃ `ROLE_ADMIN` ⊃ `ROLE_USER`. | functional | "now do i have role admin , super admin , user , guest user ?" | must |
| R2 | A guest is not a persisted role: an unauthenticated request is treated as a guest and reaches only public endpoints, receiving the existing unauthenticated response (`401`) on any authenticated endpoint. | constraint | "guest user" in the role list | must |
| R3 | A customer profile stores exactly one role, and that role is one of `ROLE_USER`, `ROLE_ADMIN` or `ROLE_SUPER_ADMIN`; no `ROLE_GUEST` value is ever written. | constraint | "guest user" in the role list | should |
| R4 | A product created by a `ROLE_ADMIN` is persisted with approval status `PENDING_APPROVAL` and with the creating admin recorded as the submitter. | functional | "admin add product it will be waiting for super admin approval in backend" | must |
| R5 | A product in `PENDING_APPROVAL` is not returned by any public storefront endpoint (list, detail, search or reference data), even if its publication flag is set. | constraint | "waiting for super admin approval" | must |
| R6 | Only `ROLE_SUPER_ADMIN` can approve or reject a product submission; the corresponding endpoints are guarded by `ROLE_SUPER_ADMIN`. | constraint | "super admin approval" | must |
| R7 | A `ROLE_ADMIN` cannot approve, reject or publish a product submission, and cannot set approval metadata through the request payload. | constraint | "admin add product ... waiting for super admin approval" (approval is not the admin's) | must |
| R8 | Approval and publication stay separate: approving a product does not publish it, and publishing remains an explicit super-admin action. | functional | implied by "waiting for ... approval" before the product is usable | should |

## Assumptions

| # | Assumption | Risk if wrong (low / med / high) |
|---|------------|----------------------------------|
| 1 | "Guest user" means an unauthenticated shopper; introducing a persisted `ROLE_GUEST` would be a new auth concept not requested by the prompt. | med |
| 2 | The approval workflow implied by the prompt applies to products only, as the prompt names products and not categories. | med |
| 3 | The existing approval states (`PENDING_APPROVAL`, `APPROVED`, `REJECTED`) and the existing submission infrastructure are reused rather than redesigned. | low |
| 4 | "Admin" maps to `ROLE_ADMIN` and "super admin" maps to `ROLE_SUPER_ADMIN`, matching the existing security configuration. | low |
| 5 | A product created by a super admin may follow the existing reviewer-self-approval behaviour; the prompt only constrains products created by an admin. | med |
| 6 | Public visibility of a pending product is governed by the storefront read models and search index, not only by the admin read path. | med |
| 7 | No frontend, template or asset changes are in scope; this is backend only. | low |
| 8 | Audit-trail extensions are not required by the prompt and, if added, are treated as an enhancement rather than a blocker. | med |

## Open questions

- [minor] Should a persisted `ROLE_GUEST` be introduced, or is anonymous/public access sufficient? Default if unanswered: anonymous access only, no guest role.
- [minor] Should the approval gate also cover categories, or only products? Default if unanswered: products only, as named in the prompt.
- [minor] Should a super-admin-created product be auto-approved on creation, or follow the same pending flow? Default if unanswered: keep the existing reviewer-self-approval behaviour.
- [minor] Should a pending product be visible to its creating admin in admin reads only, or also in any shared admin list? Default if unanswered: owner and super admin only, as in the existing submission policy.

## Out of scope

- Frontend, templates, assets or UI changes.
- New authentication mechanisms, SSO, 2FA or invitation flows for creating admin accounts.
- Approval workflow for entities other than products, unless a later prompt extends it.
- Audit-trail, reporting or dashboard widgets beyond what already exists.
- Stock, shipping, checkout, payment or search-ranking changes unrelated to approval visibility.
- Physical image/file storage or image processing.

## Work packages

| WP | Goal | Covers | Inputs | Deliverable | Owns | Files or areas | Depends on | Done when |
|----|------|--------|--------|-------------|------|----------------|------------|-----------|
| WP1 | Verify and document the role model and hierarchy (`ROLE_USER`, `ROLE_ADMIN`, `ROLE_SUPER_ADMIN`) plus anonymous guest access. | R1, R2, R3 | security.yaml, CustomerUser, AssignableCustomerRole | Role matrix and tests confirming role inheritance and guest 401 behaviour | Identity context | `config/packages/security.yaml`, `src/Identity/Infrastructure/Security/CustomerUser.php`, `src/Identity/Domain/Model/AssignableCustomerRole.php` | none | Role hierarchy and anonymous-access tests pass |
| WP2 | Ensure admin-created products are persisted as pending submissions owned by their creator. | R4, R7 | Admin product create flow, ApprovalStatus | Product creation path setting `PENDING_APPROVAL` and `submitted_by` | Catalog context | `src/Catalog/Infrastructure/Http/AdminProductController.php`, `src/Catalog/Domain/Model/ApprovalStatus.php` | WP1 | Functional test shows a `ROLE_ADMIN` creation returns `PENDING_APPROVAL` with the creator recorded |
| WP3 | Enforce that pending products are invisible on every public storefront surface. | R5 | Storefront product read models, search index | Visibility guards in list/detail/search/reference paths | Catalog context | `src/Catalog/Infrastructure/ReadModel/*`, `src/Catalog/Infrastructure/Search/*`, `src/Catalog/Infrastructure/Http/StorefrontProductController.php` | WP2 | Public requests for a pending product slug return 404 and the slug is absent from public lists |
| WP4 | Restrict approval, rejection and publication to super admins and keep approval separate from publication. | R6, R7, R8 | Submission controllers, product publication endpoint | `ROLE_SUPER_ADMIN` guards and separate publication action | Catalog context | `src/Catalog/Infrastructure/Http/AdminCatalogSubmissionController.php`, `src/Catalog/Infrastructure/Http/AdminProductController.php` | WP1 | `ROLE_ADMIN` receives 403 on approve/reject/publish; approving does not set `published=true` |
| WP5 | Add or update backend tests covering roles, guest access, pending creation and approval gating. | R1-R8 | Existing functional/unit suites | Test coverage for the above requirements | Tests | `tests/Functional/AdminCatalogTest.php`, `tests/Functional/AuthenticationTest.php`, `tests/Unit/**` | WP2, WP3, WP4 | Relevant test suites pass and report results |