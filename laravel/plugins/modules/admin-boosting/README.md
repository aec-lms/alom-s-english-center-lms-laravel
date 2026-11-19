# Admin Boosting

Admin Boosting manages scheduled promotional campaigns for courses, institutions, branches and offers.

## Campaign controls

- Campaign title, target type and optional target record ID.
- Homepage featured, course listing and notice banner placements.
- Badge and CTA copy, priority, start/end schedule and lifecycle status.
- Full in-place editing and recycle-bin deletion.
- Campaign totals and click-through rate (CTR).

## Redirect safety

Only relative site paths are accepted (for example, `/courses/ielts`). Absolute URLs, protocol-relative URLs, backslashes, control characters and unsafe schemes are rejected at both write time and redirect time. This intentionally prevents the LMS domain from becoming an open-redirect service.

## Storefront integration

The homepage renderer displays active `homepage_featured` campaigns that target a published course or a global institution/offer campaign. The public course catalog displays `course_listing` campaigns only on their matching course, matching active branch, or all courses for a global institution/offer campaign. Branch targeting is hidden and rejected when the Branch Management plugin is disabled. `notice_banner` is reserved for a future placement integration.

Both integrated placements automatically request the impression endpoint when a campaign is rendered and send CTA clicks through the click endpoint. The endpoints are also available to future placements:

```php
route('plugins.boost.impression', $campaign)
```

Campaign CTA links should use the click endpoint:

```php
route('plugins.boost.click', $campaign)
```

The impression endpoint returns HTTP 204. The click endpoint redirects to the validated destination. Events are deduplicated per campaign, event type and session using a random 256-bit token stored in the Laravel session and HMAC-SHA256 keyed by `APP_KEY`; raw session IDs, IP addresses and user-agent strings are not stored. A database unique constraint protects counters from repeated requests and concurrent duplicate inserts.

**Integration note:** the homepage and course catalog are wired. Any future placement must call the impression endpoint only when the campaign is actually visible and must use the click endpoint for its CTA. Campaigns configured for `notice_banner` will not appear until that placement is implemented.

## Target reference note

The admin UI provides searchable course and branch selectors. Course targets must resolve to published, non-recycled courses; branch targets must resolve to active, non-recycled branches while Branch Management is enabled. Institution-wide and offer-wide campaigns are global targets and do not accept a record ID. The current schema does not expose canonical institution/offer entity tables, so those two target types intentionally remain global rather than accepting unverified IDs.

## Privacy and retention

Visitor identifiers are keyed hashes of the Laravel session ID using `APP_KEY`; they are not reversible without the application secret. Events are retained with plugin data under the repository's plugin uninstall policy. Configure session lifetime and application-key rotation according to the LMS privacy and retention policy.
