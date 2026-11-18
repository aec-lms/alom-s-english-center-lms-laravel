# Portable Phonetics, Vocabulary & Exams Plugin

This plugin can run inside the AEC LMS plugin manager or as a standalone Laravel service provider in another Laravel LMS.

## What it provides

- A reusable word bank with UK/US IPA, pronunciation notes, example sentences, audio and card images.
- Course, course-category, lesson and batch-specific word assignments.
- Separate assignment rules for free courses, paid courses or both.
- Vocabulary practice that only shows words assigned to the learner's active course/lesson/batch.
- Exams generated from admin-selected words, with time limits, pass marks, attempt limits and randomized questions/options.
- Server-side answer checking. Correct answers are never sent in the question payload and submitted scores are never trusted.
- A plugin-owned batch roster fallback. If the host LMS has its own student-to-batch table, map that table instead.
- Numeric or UUID host user identifiers (stored in the plugin schema as strings, without foreign keys to host tables).
- Plugin-owned assignments, exams, attempts and audit events. Host LMS course/enrollment tables are read-only integrations.

## Standalone installation in another Laravel LMS

1. Copy this plugin directory into a path covered by Composer PSR-4 autoloading. The namespace is Plugins\Modules\Phonetics; add an appropriate PSR-4 mapping if the host project does not already autoload Plugins.
2. Register Plugins\Modules\Phonetics\Providers\StandalonePhoneticsServiceProvider::class in bootstrap/providers.php (Laravel 11/12) or the host application's service provider list.
3. Set PHONETICS_STANDALONE=true.
4. Configure the phonetics config in the host application's config/phonetics.php or edit this plugin's Config/phonetics.php.
5. Set the host LMS table/column mapping under catalog. The default mapping expects courses, course_categories, course_lessons, study_batches, and enrollments. Map the equivalent tables/columns used by your LMS.
6. Configure catalog.batch_memberships. By default the plugin uses its own phonetic_batch_memberships table. To use the host LMS roster, point this mapping at the host's batch membership table and set its user, batch, active-status and optional driver columns.
7. Configure users if the host user table or its ID/name/email columns differ from Laravel's standard users table.
8. Define the manage-phonetics Gate or replace middleware.admin with the host LMS's administrator/permission middleware.
9. Run php artisan migrate. The plugin creates only its own phonetic_* tables; it does not migrate, modify or delete host LMS tables.

The default standalone middleware is web for the practice page, web+auth for attempts/exams and web+auth+can:manage-phonetics for administration. Override middleware to match the host LMS.

## Course access rules

- A free course is identified by its mapped price column being zero or less.
- A paid course is identified by its mapped price column being greater than zero.
- `catalog.courses.price` must map to the host LMS course price column. The plugin fails closed with a service-unavailable response if the price mapping is missing; it never silently treats an unknown course as free.
- An assignment/exam can target both, free or paid.
- Learners must have an active enrollment according to catalog.enrollments.active_statuses when enrollment protection is enabled. A paid LMS should only mark a paid enrollment active after payment has been settled.
- A batch-scoped assignment/exam requires a matching active batch membership. If no host membership table exists, administrators can maintain the plugin-owned roster from Admin → Phonetics → Vocabulary & Exams.

## AEC LMS installation

The AEC plugin manifest registers the module under Learning Tools. Course Management and Batch Management are optional integrations; Phonetics can still run with a different mapped Laravel LMS catalog.
