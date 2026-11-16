# AEC LMS Payment Gateway Setup

This document describes the credential-free installation model and the provider configuration required before real payments can be accepted.

## Credential handling

- No merchant credential, API key, webhook secret, or test account is committed to Git.
- Each gateway stores its own credentials through Admin → Plugin Center → select gateway → Configure.
- Fields marked Secret are encrypted with Laravel Crypt and never rendered back into the browser. A blank secret field preserves the existing saved value.
- Credentials are never shared with the Universal Payment plugin.
- Keep each provider in Sandbox/Test mode until its connection check, test payment, callback and ledger settlement have been verified.
- Live mode must only be enabled after the merchant account is approved and the production HTTPS callback URLs are registered with the provider.
- Never submit or store raw card number (PAN), CVV or CVC. Use provider-hosted checkout or provider-issued tokenization.

## Supported provider setup

### SSLCOMMERZ

1. Install and enable gateway-sslcommerz.
2. Open Configure and enter Store ID and Store Password.
3. Keep Sandbox selected and save.
4. Use Test Gateway Connection. The test creates a non-charging BDT 10 checkout session and does not create an LMS invoice or payment transaction.
5. Register the callback endpoints on the public HTTPS application:
   - POST /payments/sslcommerz/success
   - POST /payments/sslcommerz/failed
   - POST /payments/sslcommerz/cancelled
   - POST /payments/sslcommerz/ipn
6. The application verifies success using the provider Order Validation API and checks transaction reference, amount, currency and risk level before settlement.
7. After provider sandbox tests pass, enter production Store credentials and switch to Live.

### Stripe

1. Install and enable gateway-stripe.
2. Enter the Publishable Key, Secret Key and Webhook Signing Secret from the Stripe Dashboard.
3. Keep Test / Sandbox selected and save.
4. Use Test Gateway Connection to validate the Secret Key against Stripe's account API.
5. Configure a Stripe webhook endpoint at POST /payments/stripe/webhook for payment_intent.succeeded; copy its whsec signing secret into the plugin.
6. The browser must use Stripe.js to create a PaymentMethod (pm_...). The server creates the PaymentIntent and never receives raw card details.
7. Settlement happens only after a valid Stripe-signed payment_intent.succeeded event whose PaymentIntent ID, LMS transaction reference, amount and currency match.
8. Switch to Live only with approved Stripe account credentials and a production webhook secret.

Stripe account availability, settlement currencies and supported payment methods depend on the merchant's country and Stripe account eligibility. The LMS cannot activate a Stripe merchant account on the user's behalf.

### PayPal

1. Install and enable gateway-paypal.
2. Create a REST API app in PayPal Developer Dashboard and enter its Client ID, Client Secret and Webhook ID.
3. Keep Sandbox selected and save.
4. Use Test Gateway Connection to obtain an OAuth access token and verify the credentials.
5. Register POST /payments/paypal/webhook for PAYMENT.CAPTURE.COMPLETED; the application asks PayPal's webhook verification API to verify each event.
6. Checkout creates a real PayPal Orders v2 order and returns its provider approval URL. The return handler captures the approved order server-side. Webhook and capture validation check amount and currency before settlement.
7. Use Live credentials and a Live webhook only after Sandbox order/capture tests pass.

### Visa / Mastercard processor interface

Visa and Mastercard are card networks, not merchant acquiring APIs. A merchant must obtain an acquiring-bank or PSP integration (for example, a bank-hosted checkout or processor SDK/API). The gateway-card plugin is a PCI-safe integration boundary and deliberately reports that a processor-specific adapter is required; it must not mark an LMS transaction paid merely because a browser submitted a token. Do not enter arbitrary keys and assume this plugin can charge a card.

For supported Visa/Mastercard processing without a separate acquirer adapter, configure the dedicated Stripe plugin where Stripe merchant onboarding is available. The Stripe integration uses Stripe.js tokenization and server-side PaymentIntents.

## Universal Payment

universal-payment is an orchestrator, not another credential vault. It discovers enabled gateway plugins and their setup state. It stores only global preferences (default currency, allowed gateway slugs and offline-payment toggle). Provider credentials stay in the relevant standalone gateway plugin.

A gateway is offered only when its plugin is installed, enabled and has all required credentials. Missing credentials result in not_configured; a disabled plugin is not offered; a card network without a PSP adapter reports processor_adapter_required.

## Current integration boundaries

- Implemented provider API flows: SSLCOMMERZ Session + Order Validation API; Stripe PaymentIntents + signed webhook; PayPal Orders v2 + OAuth + server-side capture + PayPal webhook verification.
- Manual cash/bank-transfer records continue through the LMS payment ledger and require authorized staff reconciliation.
- bKash, Nagad and Rocket are not yet independent live gateway plugins in this release. They must be added as separate provider integrations with merchant-issued API credentials and official API specifications; they must not be represented as working merely because a Universal Payment setting mentions them.
- Real-money acceptance cannot be certified until the merchant supplies credentials and performs provider sandbox/live acceptance tests.
