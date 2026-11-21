# SCORM 1.2 plugin — deployment and security notes

## Separate content origin is mandatory

SCORM packages are uploaded HTML/JavaScript and must not execute in the authenticated LMS origin. The plugin serves the player and package files from a separate origin and uses a short-lived, attempt-scoped token to save runtime state back to the LMS.

1. Create **two** dedicated HTTPS subdomains, for example `scorm-player.example.com` and `scorm-content.example.com`, in cPanel. They must both differ from the LMS host and from each other.
2. Point both document roots to the Laravel `public/` directory so their domain routes resolve through Laravel. Do not add public aliases or static mappings for `storage/app/scorm-packages`.
3. Issue and enable separate valid TLS certificates for both subdomains.
4. Set `SCORM_PLAYER_ORIGIN=https://scorm-player.example.com` and `SCORM_CONTENT_ORIGIN=https://scorm-content.example.com` in the LMS environment, then clear/rebuild Laravel configuration cache.
5. Keep the Laravel session cookie host-only. Do not set `SESSION_DOMAIN=.example.com` or any parent domain. The launch route rejects either isolated subdomain when it could receive the LMS session cookie.
6. Ensure requests for both subdomains are routed through Laravel's `public/index.php`.

All three hosts—LMS, player and content—must be distinct. Both isolated origins must use HTTPS when the LMS uses HTTPS. The runtime API accepts requests only from the exact configured **player origin**; it does not use credentialed CORS.

## Player and SCO origin separation

The trusted player shell is served from `SCORM_PLAYER_ORIGIN`, separate from both the authenticated LMS and untrusted SCO content. Uploaded package files are served from `SCORM_CONTENT_ORIGIN`. The trusted player embeds each SCO in a cross-origin iframe with `sandbox="allow-scripts"` and deliberately omits `allow-same-origin`, giving the SCO an opaque origin. The SCORM 1.2 adapter runs in the SCO frame but never receives the attempt token. It communicates with the trusted player through a narrow `postMessage` bridge; only the player can send authenticated, attempt-scoped, versioned commits to the LMS API.

- Keep `/scorm-player/{attempt}/{token}` on the dedicated player host, not the LMS or content host.
- The player bootstraps `/scorm-bootstrap/{attempt}/{token}/{path}` on the content host. That one-time route exchanges the path token for short-lived, attempt-scoped, host-only `HttpOnly` cookies and redirects to a clean `/scorm-content/{attempt}/{path}` URL. The token is not present in the final SCO URL or injected adapter.
- Keep `/scorm-content/{attempt}/{path}` on the dedicated content host. Do not create a same-origin alias, wildcard cookie domain, or static public package directory.
- The outer LMS page embeds the trusted player. The player's inner SCO iframe must remain sandboxed with only `allow-scripts`; never add `allow-same-origin`, `allow-top-navigation`, `allow-forms`, or pop-up privileges.
- The bridge validates the exact child `WindowProxy`, opaque `null` origin, message type, attempt identity, per-launch nonce, method allow-list, request ID, payload schema and size. The server separately validates the token hash, attempt/session state, entitlement and monotonic version under row locks. Arbitrary messages from any other frame/window are ignored.
- The player keeps the short-lived token in its own trusted origin and sends runtime commits with `credentials: omit` over exact-origin CORS. The SCO has no token, no LMS DOM access, no player DOM access and no capability to remove its sandbox.
- HTML SCO documents are limited to 10 MiB for runtime-adapter injection. Larger HTML entry documents are rejected rather than served without the SCORM API.
- The trusted player CSP permits frames only from `SCORM_CONTENT_ORIGIN` and API connections only to the LMS. Untrusted SCO responses use a separate CSP with `connect-src 'self'`; cross-origin runtime messages are the only bridge to the LMS. Arbitrary remote script/image/media/font/frame/form egress is blocked to prevent token or learner-data exfiltration. Packages that depend on remote assets must vendor those assets into the ZIP.
- SCO responses use `frame-ancestors SCORM_PLAYER_ORIGIN` and `Cross-Origin-Resource-Policy: cross-origin`. Runtime API CORS permits only `SCORM_PLAYER_ORIGIN`.
- The content host receives only the scoped, attempt-specific HttpOnly cookie created by the bootstrap endpoint. The LMS session cookie is never shared with either isolated host.

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
