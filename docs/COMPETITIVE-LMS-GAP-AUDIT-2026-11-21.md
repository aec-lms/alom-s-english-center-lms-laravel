# Competitive LMS Feature-Gap Audit

Audit date: 2026-11-21  
Target: AEC LMS — Laravel 12, plugin-first architecture  
Scope: compare the current repository feature inventory with commonly documented capabilities in Moodle, Canvas and TalentLMS.

This is a repository capability audit, not a claim that a named plugin is complete. A plugin manifest, route, or admin screen does not prove a complete learner workflow. A feature is accepted only after backend behavior, learner/admin UX, authorization, accessibility, integration tests and operational checks are verified.

## Competitive baseline

The comparison focuses on official product capabilities:
- TalentLMS feature list and product feature pages: content formats including SCORM/xAPI/cmi5, learning paths, skills, assessments, gamification, integrations, mobile and scheduled/custom reporting.
- Canvas feature comparison: LTI integrations, course modules, assignments, outcomes, rubrics, gradebook, discussions, analytics, mobile and accessibility.
- Moodle student feature matrix: SCORM, H5P, quizzes, lessons, surveys, mobile/offline activity and attempt review.

References:
- https://www.talentlms.com/features
- https://www.talentlms.com/wp-content/uploads/documents/TalentLMS-Feature-List-July-2024.pdf
- https://community.instructure.com/en/kb/articles/664412
- https://docs.moodle.org/405/en/images_en/archive/2/2a/20241009071814%21Moodle_features_students.pdf

## Current AEC capabilities found in repository

The current mainline contains 60 plugin packages. The following capabilities already have dedicated packages or code paths and must be improved in place rather than duplicated:
- Courses, curriculum/content studio, assignments, exams, question bank, quizzes/contests, discussions/comments, attendance, live classes and certificates.
- Branch, batch, class, student, teacher, guardian and guardian portal.
- Fee/payment/refund, expense/finance reporting, commerce/cart, bundles, coupons/flash sales, referral income and storefront.
- CMS, page/slider/carousel builders, media library, SEO and marketing integrations.
- Support Center, notification center, SMS/email, push notifications, mobile API, student app and instructor app.
- AI assistant and Phonetics/pronunciation workflows.

The Guardian Portal, Expense Management, Course Content Studio, Support Center, Course Bundles and Push Notifications already exist in main. The older feature-parity inventory that lists these as absent is stale and must not be used as a current implementation checklist.

## Confirmed high-value gaps from source inventory

| Priority | Capability | Current evidence | Recommended work |
|---|---|---|---|
| P1 | Sequential Learning Paths | No learning-paths plugin or learning-path schema on main at audit time | Implement ordered paths, learner progress, branch scoping, prerequisite enforcement and curriculum snapshot safety. PR #378 is the first implementation slice; not accepted until CI/review pass. |
| P1 | SCORM 1.2 / SCORM 2004 runtime | No SCORM package, runtime, manifest parser or attempt persistence found | Build a separate standards-focused plugin: safe ZIP import, imsmanifest validation, isolated launch, cmi data model, suspend/resume, score/completion, attempt history and package security tests. |
| P1 | xAPI / cmi5 interoperability | No xAPI statement store or cmi5 launch/registration implementation found | Implement only after a secure statement endpoint, actor/verb/object validation, idempotency, LRS retention and cmi5 launch lifecycle are designed. |
| P1 | LTI 1.3 / Advantage | No LTI issuer, OIDC login-initiation, JWT/JWKS validation, deep-linking or NRPS services found | Add standards-compliant external-tool integration with strict issuer/client/deployment registration, nonce/state replay protection, key rotation, scopes and tenant/branch isolation. |
| P2 | Competency / Skills framework | No skills/competency module, skill-course mapping or evidence ledger found | Add reusable skill taxonomy, proficiency levels, assessment evidence, learner skill profile and branch-aware skill-gap reporting. |
| P2 | Scheduled report delivery | Reporting/analytics exists, but no report-schedule implementation was found by filename inventory | Add saved report definitions, scheduled delivery, recipient authorization, delivery logs and export retention after auditing current reports APIs. |
| P2 | Rule-based learning automation | No learning-path automation or general rule-trigger module found | Add auditable event-triggered assignment/reminder rules after a safe event catalog and idempotent job execution contract are defined. |
| P2 | Gamification | No badge, points, leaderboard or reward package was found by filename inventory | Add opt-in badges/points with anti-abuse rules, privacy controls, branch scoping and immutable award evidence. |
| P2 | Surveys and feedback | No survey module or survey schema was found by filename inventory | Add configurable surveys, anonymous/identified modes, consent, accessibility and analytics. |
| P2 | Standards/accessibility assurance | No repository-wide WCAG 2.2 AA acceptance suite was found | Add keyboard, screen-reader, contrast, captions/transcripts, reduced-motion and automated axe checks to critical learner flows. |

## Current implementation in progress

### PR #378 — Learning Paths
- Standalone plugin; Course Management and Course Content Studio are optional integrations.
- Admin path creation, course ordering, publishing/archive, branch-scoped management.
- Student catalog, path enrollment, learner progress.
- Curriculum cannot be silently reordered after learners join.
- Active path prerequisites are checked server-side by protected lesson view, file and completion endpoints, including free/preview lesson bypass attempts.
- Course purchases remain in the existing course/commerce/payment workflows; path enrollment does not mark invoices paid or grant paid course enrollment.
- Acceptance still requires exact-head SQLite, MySQL 8, Browser E2E, Composer Security Audit and independent review.

## Implementation sequence

1. Finish and independently review PR #378; correct any CI or review findings before merge.
2. Design SCORM runtime and package threat model before implementation. Never execute uploaded package scripts on the LMS origin.
3. Add LTI 1.3 with standards-based OIDC/JWT validation and a test IdP/tool fixture.
4. Add skills/competency evidence using existing exam, assignment, Phonetics and course completion events.
5. Add scheduled reports and rule-based learning automation with idempotent queues and audit trails.
6. Add gamification and surveys only after privacy, accessibility and abuse controls are specified.
7. Build accessibility acceptance into every new learner-facing workflow.

## Non-goals / cautions

- Do not clone a competitor's proprietary UI, content or implementation.
- Do not claim SCORM/xAPI/LTI support based only on accepting a ZIP or embedding an iframe.
- Do not introduce mandatory dependencies between independent plugins.
- Do not let a learning path bypass payment, enrollment, branch, role or course-content authorization.
- Do not send learner PII, grades, guardian details or payment data to AI or external tools without explicit configuration, consent and data minimization.
- Do not mark a feature complete until its exact-head automated tests and independent review pass.
