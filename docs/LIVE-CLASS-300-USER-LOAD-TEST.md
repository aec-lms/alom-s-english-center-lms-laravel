# Aloms English Center LMS — 300+ Concurrent Student Load Test

## What this test covers

The repository currently models a live class as a scheduled record containing a platform name and an external meeting URL (Zoom, Google Meet, or Microsoft Teams). It does not implement an LMS-hosted WebRTC media server or a first-party student join/presence endpoint.

The k6 profile therefore measures the LMS web tier used by students to discover/access course pages:
- `GET /courses`
- Optionally, `GET /course/{course_slug}`

It ramps to at least 300 concurrent virtual users, holds that load for the selected period, and ramps down. It checks HTTP success, non-empty responses, request failure rate, and p95 response time.

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

## LMS-side participant admission

PR #72 adds an authenticated join flow at `POST /live-classes/{id}/join`, plus heartbeat and leave endpoints. Each session has an admin-configurable LMS seat limit (default 300, maximum 2,000). A join requires a student account with an active enrollment and matching branch, and access opens 15 minutes before the scheduled start. Seat allocation is serialized against the live-class row; presence expires after two minutes without a heartbeat.

The current k6 script above still measures public course discovery pages only. It does not yet simulate 300 authenticated, distinct enrolled student accounts against the protected join endpoint. That requires an isolated staging class and a pool of at least 300 dedicated test student accounts; never reuse a single account to represent 300 distinct seats.

## Important limitation: video meeting capacity

This test does **not** create 300 real Zoom/Google Meet/Teams participants and does not prove that an external meeting provider permits 300 attendees. The live class media capacity is governed by the provider account, meeting licence, host settings, and provider-side limits. To validate the complete class experience, separately run an authorized provider-side test meeting with 300 test accounts/participants and verify join, audio/video, moderation, recording, and reconnect behavior.

Do not expose real meeting URLs or credentials in load-test logs or source control.
