# Universal Plugin-First Update Policy

**Project:** Aloms English Center LMS  
**Applies from:** 2026-11-16  
**Policy status:** Mandatory architecture and release requirement

## Goal

Future functionality must be delivered as an independently manageable plugin whenever it can reasonably be isolated. This policy applies to every future feature request, enhancement, integration, report, automation, design module and business workflow—not only the plugins that exist today.

The objective is to keep the LMS Core stable while allowing administrators to add, configure, update, disable, uninstall, restore and, where supported, reset individual capabilities without editing application source code.

This policy describes the AEC Laravel plugin platform. It does not claim that a Laravel plugin ZIP can run unchanged on an unrelated WordPress, CodeIgniter or custom LMS. Cross-platform portability requires the separate host-adapter architecture in `PLUGIN-INDEPENDENCE-PORTABILITY-ROADMAP.md`.

## What belongs in Core

Core is deliberately small and should contain only capabilities required to host and secure the platform:

- Authentication primitives, authorization enforcement and secure session handling.
- Plugin discovery, manifest validation, dependency/capability resolution and lifecycle management.
- Versioned public contracts, event dispatch, extension registry and adapter interfaces.
- Database connection, safe filesystem boundaries, encryption primitives, audit logging and essential runtime services.
- Installer, migration orchestration, health checks, update verification and recovery mechanisms.
- Stable navigation/extension slots and minimal admin surfaces needed to manage plugins.

Do not add a course feature, marketing feature, payment provider, report, page builder, notification channel or other optional business capability to Core merely for convenience.

Core changes are permitted when a requirement is genuinely foundational, security-critical, or required to host all plugins. Such changes need a written rationale, backward-compatibility review and regression tests.

## What must be a plugin

Unless there is a documented technical reason otherwise, implement these as separate plugins:

- Academic levels, admissions, courses, batches, classes, lessons, exams and certificates.
- Student, teacher, guardian, attendance, progress and student tracking workflows.
- Payments, individual payment gateways, fees, subscriptions, commissions and finance extensions.
- Website pages, themes, sliders, popups, storefront, SEO, analytics and marketing pixels.
- SMS, email, messaging, notifications, live-class providers and external API integrations.
- Reports, exports, dashboards, AI assistants, automation, imports and data migration tools.
- New role-specific portals, custom fields, forms, widgets and optional business rules.

A plugin may provide a complete capability or a focused provider adapter. Avoid creating one giant plugin that combines unrelated features.

## Required plugin lifecycle

Every plugin must declare and support, as applicable:

1. Discover and validate its manifest.
2. Install and enable independently.
3. Configure through Admin Plugin Settings; encrypt secrets.
4. Upgrade through a versioned, signed package and forward-only migrations.
5. Disable without deleting user/business data.
6. Uninstall without silently deleting business records.
7. Restore from the recycle/backup workflow where supported.
8. Reset defaults without unexpectedly wiping operational data.
9. Report status, compatibility, required capabilities and optional integrations.
10. Provide health diagnostics and an actionable error when a required external service is unavailable.

All lifecycle actions must be authorized, audited and protected against partial failure. Destructive data purge must be a separate, explicit, confirmed action with backup guidance.

## Isolation and integration rules

- A plugin owns its tables, migrations, routes, views, permissions, settings, assets, translations and tests.
- A plugin must not edit another plugin's files or directly alter Core files during install/update.
- Required dependencies must be minimal and declared in the manifest. Prefer optional integrations and versioned contracts.
- Never rely on a sibling plugin being installed merely because it is present in the repository.
- Integrate through documented interfaces, events, capability discovery and adapters—not hidden cross-plugin imports or undocumented database joins.
- Missing optional integrations must degrade gracefully. A missing required capability must block the dependent action with a clear message.
- Events must be idempotent where retries are possible and must not leak secrets or sensitive personal data.
- Every route, job, command, webhook and data query must enforce authorization and tenant/branch scope.
- Provider credentials belong to that provider plugin's encrypted settings, never in source code or public assets.
- Avoid hard-coded provider assumptions; external services must be replaceable through adapters.

## Admin experience

The Plugin Center should remain the single control surface. For every plugin, an administrator should be able to:

- View description, version, compatibility, dependencies, status and permissions.
- Install, activate, deactivate, update and uninstall.
- Configure plugin settings and test supported connections.
- Reset documented defaults.
- Delete/restore through the recycle workflow when applicable.
- Review operation history, health and actionable errors.

Do not expose buttons for operations that the plugin cannot safely support. Explain destructive effects before confirmation.

## Quality gates for every new plugin or plugin update

A release is not accepted until it has:

- A valid manifest and schema validation.
- A clean-install test on a fresh database.
- Install/enable/disable/update/rollback/uninstall lifecycle tests as applicable.
- A standalone test with all optional sibling plugins absent.
- Dependency and compatibility tests.
- Permission, tenant/branch isolation and privacy tests.
- Validation, failure-path and external-service-unavailable tests.
- Migration upgrade tests from the previous supported version.
- No secrets, unsafe file paths, untrusted executable uploads or hidden destructive operations.
- Admin configuration and user-facing workflow tests.
- Documentation, version notes, checksums and signed release package.
- Full CI passing on supported PHP/database versions before merge.

A shared lifecycle test is not sufficient proof of a plugin's business functionality. Each plugin needs feature-specific acceptance evidence.

## How future requests will be implemented

For every future change, classify it before coding:

- **Plugin feature:** create or update an isolated plugin.
- **Provider integration:** create a separate provider plugin implementing the relevant contract.
- **Theme/presentation extension:** use a theme or UI plugin and documented extension slots.
- **Core capability:** change Core only when the requirement is foundational; explain why it cannot safely live in a plugin.
- **Existing plugin enhancement:** update that plugin with backward-compatible migrations and tests; do not move its feature into Core.

Before implementation, inspect the current plugin catalogue to avoid duplicates. After implementation, update the plugin manifest, Admin settings, translations, docs, acceptance matrix and release package. Run all required CI checks. Never merge or deploy a failing or unverified update.

## Exceptions

If a feature cannot be isolated because it changes a foundational security boundary, database transaction guarantee, authentication protocol or plugin runtime contract, document:

1. Why a plugin cannot safely provide it.
2. The smallest Core change required.
3. Backward-compatibility and migration impact.
4. Security review and regression coverage.
5. Whether the Core change creates new extension points so future business features can remain plugins.

Convenience, speed or fewer files are not valid reasons to bypass this policy.

## Current implementation caveat

The current platform has a Laravel plugin manager, plugin manifests, lifecycle actions, signed update mechanisms and module providers. The existence of those mechanisms does not automatically mean every current plugin has complete feature acceptance or that every lifecycle path has been proven on production cPanel. Continue to distinguish implemented code, automated CI evidence, staging rehearsal and production verification.
