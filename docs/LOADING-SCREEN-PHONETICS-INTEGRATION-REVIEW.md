# Loading Screen and Phonetics Module — Integration Security Review

**Review scope:** `alom-english-center-loading-screen (6).zip` (23 files; 132,698 bytes uncompressed)  
**Target:** AEC LMS Laravel 12 application (`/laravel`)  
**Status:** Static review only; not integrated, not merged, and not approved for production.

## Verification completed

- ZIP integrity check: passed (`unzip -t`; no corrupt entries).
- PHP syntax check: all 21 PHP files passed `php -l` on PHP 8.4.24.
- Branding scan inside the ZIP: no excluded third-party AI branding strings or paths found.
- Repository architecture reviewed: Laravel 12, PHP 8.3+, plugin modules under `laravel/plugins/modules/`, plugin manifests, `BasePluginServiceProvider`, dynamic plugin registration, and Core RBAC.
- The supplied source currently uses global `App\\Models`, `App\\Http\\Controllers`, `App\\Providers`, root-level Laravel config/routes/views/migrations. It is not yet packaged as two native AEC plugin modules and must not simply be copied over the repository.

## Blocking security and integration findings

1. **Admin authorization is too broad.** Both admin route groups use `auth` only. The loader FormRequest authorizes every authenticated user. Phonetics admin mutations (settings, randomize, create/update, media changes, toggle, delete) have no explicit Core permission middleware or policy checks. They must be mapped to dedicated plugin permissions and enforced server-side.
2. **Course enrollment is not enforced on attempt submission.** The practice page checks course access in `index()`, but `storeAttempt()` does not call the same enrollment authorization. A direct POST can bypass the page gate. Enforce enrollment and course-scope checks on every read/write endpoint.
3. **Quiz correctness is client-controlled.** `storeAttempt()` accepts `is_correct` from the request and stores it directly. A client can submit a correct result without answering correctly. Compute correctness on the server from the selected answer and stored entry data; never trust a submitted score.
4. **Public SVG uploads need removal or sanitization.** Loader logos and phonetics background images accept SVG and are written to a public path. Untrusted SVG can contain active content. Prefer raster-only formats or a vetted sanitization pipeline, and store uploads using the repository's safe media conventions.
5. **Media replacement is not failure-safe.** Several update paths delete the existing media file before the replacement has been stored successfully. Store the new file first, persist the new path transactionally, and delete the old file only after success; clean up newly stored files on rollback.
6. **Plugin lifecycle integration is missing.** The ZIP providers extend plain Laravel `ServiceProvider`, load standalone routes/config, and are not native plugin providers. The AEC plugin system expects module directories, a `plugin.json` manifest, a provider extending `BasePluginServiceProvider`, plugin-local Routes/Database/Migrations/Resources/views, and permission/menu declarations. Integrate as independently disableable modules without bypassing Core.
7. **Migration and cache lifecycle need integration tests.** Verify clean install, upgrade, rollback, repeated plugin enable/disable, missing-table behavior, cache invalidation, and uninstall/retention behavior against the current MySQL test harness. Do not run destructive migrations against production data.

## Required acceptance work before merge

- Convert Loader and Phonetics into separate native modules with unique slugs, manifests, providers, permissions, admin menus, plugin-local migrations/routes/views/config, and no duplicate Core functionality.
- Replace broad `auth` checks with the AEC permission system and verify unauthorized roles cannot read or mutate admin routes.
- Enforce course enrollment and course scope on both practice display and attempt submission.
- Derive correctness server-side and add tampering/regression tests.
- Remove SVG uploads unless safely sanitized; add MIME/content validation and safe media storage.
- Make media replacement transactional/failure-safe.
- Add tests for validation, authorization, course isolation, score tampering, upload abuse, file cleanup, plugin disable/enable, and migration rollback.
- Run Composer validation, Pint, the complete Laravel test suite, MySQL migration/schema integration tests, and relevant CI workflows. The local review environment did not have Composer installed, so Laravel tests were not run here.

## Decision

**Do not merge or deploy this ZIP as-is.** Syntax validity and ZIP integrity do not establish secure authorization, correct scoring, plugin compatibility, or production readiness. This report records the blockers so the integration can be completed without silently importing unsafe behavior.
