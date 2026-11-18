# Spoken English Practice — source review and integration gate

Reviewed upload: `spoken-english-practice-laravel-plugin (4).zip` (18 November 2026).

## Package verification

- Hardened package: `spoken-english-practice-laravel-plugin-1.0.2.zip`.
- Archive contains the single plugin root directory `spoken-english-practice/`; the AEC uploader accepts a root manifest or one directory below the ZIP root.
- Package SHA-256: `aab4c5bab18e19dc45b5e5a34ab7f88538e62a2fce66edcc3158e21a48fc9271`.
- 24 PHP files pass PHP syntax lint.
- All 28 SHA-256 entries match; the ZIP integrity test passes.
- Composer validation was not run because Composer is not installed in the review runtime.
- No Laravel/MySQL feature or migration integration test has been run.

## AEC LMS integration compatibility checked against `main`

Repository: `aec-lms/alom-s-english-center-lms-laravel`.

- Laravel application root: `laravel/`; independent plugin modules live at `laravel/plugins/modules/<slug>`.
- Core version defaults to `2.0.0` in `laravel/config/lms.php`; manifest compatibility `^2.0` matches that default.
- `PluginManager::locateManifestFile()` accepts `plugin.json` at the archive root or one subdirectory deep. The package's one-folder layout is accepted.
- `PluginManager::verifyPackageIntegrity()` requires `checksums.json`, requires the `plugin.json` checksum, checks each SHA-256 and rejects unlisted package files. The v1.0.2 package contains the required manifest and checksum inventory.
- `PluginManager::registerPluginAutoload()` derives the plugin namespace prefix from the manifest provider and maps it to the installed plugin directory; a Composer autoload edit is not required for this plugin.
- `BasePluginServiceProvider` loads plugin web/API routes, views and migrations. PluginManager also requires route files after installation, so route registration must be idempotent.
- The plugin uses the expected provider contract and its route file has a named-route guard for the runtime second load.
- Manifest has no hard dependencies; `course-management` and `exam-management` are optional dependencies, so absence of either does not block installation.
- Host core config defaults to `APP_VERSION=2.0.0`; confirm the deployed value before upload because a custom version outside `^2.0` will reject the package.

## Issues addressed in package v1.0.2

1. Paid/course-scoped exams cannot bypass enrollment when no linked course exists.
2. Server-side deadlines close expired attempts; expired attempts count toward the attempt limit.
3. Attempt creation/submission use database row locks and request throttling.
4. Admin cannot edit/delete an assessment, its course, or its sentences while an attempt is in progress.
5. Selected exam sentences must be active and belong to the assessment course.
6. Host table optional columns are checked before use.
7. Staff roles (teacher, instructor, branch manager) no longer implicitly bypass paid access; they need explicit plugin management permission.
8. Host completed enrollments remain valid; host course/enrollment branch scope is enforced when available.
9. Audio references are restricted to HTTPS or the configured local audio directory; protocol-relative and HTTP audio URLs are rejected.
10. Replaced uploaded audio is cleaned up after a successful update.
11. AEC provider boot avoids duplicate route/view/migration loading; route definitions are also idempotent.

## Integration state and production gates

- The v1.0.2 ZIP is prepared and statically checked.
- The plugin source files are **not yet present in the LMS Git repository**; the audit branch contains this review document only.
- The package has **not** been uploaded through the LMS Admin Panel and has **not** been installed on live hosting.
- Before production: install it in a disposable/staging copy through Admin Panel → Plugins → Upload Plugin ZIP; verify checksum acceptance, migration, enable/disable, route availability, admin permission assignment, student free/paid enrollment, branch isolation, exam deadline/attempt limits, audio upload/cleanup, and rollback.
- Run the Laravel feature suite and MySQL migration/integration tests against the actual LMS revision. Do not treat PHP lint or package checksums as proof of runtime compatibility.
- Keep this plugin independent from the Phonetics plugin and the AL Branding & Loader plugin.

