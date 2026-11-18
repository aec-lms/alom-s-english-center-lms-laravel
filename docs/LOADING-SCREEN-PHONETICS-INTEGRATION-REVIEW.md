# AL Branding & Loader and Phonetics — Integration Security Review

**Review scope:** `alom-english-center-loading-screen (6).zip` (23 files; 132,698 bytes uncompressed)  
**Target:** AEC LMS Laravel 12 application (`/laravel`)  
**Status:** Static review only; not integrated, not merged, and not approved for production.

## Confirmed product architecture

The supplied work must be organized into **two independent AEC plugins**:

1. **AL Branding & Loader** — one plugin containing two distinct feature areas:
   - **AL Branding:** LMS identity and presentation settings, such as the supported site identity, logo, and favicon.
   - **Loader:** a configurable loading screen that displays the selected custom logo while pages or links load.
   Branding settings and Loader settings remain distinct in the admin UI and implementation, but ship together under this one plugin.
2. **Phonetics** — a separate, independently installable and disableable plugin. It must not depend on, contain, or be merged into AL Branding & Loader.

Do not create a third standalone Branding plugin or fold Phonetics into the Branding/Loader plugin. Shared Core services may be used through documented interfaces, but neither plugin may require the other to be enabled.

## Verification completed

- ZIP integrity check: passed (`unzip -t`; no corrupt entries).
- PHP syntax check: all 21 PHP files passed `php -l` on PHP 8.4.24.
- Branding scan inside the ZIP: no excluded third-party AI branding strings or paths found.
- Repository architecture reviewed: Laravel 12, PHP 8.3+, plugin modules under `laravel/plugins/modules/`, plugin manifests, `BasePluginServiceProvider`, dynamic plugin registration, and Core RBAC.
- The supplied source currently uses global `App\\Models`, `App\\Http\\Controllers`, `App\\Providers`, root-level Laravel config/routes/views/migrations. It is not yet packaged as native AEC plugins and must not simply be copied over the repository.

## Blocking security and integration findings

1. **Admin authorization is too broad.** Both admin route groups use `auth` only. The Loader FormRequest authorizes every authenticated user. Phonetics admin mutations (settings, randomize, create/update, media changes, toggle, delete) have no explicit Core permission middleware or policy checks. Map each plugin's admin actions to dedicated permissions and enforce them server-side.
2. **Course enrollment is not enforced on attempt submission.** The Phonetics practice page checks course access in `index()`, but `storeAttempt()` does not call the same enrollment authorization. A direct POST can bypass the page gate. Enforce enrollment and course-scope checks on every read/write endpoint.
3. **Quiz correctness is client-controlled.** Phonetics `storeAttempt()` accepts `is_correct` from the request and stores it directly. A client can submit a correct result without answering correctly. Compute correctness on the server from the selected answer and stored entry data; never trust a submitted score.
4. **Public SVG uploads need removal or sanitization.** Loader logos and Phonetics background images accept SVG and are written to a public path. Untrusted SVG can contain active content. Prefer raster-only formats or a vetted sanitization pipeline, and store uploads using the repository's safe media conventions.
5. **Media replacement is not failure-safe.** Several update paths delete the existing media file before the replacement has been stored successfully. Store the new file first, persist the new path transactionally, and delete the old file only after success; clean up newly stored files on rollback.
6. **Native plugin lifecycle integration is missing.** The ZIP providers extend plain Laravel `ServiceProvider`, load standalone routes/config, and are not native plugin providers. Implement exactly two plugin manifests/providers/module roots as described above, each with its own permissions, menus, plugin-local migrations/routes/views/config, and independent enable/disable lifecycle. Branding and Loader remain separate feature areas inside the combined plugin.
7. **Migration and cache lifecycle need integration tests.** Verify clean install, upgrade, rollback, repeated enable/disable of each plugin independently, missing-table behavior, cache invalidation, and uninstall/retention behavior against the current MySQL test harness. Do not run destructive migrations against production data.

## Required acceptance work before merge

### AL Branding & Loader plugin
- Provide one unique plugin slug, manifest, native provider, permissions, admin menu, and plugin-local migrations/routes/views/config.
- Keep Branding and Loader as distinct settings/features within that plugin.
- Allow the configured custom logo to appear on the intended page/link loading screen; define safe defaults and graceful behavior when no logo is configured.
- Avoid duplicate Core branding/theme functionality; integrate with supported Core settings where appropriate.

### Independent Phonetics plugin
- Provide its own unique plugin slug, manifest, native provider, permissions, admin menu, and plugin-local migrations/routes/views/config.
- Ensure it installs, runs, and disables without AL Branding & Loader being enabled.
- Enforce course enrollment and course scope on both practice display and attempt submission.
- Derive correctness server-side and add score-tampering regression tests.

### Shared security and release gates
- Replace broad `auth` checks with the AEC permission system and verify unauthorized roles cannot read or mutate admin routes.
- Remove SVG uploads unless safely sanitized; add MIME/content validation and safe media storage.
- Make media replacement transactional/failure-safe.
- Add tests for validation, authorization, course isolation, score tampering, upload abuse, file cleanup, independent plugin enable/disable, and migration rollback.
- Run Composer validation, Pint, the complete Laravel test suite, MySQL migration/schema integration tests, and relevant CI workflows. The local review environment did not have Composer installed, so Laravel tests were not run here.

## Decision

**Do not merge or deploy this ZIP as-is.** Syntax validity and ZIP integrity do not establish secure authorization, correct scoring, plugin compatibility, or production readiness. The implementation must preserve the two-plugin architecture above and pass the required checks before merge.


## Implementation checkpoint — 2026-11-18

An initial native-plugin implementation is now present on the integration branch:

- `al-branding-loader` packages the AL Branding entry point and Loader feature areas together. Branding delegates to the existing Core Site Identity service rather than introducing a competing identity store.
- `phonetics` is a separate plugin with no hard dependency on Course Management; it declares Course Management as optional and fails closed when enrollment enforcement is enabled but the enrollment tables are unavailable.
- Admin routes use the plugin-enabled and permission middleware.
- Phonetics attempt correctness is calculated from the stored IPA answer on the server; client-supplied correctness is not accepted.
- Loader and phonetics media validation does not accept SVG uploads in this initial implementation.

This is an incremental implementation, not a release approval. The original ZIP's complete audio upload, rich theme profiles, card animation, full admin editing, and complete migration/rollback acceptance still require implementation and tests. Do not install this on production until all CI checks and acceptance tests pass.
