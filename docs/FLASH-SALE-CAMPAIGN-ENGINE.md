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

The shared `FlashSalePricingService` is integrated into direct course enrollment invoice generation in this change.

**Unified Commerce / legacy course-cart integration is deliberately excluded from this PR** because the canonical owner of `CommerceCatalog.php`, `CourseCommerceController.php`, and the commerce/course-management manifests is PR #275. That owner must apply and test the bounded quote integration separately. Do not assume cart checkout is Flash Sale-aware until that follow-up is merged.

If a course price or eligible campaign changes between cart addition and checkout, the commerce owner must revalidate the price snapshot before payment.

Gift-course purchase pricing is not included. Coupon eligibility and gift pricing remain controlled by their existing commerce services.

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

## Explicit limitations

This release does not implement redemption quotas, per-student caps, automated recurring schedules, A/B testing, impression/conversion analytics, gift pricing, or unified cart Flash Sale pricing. Those require separate scoped work, atomic reservation/release handling where relevant, and payment/refund reconciliation.
