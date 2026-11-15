# GitHub Transfer & Portable Release Guide

This document explains how to move the Alom's English Center LMS to another GitHub account without losing the work currently integrated in the release branch.

## Source branch and status

- Integration source: `release/portable-integration`
- Created from: `feature/recurring-expense-requests`
- This branch includes the dependent feature chain through recurring expense requests (migrations 001–034).
- It is a **consolidation/work branch, not a production-approved release**. Hosted CI runner allocation and the clean cPanel rehearsal are still release gates.
- Do not transfer only `main`: the latest Library, Hostel/Transport, Expense, Commission, Accounting, Marksheets and Private Media changes are on dependent feature branches.

## Preferred transfer: preserve Git history and branches

From a computer with Git installed:

```bash
git clone --mirror https://github.com/Alomsenglishcenter/Aloms-Lms.git
cd Aloms-Lms.git
git push --mirror https://github.com/NEW-OWNER/NEW-REPOSITORY.git
```

Replace `NEW-OWNER/NEW-REPOSITORY` with the destination repository. Create the destination as an empty repository first (do not initialize it with a README, license or .gitignore). A mirror push transfers Git refs/branches and history; it does not transfer GitHub Actions secrets, repository variables, environments, billing configuration, collaborators, branch protection, issues, pull requests, or deployment settings. Recreate those separately in the destination account.

After transfer, check out the consolidated integration branch:

```bash
git clone https://github.com/NEW-OWNER/NEW-REPOSITORY.git
cd NEW-REPOSITORY
git checkout release/portable-integration
```

## Alternative: upload a ZIP as a fresh starting point

1. Open the source repository and select the `release/portable-integration` branch.
2. Use **Code → Download ZIP** for that branch.
3. Extract the ZIP locally.
4. Create an empty destination repository.
5. Upload the extracted source files (including hidden files such as `.github`, `.htaccess`, `.env.example`, and `.gitignore`).
6. Commit the files to the destination repository's `main` branch.

A ZIP transfer contains the source snapshot only. It does not contain Git history, other branches, pull requests, issues, GitHub settings, Actions secrets, or workflow run history. Do not upload `.env`, database dumps, production user uploads, passwords, API keys, or `node_modules`.

## Required destination setup

- Set the default branch to the intended release branch (or keep the uploaded snapshot on `main`).
- Enable GitHub Actions and review workflow permissions.
- Recreate required repository secrets/variables only if a workflow actually needs them. Never copy secrets into source files.
- Confirm the workflow can allocate runners; check the destination account's Actions usage and spending limit.
- Protect the default branch and require the PHP, MySQL integration and frontend checks after the runner issue is resolved.
- Keep the same PHP 8.3, MySQL 8.4 test service, Bun lockfile and Node 22 workflow versions unless deliberately upgraded.

## Build and local validation

```bash
bun install --frozen-lockfile
bun run lint
bun run build
php scripts/check-migration-safety.php
```

For database/API integration, use a disposable MySQL database only:

```bash
php scripts/test-mysql-migrations.php
php scripts/test-php-api-integration.php
php scripts/test-account-lifecycle.php
```

Set the documented `AEC_TEST_DB_*` and `AEC_DB_*` variables for the disposable test database before running the PHP integration scripts. Never run destructive integration tests against production data.

## cPanel release checklist

Before deployment, use the exact candidate ZIP and follow [CPANEL-DEPLOYMENT.md](CPANEL-DEPLOYMENT.md). Confirm PHP 8.3, PDO MySQL, mbstring, MySQL/MariaDB compatibility, HTTPS, Apache rewrite rules, private storage permissions, email delivery, all migrations, administrator creation, protected file access and student enrollment/payment flows.

A final ZIP may be labelled **production-ready** only after:
- all migration files apply cleanly on a fresh database and an upgrade database;
- PHP lint, migration safety, MySQL schema/API integration, account lifecycle and frontend type-check/build pass on the exact release commit;
- role/branch authorization and browser E2E/security checks pass;
- payment, email and private media cPanel smoke tests pass;
- backup and restore are rehearsed;
- the exact ZIP installs successfully on a clean cPanel account.

Until then, identify the ZIP as a **development/integration snapshot — not production-ready**.
