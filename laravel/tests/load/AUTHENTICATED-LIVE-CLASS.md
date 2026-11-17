# Authenticated live-class load test

This is a staging-only test of the LMS seat and presence workflow. It authenticates distinct student accounts, joins one live class, and sends periodic heartbeats for the selected hold duration.

## Required staging setup

1. Use a disposable HTTPS staging installation and database. Never target production.
2. Create at least as many active student accounts as the selected virtual-user count. Emails must match the supplied pattern, where {vu} is replaced by the k6 virtual-user number (1 through N).
3. Give every test account the same dedicated temporary password and enroll every account in the same course that owns the target live class.
4. Set the target live class to live, set its LMS capacity to the intended test value (300 or more), and ensure it is within its 15-minute access window.
5. Add the repository Actions secret AEC_LIVE_CLASS_TEST_PASSWORD containing only the temporary test-account password.
6. Run Authenticated Live Class 300+ Load Test from GitHub Actions with the staging URL, live-class ID, email pattern, user count, capacity, and hold duration.

The workflow validates HTTPS, requires at least 300 VUs, and refuses to run without the dedicated password secret. Each VU logs in as a different student, obtains its own session cookie, joins the class, and keeps its presence lease alive with heartbeat requests.

## Acceptance signals

- Authentication and join success rates exceed 99%.
- Heartbeat success rate exceeds 99%.
- No heartbeat reports an active seat count above the configured capacity.
- HTTP request failure rate remains below 1% and p95 latency below 5 seconds.

A passing run validates LMS authentication, seat admission, and heartbeat behavior under the measured staging load. It does not prove that Zoom, Google Meet, or Microsoft Teams can host the same number of real media participants. Provider-side meeting capacity must be tested separately.

Test accounts' presence leases expire within two minutes after the load test stops unless they explicitly leave the class. Use only disposable staging accounts.
