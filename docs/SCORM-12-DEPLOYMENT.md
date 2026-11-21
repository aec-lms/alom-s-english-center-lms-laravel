# SCORM 1.2 plugin — deployment and security notes

## Separate content origin is mandatory

SCORM packages are uploaded HTML/JavaScript and must not execute in the authenticated LMS origin. The trusted player shell is served from the LMS application origin; only untrusted SCO/package files are served from the dedicated content origin. The SCORM 1.2 API adapter is injected into the SCO document and communicates with the LMS runtime over a narrowly scoped, attempt-bound request.

1. Create a dedicated HTTPS subdomain such as `scorm-content.example.com` in cPanel.
2. Point its document root to the same Laravel `public/` directory as the LMS so the Laravel routes can resolve the content-host requests.
3. Issue and enable a valid TLS certificate for that subdomain.
4. Set `SCORM_CONTENT_ORIGIN=https://scorm-content.example.com` in the LMS environment and clear/rebuild Laravel configuration cache.
5. Keep the Laravel session cookie host-only. Do not set `SESSION_DOMAIN=.example.com` or any parent domain that would send the LMS session cookie to the SCORM content host. The launch route deliberately refuses a shared session-cookie domain.
6. Ensure the web server routes requests for the content subdomain through Laravel's `public/index.php`; do not expose `storage/app/scorm-packages` as a public alias or static directory.

The content origin must be different from the LMS/player origin and must use HTTPS whenever the LMS uses HTTPS. The player shell route remains on APP_URL; only the package-file route is bound to SCORM_CONTENT_ORIGIN. The runtime API accepts requests only from the exact configured content origin and does not allow credentialed CORS. The SCO iframe is sandboxed; because the SCO and trusted player are cross-origin, the SCO cannot inspect the player DOM or its parent window. The attempt token is available only inside that attempt's SCO document and is never placed in the LMS page's DOM.

## Import limits and package safety

- ZIP upload maximum: 200 MiB.
- Expanded package maximum: 500 MiB.
- Maximum entries: 5,000.
- Maximum individual file: 200 MiB.
- Maximum compression ratio: 200:1.
- Encrypted entries, traversal/absolute/encoded paths, case-colliding names, symlinks/special files, unsafe XML declarations and non-SCORM-1.2 manifests are rejected.
- Extracted files are stored below private `storage/app/scorm-packages/` with owner-only directory/file permissions.
- Package files are delivered only through an attempt-scoped route after the learner, role, enrollment, branch, course, package and payment are revalidated.

## Learner eligibility and completion semantics

- Learner must have an active account and current Student role.
- The package must be published and attached to a published course.
- Enrollment must be active or completed and belong to the authenticated learner.
- Paid courses require the linked invoice to be fully settled.
- Branch restrictions are checked at launch and again on every content/runtime request.
- SCORM lesson status and score are stored per attempt. They do not automatically mark the overall course complete, issue a certificate, or bypass course completion policy.
- Attempt state uses a monotonically increasing version; a stale concurrent tab cannot overwrite a newer commit.

## Current support boundary

This phase targets SCORM 1.2 runtime methods and data elements used by the current adapter. It does not claim SCORM 2004 sequencing/navigation, xAPI/cmi5 LRS, LTI 1.3, third-party certification or offline synchronization. Those require separate acceptance work.

Do not enable SCORM on production until the exact-head SQLite/MySQL 8 suites, browser tests, security review, package checks and an isolated cPanel staging rehearsal pass.
