# Plugin Independence & Cross-LMS Portability Roadmap

**Project:** Aloms English Center LMS  
**Reviewed ref:** `chore/release-audit-and-plugin-matrix`  
**Review date:** 2026-11-16  
**Status:** Dependency-manifest inventory completed; framework-neutral portability is not yet implemented.

## Acceptance requirement

Every plugin should be installable, enabled, upgraded, disabled and removed independently. A plugin must not require an unrelated sibling plugin merely because both are part of this LMS. Optional integrations must degrade safely when the related module is absent.

The stronger requirement—running the same plugin package on an arbitrary, non-Laravel LMS—cannot be claimed by the current implementation. The modules use Laravel service providers, routes, middleware, Eloquent, Laravel migrations, Blade views and shared AEC LMS runtime services. Cross-LMS portability therefore requires a framework-neutral plugin core plus host-specific adapters. This roadmap treats that as an explicit architecture project, not a manifest-only change.

## Manifest dependency inventory

The 39 plugin manifests were checked individually on the reviewed branch. All 39 declare an empty required-sibling `dependencies` list; the following 20 declare optional sibling integrations. A source-level PHP namespace scan now checks that direct sibling references are declared as required or optional integrations. This prevents hidden undeclared references, but runtime standalone behavior still requires plugin-by-plugin acceptance tests:

| Plugin | Optional integrations | Portability concern |
|---|---|---|
| admission-management | branch-management | Admission should support an optional branch context and a default/global mode. |
| attendance-management | branch-management, student-management, course-management, batch-management | Attendance needs stable student/course/batch interfaces; integrations should be adapters, not hard-coded model imports. |
| batch-management | branch-management | Batch ownership should be optional; global batches must remain available without branches. |
| class-management | branch-management | Class scheduling should work in global mode without branch context. |
| certificate-management | student-management, branch-management | Certificate issuance should accept a verified learner identity through a contract. |
| course-management | branch-management, student-management, fees-management | Course catalogs should support a global/default organization context; learner enrollment and invoicing remain optional adapters. |
| exam-management | course-management, branch-management | Exams should use optional course/branch adapters and remain usable in standalone mode where supported. |
| gateway-paypal | payment-management, fees-management, student-management | Gateway should implement a payment-provider contract and resolve invoice/learner adapters only when enabled. |
| gateway-stripe | payment-management, fees-management, student-management | Gateway should implement a payment-provider contract and resolve invoice/learner adapters only when enabled. |
| gateway-sslcommerz | fees-management, payment-management, student-management | Hosted checkout should use the same invoice/provider contracts and keep credentials in its own settings. |
| hr-staff-management | branch-management | Branch context should be optional and resolved through a branch adapter. |
| library-management | branch-management | Library should work without multi-branch support. |
| notification-center | email-notifications, sms-gateway, branch-management | Email delivery should be an optional channel adapter, not a hard requirement for in-app notifications. |
| student-management | branch-management | Student records should support a nullable/default organization context. |
| teacher-management | branch-management | Teacher records should support a nullable/default organization context. |
| reports-analytics | branch-management | Reports should degrade to global scope without branch integration. |
| notice-board | branch-management | Notices should support global audience when branch integration is absent. |
| universal-payment | payment-management, fees-management, branch-management, gateway-paypal, gateway-sslcommerz, gateway-stripe | Payment orchestration should depend on invoice/payment/provider contracts, not sibling Eloquent models. |

Additional plugins also declare optional integrations: `guardian-management` → `student-management, branch-management`, `fees-management` → `student-management, branch-management`, and `payment-management` → `fees-management, branch-management`. `universal-payment`, `notification-center`, and `certificate-management` also include branch context in their optional integration lists. The remaining 19 manifests declare no optional sibling integration. This does **not** prove they are independent: source files may still import sibling models, tables, routes or services without declaring those dependencies. Source-level import and runtime dependency scans remain required for all 39 modules.

## Target architecture

1. **Framework-neutral plugin manifest:** stable ID, semantic version, permissions, capabilities, migration version, optional integrations and compatibility constraints. No Laravel class names in the portable manifest.
2. **Plugin core contracts:** define versioned interfaces for identity, authorization, settings, storage, events, logging, queues, payments, invoices, learners, courses, batches, branches, notifications and file uploads.
3. **Host adapters:** keep Laravel implementation under an adapter layer. A future WordPress/CodeIgniter/custom-LMS host must provide its own adapter implementing the same contracts.
4. **Optional integrations:** replace hard sibling dependencies with capability discovery and adapters. If a capability is missing, hide/disable only the integration-dependent action and preserve standalone plugin features.
5. **Events over direct calls:** use documented events such as `learner.created`, `invoice.paid`, `course.enrolled`, `class.attendance.recorded` and `notification.requested`. Consumers must be idempotent and tolerate absent subscribers.
6. **Data ownership:** each plugin owns its tables and migrations. Cross-plugin references use stable public IDs/contracts, not direct joins into another module's private tables unless an explicit adapter owns that mapping.
7. **Lifecycle isolation:** install/enable/disable/upgrade/uninstall each plugin independently; dependency checks must distinguish required host capabilities from optional integrations. Failure must roll back only the plugin operation.
8. **Security boundary:** every adapter must enforce tenant/branch scope, authorization and data minimization. The absence of a branch adapter must not silently broaden a query.
9. **Package format:** publish each plugin as a signed/versioned package with manifest schema, checksums, migration scripts, translations, assets, compatibility metadata and a self-test suite.
10. **Compatibility contract:** publish an API/contract version and automated compatibility tests so a plugin can be tested against more than one host adapter.

## Required remediation sequence

- [ ] Build a machine-readable manifest schema and validator.
- [ ] Scan all PHP files for direct sibling namespaces, Eloquent model imports, table names, route names and service-container bindings; compare findings with each manifest.
- [ ] Classify every dependency as required core capability, optional integration, or accidental coupling.
- [ ] Add contracts for identity, authorization, settings, files, events, queues and audit logging first.
- [ ] Refactor notification, payment, invoice, learner, course, batch and branch integrations behind adapters.
- [ ] Add a standalone mode and missing-adapter tests for every plugin.
- [ ] Add install/enable/disable/upgrade/rollback tests per plugin.
- [ ] Add contract tests for the Laravel adapter and at least one second host adapter before claiming cross-LMS portability.
- [ ] Publish one plugin package and test install/upgrade/uninstall on a clean host before scaling the package process to all 39.

## Release policy

Until the second host adapter and cross-host contract suite exist, describe these modules as **AEC LMS Laravel plugins**, not universal plugins compatible with every LMS. All 39 manifests currently have no required sibling-plugin dependencies. Twenty manifests declare optional integrations. This permits the lifecycle manager to install each plugin without first installing sibling plugins, but it does not prove every workflow gracefully degrades when an optional module is absent. The source-level audit and standalone feature tests remain mandatory. Do not remove dependencies from manifests until the corresponding source imports and workflows have been replaced by tested adapters.
