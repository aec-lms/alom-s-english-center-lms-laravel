# Student Management security and capability audit

**Audit date:** 2026-11-19  
**Audited baseline:** `main` at the time of review; focused branch changes are in PR #297.  
**Scope:** Existing `student-management` plugin only. This audit does not certify connected plugins or external providers.

## Existing capability inventory

| Area | Evidence / current behavior | Audit result |
|---|---|---|
| Student identity | `Student` model belongs to `User`; unique student code; admission date and lifecycle status | Present |
| Admission / manual registration | `StudentController::store` validates identity fields and creates user + student in a DB transaction | Present |
| Branch assignment | Nullable branch ID supports global/online learners; branch manager is required to have an active assigned branch | Present; edit UI gap addressed by PR #297 |
| Branch isolation | List is filtered for branch managers; update/delete verify the target student's branch; create/update reject a forged branch and force the manager's own branch | Server-side controls present; PR #297 adds regression coverage |
| Password lifecycle | Creation hashes password; focused PR adds optional password reset with blank meaning unchanged | Present; UI gap addressed by PR #297 |
| Guardian linkage | Optional `guardian_student` pivot is created when both plugins' tables exist | Schema integration present; full guardian workflows belong to Guardian Management |
| Attendance, fees, payment, referral, certificates, communications | No corresponding workflow/routes/models are implemented inside this plugin directory | Owned by existing adjacent modules; integration contracts require separate verification |
| Import/export and file handling | No import/export endpoints, upload handlers, or download endpoints are defined in this plugin's routes | Not implemented here; no plugin-local upload surface found |
| Archive / recycle bin | Student model uses soft deletes; delete deactivates the linked user; existing recycle-bin flow restores records | Present; restore path is owned by the existing recycle-bin integration |
| Auditability | Create/update/delete call the shared AuditLogger | Present, but update event currently records no field-level before/after diff |
| Query behavior | Student list eager-loads user and paginates 25 rows; branch filter is applied before pagination | Good baseline; no search/filter controls in current view |

## Risk-ranked findings

### P2 — Audit trail lacks change detail
The update event records the student identifier but not which fields changed. For admissions and branch reassignment, an operator investigating a disputed change cannot reconstruct the previous branch, status, email, or student code from this event alone.

**Recommended follow-up:** add a privacy-conscious before/after diff for a strict allowlist of non-secret fields (student code, branch ID, status); never log passwords, password hashes, tokens, or unnecessary contact data. Add tests for the event payload and secret exclusion.

### P2 — Student edit UI hid branch assignment
The baseline edit form submitted `branch_id` as a hidden field, preventing administrators from changing the student's branch through the UI. PR #297 replaces it with a branch selector populated from the controller's branch list and adds a password reset field.

**Verification:** PR #297 tests admin branch movement and synchronization of `students.branch_id` with `users.branch_id`, password hashing, branch-manager cross-branch edit/delete denial, forged branch creation denial, and forced own-branch assignment.

### P3 — Operational discovery controls are minimal
The list is paginated and avoids the user N+1 query, but the plugin view does not provide search/filter controls or an explicit empty-state recovery workflow beyond the existing recycle bin.

**Recommended follow-up:** add bounded search by student code/name/email and status/branch filters, retaining branch scoping before pagination. Avoid broad exports of personal data without a separately permissioned and audited workflow.

### P3 — Cross-plugin lifecycle contracts need end-to-end verification
Student records are linked to users and an optional guardian pivot; other lifecycle areas (attendance, finance, referrals, certificates, communications) are owned by separate plugins. This plugin-local audit cannot certify their behavior, especially around student soft-delete/restore, user deactivation/reactivation, and branch reassignment.

**Recommended follow-up:** add integration tests with the owning modules after their owners confirm the contract; do not create duplicate modules or modify their active PR files without coordination.

## Security controls observed

- Admin routes require authentication, plugin activation, and `students.manage` permission.
- Branch-manager access requires an active branch assignment and enabled Branch Management plugin.
- Branch filtering and target ownership checks are server-side; hidden form fields are not treated as authorization.
- Passwords are hashed through Laravel's Hash facade.
- User + student creation and updates are transactional.
- Student deletion is soft-delete based and deactivates the linked user.
- The list eager-loads the user relation and paginates results.

## Explicitly out of scope / not certified

- Teacher Management PR #293 and its files.
- Unified Commerce / payment / refund / ledger behavior in PR #275.
- Guardian, referral, attendance, fee, certificate, notification and communications modules beyond the Student Management integration points.
- Production data, provider credentials, staging callbacks, external backups, and deployment.
- A full privacy/legal assessment or production-readiness certification.

## Completion gates

