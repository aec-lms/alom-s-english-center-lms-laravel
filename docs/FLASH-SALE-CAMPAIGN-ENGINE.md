# Flash Sale Campaign Engine

## Overview

Flash Sale is an optional campaign layer for paid courses. Campaign presentation is public, but the price is always recalculated by the LMS on the server. The storefront never submits a trusted price.

## Campaign eligibility and precedence

A campaign must:
- belong to the requested course;
- be published and enabled;
- have started (starts_at <= now()) and not expired (ends_at > now());
- match the learner's effective branch when it is branch-targeted.

When multiple campaigns overlap, the pricing service chooses one campaign deterministically:
1. branch-specific campaign before platform-wide campaign;
2. higher numeric priority;
3. earlier expiration;
4. higher campaign ID as a stable tie-breaker.

Only one campaign applies to a course. Discounts do not stack with other Flash Sale campaigns. Percentage discounts are bounded by 100%; fixed discounts cannot exceed the course subtotal.

## Pricing integration

The shared FlashSalePricingService is used by:
- direct course enrollment invoice generation;
- legacy course-cart item snapshots and checkout revalidation;
- unified commerce course catalogue snapshots and checkout revalidation.

If a course price or eligible campaign changes after a legacy cart item was added, checkout fails closed with a review-the-cart message. A new cart snapshot must be created before payment.

Gift-course purchase pricing is deliberately not included in this version. Coupon eligibility and gift pricing remain controlled by their existing commerce services.

## Campaign management

The Flash Sale manager can configure:
- campaign title, subtitle and description;
- percentage or fixed discount;
- badge text, CTA label and HTTPS banner image URL;
- campaign start/end, display priority and optional branch;
- offer terms and public page visibility.

Branch managers are constrained to their active assigned branch. A branch-targeted campaign must point to a branch that exists and must not conflict with a branch-specific course.

Campaign lifecycle and storefront setting changes are recorded through the shared audit logger when the core audit table is installed.

## Storefront

The public page shows currently eligible offers only, chooses the same winning campaign as checkout, and displays original price, sale price, savings, offer terms and a countdown to expiry. Branch-targeted offers are only shown to signed-in visitors whose branch matches; guests see platform-wide offers only.

Countdowns are presentation only. The server schedule remains authoritative, so an expired campaign cannot be purchased even if a browser tab remains open.

## Migration and release

The additive migration 2026_11_19_000001_add_premium_flash_sale_campaign_fields.php extends existing campaigns without dropping or rewriting campaign rows. Plugin version is 1.1.0.

Before release, run:
- Laravel Feature suite on SQLite and MySQL 8;
- Browser E2E;
- Composer validation and security audit;
- PHP syntax lint and Blade compilation;
- package build/install verification;
- manual responsive checks for the public page and admin editor.

## Explicit limitations

This release does not implement finite redemption inventory, per-student redemption caps, automated recurring schedules, campaign A/B testing, impression/conversion analytics, or Flash Sale pricing for gifts. Do not advertise those capabilities until a later version includes atomic reservation/release handling and payment/refund reconciliation.
