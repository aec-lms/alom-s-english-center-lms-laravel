# Unified Commerce Cart & Checkout

The optional `commerce-cart` plugin provides one authenticated shopping cart and one invoice/order checkout for paid learning products.

## Supported catalogue types

| Type | Source | Settlement result |
|---|---|---|
| Course | Published `courses` record | Pending enrollment is linked to the invoice and activated only after full verified settlement |
| eBook | Active paid `storefront_items` record of type `ebook` | Protected `storefront_item_access` row is created after settlement |
| Mock Test | Published paid Learn & Earn exam | `learn_earn_exam_access` is granted after settlement |
| Subscription | Admin-created `commerce_sellables` record | Time-bounded subscription entitlement is recorded |
| Live Class Pass | Admin-created sellable mapped to a real `live_classes.id` | Time-bounded live-class entitlement is recorded and checked by live-class join/heartbeat |
| Other | Admin-created sellable | Generic auditable entitlement is recorded for future module adapters |

Access-based items are single-quantity. The optional `other` item type can use quantity up to 20. Prices are never accepted from the browser; the catalog is resolved again at checkout and current price/availability is used.

## Student experience

- Course, eBook and paid Mock Test detail pages have Add to Cart actions.
- `/learning-services` lists active subscriptions, live-class passes and other configured paid services.
- `/cart` shows a student's private cart and one checkout form.
- Checkout creates one Fee Invoice and one Commerce Order with immutable title/price snapshots.
- SSLCOMMERZ hosted checkout (including provider-supported bKash/Nagad/card/banking methods) and PayPal use the existing Universal Payment orchestrator.
- Access is not granted from the browser return URL. Existing signed/validated provider settlement updates the invoice and then fulfills the Commerce Order.

## Admin configuration

Install and activate `commerce-cart`, `fees-management`, `payment-management`, `universal-payment` and at least one configured payment gateway. Configure the gateway using its own plugin settings. Open **Commerce → Paid Services Catalogue** to create subscription plans, live-class passes and other paid services. A Live Class Pass must be mapped to an existing Live Class ID.

## Entitlement contract

The `commerce_entitlements` table is the shared entitlement ledger. Other plugins should use `CommerceEntitlementService::hasActive()` or `hasActiveSubscription()` and must honor `starts_at`, `expires_at` and `status`. A generic subscription entitlement does not silently grant every course or feature; a plan-specific adapter must explicitly map its included resources.

## Release and acceptance gates

1. Clean-install migration and plugin discovery on SQLite and MySQL 8.0.
2. Verify cart isolation, server-side price validation, duplicate prevention, invoice/order creation and no-premature-access tests.
3. Test SSLCOMMERZ and PayPal sandbox callback settlement for a mixed order containing course + eBook + mock test + service pass.
4. Confirm each access record is idempotent under duplicate provider callbacks.
5. Test subscription expiry and live-class pass join/heartbeat/leave under the existing capacity controls.
6. Run the full Browser E2E suite, Composer audit and cPanel package build.

Do not enable production checkout until provider sandbox acceptance is complete. The pull request does not configure merchant credentials or deploy the plugin to the live cPanel host.

## Gift courses and gift vouchers — migration status

Gift-course and gift-voucher purchases are **not yet supported** as unified-cart line items. Their existing legacy checkout remains separate while the recipient-specific settlement adapter is implemented.

Do not add `gift_course` or `gift_voucher` to the generic buyer-entitlement flow. A generic entitlement is owned by the order buyer; a gift must instead fulfill a server-owned draft for its verified recipient. Payment settlement currently fails closed for unrecognized/special order-item types so a future gift item cannot accidentally grant access to the sender. Regression coverage includes both gift-only and mixed course-plus-gift orders: settlement must roll back atomically, leaving the invoice unpaid, order awaiting payment, transaction initiated, and buyer entitlement ledger empty.

The required adapter must bind each order item to exactly one server-created gift/voucher draft, reconcile the immutable amount/currency and branch snapshot against the settled invoice, and fulfill the recipient gift or voucher wallet only after full verified settlement. Duplicate callbacks must be idempotent across invoice creation, gift status, voucher token, wallet credit and ledger rows.

Voucher refunds require an explicit ledger reversal policy: reverse only unspent wallet value; do not silently claw back value already spent. Redeemed course gifts and spent voucher value require an audited manual-review/recovery policy. Provider refund confirmation and ambiguous-outcome reconciliation must precede local refunded state.

The legacy routes remain available during migration. This section is not an authorization to enable new gift checkout or to treat existing gift/voucher refund handling as complete.
