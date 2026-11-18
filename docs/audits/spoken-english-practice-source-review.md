# Spoken English Practice — source review

Reviewed source: `spoken-english-practice-laravel-plugin (4).zip` uploaded 18 November 2026.

## Initial verification
- 44 ZIP entries; 27 source checksums match the supplied `checksums.json`.
- All 27 PHP and Blade files pass PHP 8.4 syntax lint.
- The module is designed as an independent plugin and should remain separate from Phonetics and AL Branding & Loader.

## Integration compatibility confirmed
- AEC LMS plugin modules live under `laravel/plugins/modules/<slug>`.
- Providers extend `App\Core\Plugins\BasePluginServiceProvider`.
- The LMS course schema uses `courses(id,title,slug,description,price,status)`.
- The LMS enrollment schema uses `enrollments(user_id,course_id,status)`, with `active` as the access-granting status.
- The plugin's original host-course linking assumptions match those current columns.

## Security and correctness issues requiring fixes before enablement
1. `CourseAccessService::canAccessExam()` grants access to any authenticated user when an exam has `access_scope=paid` but no linked course. This bypasses paid-course gating.
2. Exam submission does not enforce the server-side deadline. The browser timer is only a client-side control and can be bypassed.
3. Exam attempt creation/submission is not serialized with row locks; concurrent requests can create or submit duplicate attempts.
4. An abandoned `in_progress` attempt can be resumed after its deadline.
5. Host course and enrollment table queries assume optional columns exist. Make the integration schema-aware for reuse in other Laravel LMS installations.
6. Audio URL inputs accept arbitrary strings, and the model accepts protocol-relative URLs. Validate HTTPS URLs or known local storage paths and reject protocol-relative paths.
7. Replacing uploaded audio leaves the previous stored asset behind; clean it up after a successful database update.

## Release gate
Do not mark the plugin production-ready until the above authorization, timer, concurrency, and URL issues are fixed and tests cover free/paid access, expired attempts, replay/concurrency, cross-student ownership, and host-schema compatibility.
