# Bangladesh MFS merchant gateway integration

## Current status

This change adds Plugin Manager configuration manifests for bKash, Nagad and DBBL Rocket. It does **not** enable hosted checkout or settle payments. All three gateways default to disabled. Existing manual MFS transaction-reference submissions remain separate.

## bKash

The settings reserve the merchant-issued Tokenized Checkout credentials: App Key, App Secret, API Username and API Password, plus sandbox/live selection. Implement the adapter against the current official bKash merchant documentation. Confirm token grant/refresh, create payment, execute/query payment, callback/redirect behavior, idempotency and refund/reconciliation semantics from the merchant-issued contract before enabling checkout.

## Nagad and DBBL Rocket

Only generic, optional credential slots are provided. The exact authentication scheme, required certificates/keys, endpoints, callback signatures, transaction query and refund behavior must come from the respective merchant onboarding documentation. Provider API hosts must be fixed to verified official endpoints in server-side code, never accepted as arbitrary admin-configured URLs. Do not infer or invent these protocols.

## Mandatory implementation and release gates

1. Add provider-specific server-side adapters and routes.
2. Store credentials through the Plugin Manager secret-setting mechanism; never expose secrets to browser code, student responses, logs or audit metadata.
3. Verify provider callbacks server-side and independently query provider transaction status where the official protocol supports it.
4. Bind each payment to the exact LMS transaction, invoice, merchant account, amount and currency.
5. Enforce idempotency and safe handling of duplicate, delayed, forged and out-of-order callbacks.
6. Only a provider-confirmed success may call PaymentSettlementService::settleTransaction.
7. Treat network timeouts and ambiguous provider responses as pending/reconciliation-required, never as confirmed failure or success.
8. Add HTTP-fake tests for success, failure, invalid signatures, mismatched references/amount/currency, duplicate callbacks, timeout, disabled gateway and credential redaction.
9. Complete merchant sandbox testing and independent finance/security review before enabling live mode.

The enabled setting is intentionally 0 for all gateways until these gates are complete.

## Public Rocket merchant-flow clarification

DBBL's public Rocket merchant-payment page describes an in-person flow in which the merchant initiates the payment from the merchant's mobile, issues a receipt, and the customer receives an SMS/IVR confirmation. It does not publish a web checkout API contract, API endpoints, authentication scheme or callback specification. Therefore this public flow must not be represented as an online LMS API integration. Obtain DBBL's separate corporate/e-commerce merchant API specification and sandbox access before implementing an automated Rocket checkout adapter. Reference: https://www.dutchbanglabank.com/rocket/merchant-payment.html.
