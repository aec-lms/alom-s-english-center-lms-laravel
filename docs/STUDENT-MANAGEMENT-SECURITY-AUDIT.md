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