- PR #297 exact-head CI must pass on SQLite and MySQL, Browser E2E, Composer audit, PHP lint, Blade compilation, and package checks.
- Independent review must be completed before merge.
- The P2 audit-log detail finding and P3 workflow/integration follow-ups remain tracked separately; this focused PR does not silently claim those are fixed.

## 2026-11-20 expanded risk-ranked Student Management audit

**Audit date:** 2026-11-20  
**Repository:** `aec-lms/alom-s-english-center-lms-laravel`  
**Issue:** [#296 — Student Management premium audit and hardening](https://github.com/aec-lms/alom-s-english-center-lms-laravel/issues/296)  
**Scope:** Existing Student Management plugin and its explicitly owned integration boundaries. This is a risk-ranked engineering audit, not a production-readiness certification.

## Executive summary

The Student Management plugin is intentionally a small student-profile and account-lifecycle module. It should not absorb admission, attendance, fees, payment, referral, guardian, certificate, notification, course enrollment, or recycle-bin responsibilities already owned by sibling plugins.

The current implementation work is split across the canonical Student Management PR #298 and related, separately owned lifecycle work. The PR adds transactional user/profile changes, role fail-closed behavior, allowlisted audit diffs, and branch checks. The audit identified additional normalization/collision cases that still need explicit regression coverage, plus cross-plugin lock-order and retention contracts that must be verified together.

**Current disposition:** Audit findings documented; several code fixes are in active PRs and are not considered complete until exact-head CI, review, and cross-plugin checks are recorded. No production records or credentials were accessed.

## Capability inventory

| Capability | Existing location / owner | Finding |
|---|---|---|
| Student profile | Student Management model/controller | Present: user link, branch, student code, admission date, status, timestamps and soft deletes |
| Student account provisioning | StudentController | Present; PR #298 makes missing canonical student role fail closed and keeps user/profile creation transactional |
| Branch assignment | StudentController + Branch Management | Present; branch-manager scope is enforced server-side; UI branch editing is handled separately |
| Password lifecycle | StudentController | Password hashing is present; blank optional update must remain a no-op |
| Guardian relationship | Optional `guardian_student` pivot when both plugins are enabled | Integration only; Guardian Portal/Management remains separately owned |
| Attendance | Attendance plugin | Separate module; Student Management must not duplicate it |
| Fees / payment / refunds | Fees and Payment Management + gateway plugins | Separate financial modules; cross-module identity/branch contracts require integration tests |
| Referral income | Referral Income plugin | Separate commission/payout ledger; do not copy referral logic into Student Management |
| Certificates / communications / notifications | Separate plugins | No duplicate implementation in Student Management |
| Import/export and student file handling | No Student Management-local routes identified in the audited surface | Any future feature needs explicit privacy, permission, branch scope and audit requirements; do not infer sibling-module acceptance |
| Archive / recycle bin | Existing Recycle Bin integration and Student soft deletes | Present; restore/permanent-delete lifecycle must share the account-before-profile lock contract |
| Audit logging | Shared AuditLogger | Create/delete events exist; PR #298 adds allowlisted update diffs inside the transaction |
| Directory queries | StudentController | Eager-loads user and paginates; search/filter and empty-state UX remain improvement opportunities |

## Risk-ranked findings and disposition

### P1 — User/profile lifecycle lock-order consistency

Student status and branch assignments are represented on both the linked User and Student profile. Join/heartbeat, profile update/delete, and recycle-bin restore must not acquire these rows in incompatible orders.

- PR #298 is the canonical Student Management writer and locks participating User rows in deterministic ID order before the Student profile.
- PR #312 is the Live Class join/heartbeat implementation and re-reads/locks the current User, Student profile, enrollment and entitlement within the protected transaction.
- PR #330 is the bounded Recycle Bin counterpart and changes restore to User-before-Student locking.

**Acceptance:** all three paths must use a compatible lock order and MySQL 8 two-connection regressions must prove revocation/restore/join serialization. A passing SQLite test alone is not proof of row-lock behavior. PR #312 and PR #298 remain subject to this combined verification.

### P2 — Missing canonical student role during provisioning

A missing `student` Role row must never result in a newly created User without the required role.

**Disposition:** addressed in PR #298 by locking and requiring the canonical role before account creation, with a regression asserting no orphan User/Student rows on failure. Keep this claim tied to the PR's exact reviewed head; do not infer it is already deployed.

### P2 — Email normalization before uniqueness validation

The current request path lowercases the email when persisting it, but the unique validation rule runs before normalization. SQLite's default unique comparison may allow a case-variant input that later normalizes to an existing stored address.

**Required fix:** normalize the request value before validation (or use an equivalent case-normalized uniqueness constraint) and test existing lowercase + submitted uppercase variants on SQLite and MySQL. This remains a follow-up review item for PR #298.

### P2 — Generated student-code collision retry

Generated `STU-` codes use a short random suffix. A unique index protects the database, but a single collision currently fails the provisioning request rather than retrying safely.

**Required fix:** use a bounded collision retry that relies on the unique index as the final arbiter; do not perform an unprotected exists-then-insert check as the only safeguard. Add a deterministic collision regression.

### P2 — Student profile/account status consistency

Student profile status and `users.is_active` are intentionally synchronized by StudentController. Recycle-bin restore deliberately does not reactivate the account. All lifecycle writers must preserve this policy and must not use a stale route-bound model as the authority for current access.

**Acceptance:** tests cover active/inactive/graduated transitions, soft delete, restore, and access denial while the account remains inactive.

### P2 — Hard-delete retention and cascade risk

The Student-to-User foreign key uses cascade deletion in the audited schema. A hard delete of a linked User outside the approved Student Recycle Bin retention/purge path can bypass the intended archive and audit lifecycle.

**Required follow-up:** inventory all User hard-delete paths. Either prevent deletion while a Student profile/history exists or require an explicit, permissioned, audited permanent-purge protocol that verifies dependent records and retention policy. Do not silently change the foreign key without a migration/retention plan.

### P2 — Optional Guardian relationship lifecycle

The guardian pivot is optional and depends on both Student Management and Guardian Portal being present. Verify either plugin's install/disable/uninstall/reinstall order, student soft-delete/restore/permanent-purge behavior, and branch scoping in Guardian Portal. Do not make Guardian Portal a hard dependency solely to simplify this integration.

### P3 — Directory search, filters and empty states

The list already eager-loads the User relation and paginates. Search by student code/name/email and filters by status/branch are not part of the current baseline. If added, enforce branch scope before pagination and avoid leaking personal data through broad exports.

## Required cross-module acceptance matrix

| Workflow | Canonical owner / related work | Required evidence |
|---|---|---|
| Student create/update/delete | Student Management PR #298 | SQLite + MySQL, role missing, case-normalized email, generated-code collision, branch authorization, audit privacy |
| Recycle-bin restore/purge | PR #330 | MySQL lock ordering, restore remains inactive, retained history, safe permanent purge |
| Live-class join/heartbeat | PR #312 / Issue #309 | MySQL two-connection revoke-vs-join and revoke-vs-heartbeat; leave remains available |
| Protected lesson media | PR #338 / Issue #313 | Role/account/profile/enrollment rechecked at stream and download time |
| Course assessment role and grading | PR #318 / #319 | Role revocation and teacher branch isolation, including stale/corrupt assignment rows |
| Referral attribution and payout | Issues #301, PRs #332/#339 | Verified settlement, idempotency, payout concurrency, refund evidence and branch-scoped finance |
| Payment checkout/refunds | Issue #302 / PR #326; Issue #303 remains separate | Invoice reservation concurrency; provider-confirmed refund state machine and reconciliation |
| Guardian access | Guardian Portal owner | Optional dependency lifecycle and guardian/student branch isolation |

## Security controls observed

- Admin routes are expected to require authentication, plugin activation and the relevant `students.manage` permission.
- Branch-manager operations require an active assigned branch and are checked server-side.
- User passwords are hashed through Laravel's Hash facade.
- User/profile provisioning and update paths are transactional.
- Student deletion is soft-delete based and deactivates the linked account.
- Student listing eager-loads the User relation and paginates results.
- Audit payloads must remain allowlisted; passwords, hashes, tokens and submitted request payloads must never be recorded.

## Explicit non-claims / boundaries

- This report does not certify Teacher Management PR #293 or modify its files.
- It does not certify payment/refund, referral, Guardian Portal, attendance, enrollment, certificate, or communication workflows beyond the integration contracts listed above.
- It does not claim import/export or file-upload/download features exist in Student Management.
- It does not certify production hosting, cPanel, external providers, backups, or deployment.
- No production data, live payment, or production database operation was used.

## Completion gates for Issue #296

1. Resolve the remaining email-normalization and generated-code-collision regressions in the canonical Student Management owner PR.
2. Verify the combined User/Profile lock order across PRs #298, #312 and #330 on MySQL 8.
3. Complete the cross-plugin integration checks listed above without duplicating another owner's files.
4. Run exact-final-head SQLite, MySQL 8, Browser E2E, Composer Security Audit, PHP lint, Blade compilation and release-package checks for each changed PR.
5. Obtain independent security review and reconcile the central ownership register.
6. Keep Issue #296 open until the acceptance evidence is complete; this audit document alone does not close the implementation work.
