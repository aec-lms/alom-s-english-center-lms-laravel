# Aloms English Center LMS — 300+ Concurrent Student Load Test

## What this test covers

The LMS includes an authenticated student join and presence workflow for scheduled live classes. It checks active enrollment, branch access, class availability, and a configurable LMS seat capacity (300 by default); it records participant join, heartbeat, and leave state. The LMS does not host the video stream itself: Zoom, Google Meet, or Microsoft Teams provides the meeting media.

The current k6 workflow measures the public LMS web tier used by students to discover/access course pages:
- `GET /courses`
- Optionally, `GET /course/{course_slug}`

It ramps to at least 300 concurrent virtual users, holds that load for the selected period, and ramps down. It checks HTTP success, non-empty responses, request failure rate, and p95 response time.

**Important distinction:** the current k6 workflow does not authenticate 300 distinct student accounts and does not exercise the live-class join, heartbeat, or leave endpoints under 300-user concurrency. Passing this workflow is evidence about public course-page performance only, not proof of 300 concurrent authenticated joins or participant presence under load.

## Run it

1. Deploy the current tested branch to an authorized HTTPS staging environment.
2. In GitHub Actions, choose **Live Class Load Test** → **Run workflow**.
3. Enter the staging base URL, optionally a published course slug, and at least 300 virtual users.
4. Start with 300 users for 5 minutes. Increase to 500 or 1000 only after confirming hosting capacity and monitoring CPU, RAM, PHP-FPM workers, database connections, queue depth, and web-server errors.
5. Do not point this test at production without an approved maintenance/load-test window.

## Pass criteria

- HTTP request failure rate below 1%.
- p95 response time below 3 seconds.
- At least 99% of page checks pass.

These are initial acceptance thresholds, not a guarantee that every hosting plan will meet them. Record actual host metrics alongside the k6 summary.

## Remaining live-class acceptance tests

Before production release, run a separate authorized staging test with at least 300 distinct enrolled student accounts against the authenticated join, heartbeat, and leave flow. Verify that no more than the configured number of active seats are granted, rejected attempts do not create joined activity events, expired presence is reclaimed, and branch/enrollment restrictions remain enforced. The test must use a disposable staging database and report concurrency, error rate, p95 latency, database lock behavior, and participant counts.

## Important limitation: video meeting capacity

Neither the current public-page k6 test nor the LMS seat counter creates 300 real Zoom/Google Meet/Teams participants or proves that an external meeting provider permits 300 attendees. The live class media capacity is governed by the provider account, meeting licence, host settings, and provider-side limits. To validate the complete class experience, separately run an authorized provider-side test meeting with 300 test accounts/participants and verify join, audio/video, moderation, recording, and reconnect behavior.

Do not expose real meeting URLs or credentials in load-test logs or source control.
