## Feature: Super Admin Management and Approval Workflow for Products and Categories

### Objective
Implement a backend workflow in which regular Admins submit products and categories, and the Super Admin can perform full CRUD operations on those submissions before managing their approval lifecycle.

**Scope: Backend only. Do not modify frontend code.**

### 1. Regular Admin Responsibilities
Regular Admins can:
- Create products and categories.
- Submit newly created products and categories for Super Admin review.
- View the approval status of their submissions.
- Edit their pending or rejected submissions according to existing business rules.
- Resubmit rejected submissions after making corrections.
- View rejection reasons when provided.

New submissions must default to `PENDING_APPROVAL`, enforced by the backend.

Regular Admins must not be able to approve their own submissions or manipulate approval metadata through API payloads.

### 2. Super Admin CRUD Operations
When an Admin submits a product or category, the Super Admin must have full backend CRUD capabilities over that submission.

**Products:**
- **Create:** Create a product directly when necessary.
- **Read:** Retrieve complete product details, including pending, approved, rejected, and published records.
- **Update:** Modify product information submitted by an Admin before approval, including correcting errors or improving the submission.
- **Delete:** Delete a submitted product when necessary, subject to existing business constraints.

**Categories:**
- **Create:** Create a category directly when necessary.
- **Read:** Retrieve complete category details across all approval states.
- **Update:** Modify submitted category information before or during the approval process.
- **Delete:** Delete a submitted category when necessary, subject to existing business constraints and product dependencies.

All CRUD operations must be authorized on the backend. Existing domain rules, data integrity constraints, and referential integrity must be preserved.

### 3. Approval Lifecycle
After reviewing or modifying a submission, the Super Admin manages its approval lifecycle.

Supported approval states:

- `PENDING_APPROVAL`: Awaiting Super Admin review.
- `APPROVED`: Explicitly approved by the Super Admin.
- `REJECTED`: Rejected by the Super Admin.

Supported transitions:

- New Admin submission → `PENDING_APPROVAL`
- `PENDING_APPROVAL` → `APPROVED`
- `PENDING_APPROVAL` → `REJECTED`
- `REJECTED` → `PENDING_APPROVAL` after correction and resubmission.
- `APPROVED` → `PENDING_APPROVAL` if a subsequent modification requires reapproval under the established business rules.

The Super Admin must be able to approve or reject submissions after reviewing them and, when necessary, modifying their information.

**Important:** Performing CRUD operations must not automatically approve a product or category. Approval must remain an explicit action.

### 4. Separation of Approval and Publication
Keep approval status separate from publication status.

For example:
- An approved product may remain a draft until explicitly published.
- A pending or rejected product must never be publicly accessible.
- An approved category must not automatically publish its associated products.
- A product must not be publicly accessible if its category is unapproved or unpublished.

Enforce these restrictions across all relevant public API endpoints.

### 5. Authorization Rules
- Only a Super Admin can approve or reject products and categories.
- Only a Super Admin can perform privileged CRUD operations on submissions created by other Admins.
- Regular Admins cannot manipulate approval statuses, reviewer identities, or approval timestamps.
- The backend must validate every operation regardless of the request payload or endpoint used.
- Apply authorization consistently to individual resources, bulk operations, and alternative API operations where applicable.
- Prevent unauthorized users from accessing administrative data through public endpoints.

### 6. Audit Trail
Record significant actions, including:
- Submission creation and resubmission.
- Super Admin modifications.
- Approval and rejection decisions.
- Deletions.
- The acting user's identity and action timestamp.
- Rejection reasons when applicable.

Use the existing audit infrastructure if available.

### 7. Database and Business Logic
- Inspect the existing entities, repositories, services, controllers, API operations, and database schema.
- Reuse existing architecture and conventions.
- Introduce or extend approval fields only where necessary.
- Ensure server-controlled statuses cannot be overridden by regular Admins.
- Handle product-category relationships safely.
- Use transactions where required for consistency.
- Create safe migrations that preserve existing records and their current publication behavior where appropriate.

### 8. Automated Tests
Implement backend tests verifying:
- Admin product and category submissions.
- Super Admin CRUD operations on submissions created by other Admins.
- Unauthorized CRUD and approval attempts.
- Explicit approval and rejection.
- Editing submissions before approval.
- Reapproval rules for modified approved records.
- Rejection reasons and resubmission.
- Deletion constraints and product-category dependencies.
- Public API visibility restrictions.
- Attempts to bypass approval through alternative endpoints.
- Existing functionality and regression scenarios.

Execute the relevant tests and report the actual results.

### 9. Implementation Instructions
1. Analyze the existing backend before modifying anything.
2. Identify the affected entities, services, repositories, API operations, authorization rules, and tests.
3. Present a concise implementation plan.
4. Implement the backend changes using existing architectural patterns.
5. Add the necessary migrations and tests.
6. Run the relevant tests and available code-quality checks.
7. Report changed files, migrations, test results, and remaining limitations.

### Strict Constraints
- Backend only; do not modify frontend code.
- Do not introduce unrelated refactoring or unnecessary dependencies.
- Do not duplicate existing CRUD or approval functionality.
- Do not automatically approve submissions after CRUD operations.
- Do not trust client-provided approval metadata.
- Do not claim successful implementation or testing without evidence.
