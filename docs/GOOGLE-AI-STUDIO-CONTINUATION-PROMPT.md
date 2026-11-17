# Google AI Studio — AEC LMS continuation prompt

Use this prompt with the repository connected/open in Google AI Studio. Also add `GEMINI.md` to the model's context if the interface does not automatically read it.

You are continuing an existing production-oriented codebase, not starting a new project.

## First: understand the project
Read `GEMINI.md`, root `README.md`, `laravel/README.md`, and:
- `docs/RELEASE-READINESS-AUDIT.md`
- `docs/PLUGIN-ACCEPTANCE-MATRIX.md`
- `docs/PLUGIN-FEATURE-ACCEPTANCE-MATRIX.md`
- relevant module-specific docs and tests.

This repository is **Laravel 12 / PHP 8.3+**. The app lives in `/laravel`; its dependencies are in `laravel/composer.json` and `laravel/composer.lock`. Laravel is the sole production runtime.

## Critical: do not fix the wrong problem
Do not create a root `package.json` just because AI Studio displays “Dependency installation failed” or expects an npm project. Do not scaffold React/Vite/Next.js or replace the Laravel application. Do not move files. Do not add fake npm dependencies. AI Studio's Node preview is not the Laravel runtime. If the preview cannot run PHP, continue editing source files and clearly state that runtime preview/testing must happen in a PHP 8.3 + Composer + MySQL-compatible environment or GitHub Actions.

## Your mission
Independently identify and complete the highest-priority genuinely incomplete LMS tasks, in dependency order, without repeatedly asking the user for permission. Work one focused task at a time:
1. Inspect current repository state, branches, open PRs/issues and latest CI; do not rely on stale context.
2. Audit the relevant implementation end-to-end. A route, menu, manifest, form, or placeholder is not completion.
3. Define acceptance criteria and implement the real workflow across database, validation, authorization, branch scoping, services, UI, audit logs, and tests.
4. Add regression tests for success, validation failures, unauthorized access, cross-branch access, and failure/retry behavior as relevant.
5. Run available checks. If PHP/Composer are unavailable in AI Studio, do not pretend to have run Laravel tests; rely on GitHub Actions or request a PHP-capable test environment.
6. Review the diff for secrets, unsafe code, accidental data deletion, duplicate functionality, and unrelated changes.
7. Commit focused changes on a feature branch and open/update a pull request. Do not push directly to main, merge, deploy, or touch production data.
8. Continue to the next incomplete task and maintain a clear completion checklist.

## Product requirements
- Preserve the modular plugin architecture and optional plugin independence.
- Core RBAC, audit logs, installer, backup/restore, plugin lifecycle, and update integrity must not be bypassed.
- Enforce branch-level data isolation on all relevant reads/writes/exports/downloads/APIs.
- Payment gateways remain separate plugins. Never claim live integration without real provider sandbox tests, signed/idempotent callbacks, order/amount/currency checks and ledger reconciliation.
- Admin must be able to customize supported theme colors/design tokens through the UI without editing code; validate all tokens and never execute arbitrary HTML/JS/PHP.
- Protect student/guardian/teacher data, private uploads, receipts, backups, secrets and production configuration.
- Do not invent student statistics, business records, successful payments, or test results.

## Priority order
Use the current acceptance matrices and code audit to determine actual gaps. Prioritize security/data integrity first, then core LMS workflows (student/guardian/teacher, course/class/batch, attendance online/offline sync, exams/results, fees/ledger/payments), then CMS/design tools, reporting/notifications/mobile API, and operational release gates. Avoid reimplementing features already present.

## Final response
Give a concise Bangla progress report with changed files, real test evidence, commit/PR links, and remaining blockers. Clearly label work as not merged/not deployed unless it actually is.
