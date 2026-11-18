# Spoken English Practice — source review and integration gate

Reviewed upload: `spoken-english-practice-laravel-plugin (4).zip` (18 November 2026).

## Package verification
- Original archive: 44 entries; 27 supplied source checksums matched.
- Hardened package: `spoken-english-practice-laravel-plugin-1.0.2.zip`.
- 24 PHP files pass PHP syntax lint.
- 28 SHA-256 entries match the packaged files; the ZIP integrity test passes.
- The plugin remains an independent module, separate from Phonetics and AL Branding & Loader.

## AEC LMS compatibility
- Modules are installed under `laravel/plugins/modules/<slug>`.
- Provider contract: `App\Core\Plugins\BasePluginServiceProvider`.
- Host course schema: `courses(id,title,slug,description,price,status,branch_id?)`.
- Host enrollment schema: `enrollments(user_id,course_id,status,branch_id?)`.
- Host access accepts `active` and `completed` enrollment states and checks branch IDs where present.
- Route loading is idempotent for the runtime ZIP-install path, where the plugin manager may load a route file after provider boot.

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

## Verification still required before production
- Run the plugin through the AEC Plugin Manager against a disposable copy of the LMS.
- Execute Laravel feature tests and MySQL migrations/integration tests.
- Verify plugin enable/disable/uninstall and student/admin browser flows.
- Do not deploy migrations to the live hosting environment until the staging acceptance checks pass.

The ZIP package has been prepared and statically checked. It has **not** been installed on the live hosting account, and its source files have **not** yet been merged into the LMS repository.
