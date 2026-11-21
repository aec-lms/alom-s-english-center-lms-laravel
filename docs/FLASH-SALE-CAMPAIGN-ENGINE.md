# Flash Sale Campaign Engine

## Overview

Flash Sale is an optional campaign layer for paid courses. Campaign presentation is public, while prices are recalculated server-side. Client-submitted prices are never trusted.

## Eligibility and precedence

A campaign must belong to the course, be published and active, be within its start/end window, and match the learner's effective branch when branch-targeted.

When campaigns overlap, the pricing service selects one deterministically:
1. Branch-specific campaign before platform-wide campaign.
2. Higher numeric priority.
3. Earlier expiration.
4. Higher campaign ID as a stable tie-breaker.

Discounts do not stack. Percentage discounts are limited to 100%; fixed discounts are capped at the course subtotal.

## Pricing integration

The shared `FlashSalePricingService` is the canonical server-side quote resolver used by all supported course-enrollment purchase paths in this release:

- **Direct enrollment:** invoice subtotal, discount and payable amount use the same quote. Branch-specific/global precedence is shared with the storefront.
- **Unified Commerce:** `CommerceCatalog::resolve('course')` uses the quote and stores campaign identity, regular price and discount amount in the server-generated cart snapshot.
- **Legacy course cart:** add-to-cart snapshots use the same quote. Checkout revalidates the current quote against the stored unit price and returns a cart-review conflict before creating a payment session if the offer or price has changed.

Course Management and Commerce Cart declare Flash Sale as an optional dependency. When the Flash Sale plugin is disabled or its campaign table is unavailable, the resolver returns the regular course price.

Branch context is resolved server-side. Account and Student-profile branch assignments that conflict fail closed in the commerce catalogue, legacy cart and direct enrollment paths. A branch-targeted campaign cannot override a course assigned to another branch.

Gift-course pricing is intentionally excluded. Coupon eligibility and gift pricing remain controlled by their existing commerce services; this integration does not claim to alter those flows.

## Campaign management

The Flash Sale manager can configure:
- Campaign title, subtitle and description.
- Percentage or fixed discount.
- Badge text, CTA label and HTTPS banner image URL.
- Campaign start/end, display priority and optional branch.
- Offer terms and public page visibility.

Branch managers may manage only campaigns explicitly assigned to their active branch and linked to courses in that same branch. Conflicting account and Student-profile branch assignments fail closed for branch-targeted storefront visibility.

Campaign lifecycle and storefront setting changes are recorded through the shared audit logger when the core audit table is installed.

## Storefront

The public page shows currently eligible offers, original and sale prices, savings, offer terms and a countdown to expiry. Branch-targeted offers are shown only to signed-in visitors whose effective account/profile branch agrees with the target. If the two branch assignments conflict, only global offers remain visible. Guests see platform-wide offers only.

Countdowns are presentation only; the server schedule remains authoritative.

## Migration and release

The additive premium migration adds campaign presentation, priority and branch-targeting fields. It uses stable named indexes and retry-safe existence checks. Its rollback is intentionally non-destructive / forward-only because schema objects may predate the migration and MySQL DDL can partially commit. A destructive rollback without durable object ownership could erase pre-existing branch assignments or campaign data.

Before release, run:
- Laravel Feature suite on SQLite and MySQL 8.
- Browser E2E.
- Composer validation and security audit.
- PHP syntax lint and Blade compilation.
- Package build/install verification.
- Responsive and accessibility checks for public storefront and admin editor.

## Responsive and keyboard accessibility evidence

The Flash Sale Playwright suite captures and checks the public storefront and Admin Studio at these CSS viewport sizes:

| Surface | Desktop | Tablet | Mobile |
|---|---:|---:|---:|
| Public storefront | 1440 × 1000 | 768 × 1024 | 375 × 812 |
| Admin Studio | 1440 × 1100 | 768 × 1024 | 375 × 812 |

The browser checks assert the public offer grid uses three, two and one columns respectively; the Admin Studio metrics use four, two and one columns; and neither surface creates horizontal document overflow at the tested widths. The E2E run saves desktop/tablet/mobile PNG evidence under `laravel/test-results/` and uploads it as the `aec-lms-browser-e2e-report` workflow artifact.

Keyboard checks move from the offer-terms disclosure to the public course CTA and assert visible `:focus-visible` styling. Admin checks Tab navigation from the campaign-name control to the course control in both create and edit forms, assert visible keyboard focus, verify accessible labels for the campaign fields and status controls, and verify the native edit disclosure opens with Enter, exposes a named form, closes with Enter on its Close editor button, and restores focus to its summary. The edit interaction is intentionally a non-modal `<details>/<summary>` disclosure: it does not use `role="dialog"` or `aria-modal`, and the rest of the page remains available. These are automated browser checks; they do not replace a human screen-reader audit. A manual NVDA/Firefox or NVDA/Chrome smoke test remains a required reviewer sign-off and has not been performed in this environment.

## Explicit limitations

This release does not implement redemption quotas, per-student caps, automated recurring schedules, A/B testing, impression/conversion analytics, gift pricing, or unified cart Flash Sale pricing. Those require separate scoped work, atomic reservation/release handling where relevant, and payment/refund reconciliation.
