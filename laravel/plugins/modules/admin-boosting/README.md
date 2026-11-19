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

- The homepage renders active `homepage_featured` campaigns that target a published course or a global institution/offer campaign.
- The homepage renders active `notice_banner` campaigns as a notice strip.
- The public course catalog renders `course_listing` campaigns only on their matching course, matching active branch, or all courses for a global institution/offer campaign.

All three placements include an impression beacon and route CTA clicks through the click endpoint. Future placements must call the impression endpoint only when the campaign is actually visible and must use the click endpoint for its CTA.

The impression endpoint returns HTTP 204. The click endpoint redirects to the validated destination. Example route helpers:

```php
route('plugins.boost.impression', $campaign)
route('plugins.boost.click', $campaign)
```

## Target validation

The admin UI provides searchable course and branch selectors. Course targets must resolve to published, non-recycled courses; branch targets must resolve to active, non-recycled branches while Branch Management is enabled. Branch campaigns are restricted to the course listing placement so they cannot be configured in placements that do not have branch context.

Institution-wide and offer-wide campaigns are global targets and do not accept a record ID. The current schema does not expose canonical institution/offer entity tables, so those target types intentionally remain global rather than accepting unverified IDs.

## Analytics and privacy

Impressions and clicks are deduplicated per campaign, event type and session using a random 256-bit visitor token stored in the Laravel session. The token is HMAC-SHA256 hashed with `APP_KEY` before storage. Raw session IDs, IP addresses and user-agent strings are not stored. A database unique constraint protects counters from repeated requests and concurrent duplicate inserts.

Events are retained with plugin data under the repository's plugin uninstall policy. Configure session lifetime and application-key rotation according to the LMS privacy and retention policy.
