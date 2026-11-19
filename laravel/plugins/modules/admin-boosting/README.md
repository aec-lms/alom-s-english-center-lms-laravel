# Admin Boosting

Admin Boosting manages scheduled promotional campaigns for courses, institutions, branches and offers.

## Campaign controls

- Campaign title, target type and optional target record ID.
- Homepage featured, course listing and notice banner placements.
- Badge and CTA copy, priority, start/end schedule and lifecycle status.
- Full in-place editing and recycle-bin deletion.
- Campaign totals and click-through rate (CTR).

## Redirect safety

Campaign destinations accept relative site paths (for example, \`/courses/ielts\`) and absolute URLs only when their host exactly matches \`APP_URL\`. Protocol-relative URLs, credentials in URLs, unsafe schemes, control characters and external hosts are rejected. If external partner destinations are ever required, implement a reviewed host allowlist rather than relaxing this validation.

## Analytics endpoints

The campaign renderer must request the impression endpoint when a campaign is actually shown:

\`\`\`php
route('plugins.boost.impression', $campaign)
\`\`\`

Campaign CTA links should use the click endpoint:

\`\`\`php
route('plugins.boost.click', $campaign)
\`\`\`

The impression endpoint returns HTTP 204. The click endpoint redirects to the validated destination. Events are deduplicated per campaign, event type and session using an HMAC-SHA256 session fingerprint; raw IP addresses and user-agent strings are not stored. The unique event constraint protects counters from repeated requests and concurrent duplicate inserts.

**Integration note:** registering the endpoints and showing aggregate analytics does not automatically instrument every public placement. The storefront component that renders each campaign must call the impression endpoint; the CTA must link through the click endpoint. Until those consumers are connected, the dashboard counters will remain unchanged for uninstrumented placements.

## Target reference note

\`entity_id\` is an optional positive record identifier paired with \`entity_type\`. The admin UI stores the reference but does not yet provide a searchable entity picker or enforce existence against every domain table. A future integration should use the LMS's canonical course/branch/institution/offer repositories and branch authorization rules rather than assuming table names.

## Privacy and retention

Visitor identifiers are keyed hashes of the Laravel session ID using \`APP_KEY\`; they are not reversible without the application secret. Events are retained with plugin data under the repository's plugin uninstall policy. Configure session lifetime and application-key rotation according to the LMS privacy and retention policy.
