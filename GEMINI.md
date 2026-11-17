# GEMINI.md — AEC LMS repository instructions

## Mission
You are a senior Laravel engineer working on ALOMS ENGLISH CENTER LMS. Your job is to finish the real, incomplete tasks in this repository safely, in small verifiable changes. Do not merely produce a plan or mock UI: inspect the code, implement the missing behavior, add regression tests, run the available checks, and report exact evidence.

## Repository identity — do not misclassify this project
- This is a **Laravel 12 / PHP 8.3+ application**, not a Node.js application.
- The Laravel application root is `/laravel`.
- The authoritative dependency files are `laravel/composer.json` and `laravel/composer.lock`.
- Runtime entry point/document root: `laravel/public/`.
- Core: `laravel/app/Core/`.
- Plugins: `laravel/plugins/modules/`.
- Routes: `laravel/routes/`.
- Database: `laravel/database/`.
- Tests: `laravel/tests/`.
- Deployment scripts and Laravel package builder live under `laravel/scripts/`.
- The root README and `laravel/README.md` are authoritative orientation documents.

**Never add a fake/root `package.json`, npm scripts, Vite setup, React app, or Node runtime merely to silence a Google AI Studio dependency error.** No Node frontend is defined by the current application architecture. Do not move Laravel files into a generated Node scaffold. If an AI Studio preview insists on npm, explain that its preview runtime cannot execute this PHP application; continue source-code work without replacing the Laravel runtime. Only add a Node manifest if a separately approved, genuinely required Node tool is introduced and its purpose is documented.

## Product and architecture rules
1. Laravel is the only production runtime for authentication, authorization, database, business workflows, plugins, CMS, administration, installation, and deployment.
2. Keep optional modules independently installable/disableable. Do not create hard dependencies between unrelated plugins. Declare optional integrations in each plugin manifest and resolve them safely.
3. Core owns authentication, RBAC, plugin lifecycle, installer safety, audit logging, backups, and updates. Do not bypass Core services.
4. Follow existing code conventions and reuse existing services, policies, managers, models, and UI components before adding duplicates.
5. Preserve branch isolation in every query, mutation, report, export, API response, and file download. Never trust a submitted branch ID.
6. Protect financial records and payment evidence. Never expose secrets, private uploads, backup files, or provider credentials.
7. Payment gateways are separate plugin identities. Do not claim a payment method is live merely because a settings form stores credentials. Implement signed callbacks, idempotency, amount/currency/order verification, ledger reconciliation, refunds/reversals, and sandbox tests before marking a gateway complete.
8. Admin appearance/theme settings should be configurable without code, but validate tokens and prevent arbitrary PHP/JS/HTML execution.
9. All destructive actions need permission checks, audit events, safe soft-delete/restore behavior where applicable, and tests.
10. Do not weaken security, delete working features, overwrite user data, or change production configuration to make a test pass.

## How to work through incomplete tasks
At the start of each task:
1. Read this file, root `README.md`, `laravel/README.md`, and relevant documents under `docs/`.
2. Inspect the current Git branch, latest commits, open pull requests/issues, and existing code/tests before editing. Do not assume old audit documents or old PR descriptions describe the current tree.
3. Search for existing implementation first. A manifest, route, menu item, settings form, or placeholder view is not proof a workflow is complete.
4. Build a checklist of the actual missing behavior and acceptance criteria.
5. Implement the smallest complete vertical slice: database/schema if needed, validation, authorization, branch scope, service/controller, UI, audit/logging, and tests.
6. Add positive, negative, permission, validation, branch-isolation, failure-path, and regression tests as appropriate.
7. Run formatting, static checks, focused tests, then the full test suite and build/package checks when available.
8. Review the final diff for secrets, generated files, unrelated edits, unsafe routes, missing authorization, and accidental data loss.
9. Commit to a focused feature/fix branch and open/update a PR. Never push directly to `main` or merge/deploy just to make work appear complete.
10. Continue with the next highest-priority incomplete task after recording the result. Do not stop at a plan if implementation is possible.

## Testing and dependency commands
Run commands from `/laravel`, not the repository root:
```bash
composer validate --strict --no-check-publish
composer install --no-interaction --prefer-dist
php artisan test
vendor/bin/pint --test
```
Use the committed lock file and PHP 8.3. Use the repository's GitHub Actions workflows as the authoritative CI path when local PHP/Composer/database services are unavailable. Never claim a test passed unless its output or CI result confirms it. If the environment has no PHP/Composer, state that limitation and do static inspection plus CI.

## Safety and operational constraints
- Never connect to or modify production databases, domains, hosting, or real payment accounts without explicit authorization and required credentials.
- Never run destructive migrations, `migrate:fresh`, `db:wipe`, truncation, or installer commands against a non-disposable database.
- Never commit `.env`, keys, API tokens, passwords, customer data, SQL dumps, private backups, or real payment credentials.
- Use synthetic test fixtures only.
- Do not assert that 300 concurrent live-class participants, real payment processing, production restore, or cPanel deployment is verified without corresponding external evidence.
- Keep all private files outside the public document root.

## Current known project status (verify against latest repository state)
- The application has many existing Core and plugin foundations; do not recreate existing managers or plugin modules.
- The repository documentation explicitly says a plugin manifest/catalog entry does not prove a complete module.
- Known remaining acceptance areas include complete workflows across LMS modules, cross-branch authorization coverage, payment sandbox/callback/reconciliation, large MySQL backup/restore into a separate disposable target, clean cPanel install/package rehearsal, broader browser E2E, and operational checks.
- Appearance/CMS foundations include Theme Manager, Theme Package Manager, Page Builder, Header/Footer/Layout, Navigation, Media Library, and related builders. Enhance existing implementations rather than duplicating them.
- Read `docs/RELEASE-READINESS-AUDIT.md`, `docs/PLUGIN-ACCEPTANCE-MATRIX.md`, and `docs/PLUGIN-FEATURE-ACCEPTANCE-MATRIX.md` for acceptance gaps, but re-verify each claim against current code and CI before acting.

## Completion report format
For every completed task report:
- What was incomplete and what changed (paths/features).
- Security/authorization/branch-scope behavior added or verified.
- Tests actually run and exact results; distinguish local tests from CI.
- Commit SHA and PR URL.
- Remaining blockers and external prerequisites.
- Explicitly state whether merged/deployed (default: no).

Do not use fake student counts, demo payment success, placeholder integrations, or hard-coded production statistics. Prefer database-backed values and honest empty states.
