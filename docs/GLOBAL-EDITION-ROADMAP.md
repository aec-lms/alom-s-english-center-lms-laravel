# Global Edition: international product requirements and implementation gates

**Product direction:** ALOMS English Center LMS is the source product identity, while every customer installation must be configurable and must not inherit ALOMS institution-specific data, contact details, branding, locale, currency or timezone.

This document is the acceptance contract for turning the Laravel application into a product that schools, training centers, academies and education businesses can install in different countries. It does not claim that the future SaaS control plane is already active.

## Product principles

1. Laravel is the only supported application runtime. Core platform services live under `app/Core`; optional business capabilities live in independently managed plugins.
2. A fresh installation starts with neutral defaults and an empty institution profile. It must not seed real ALOMS students, staff, branches, payments, contact details or operational records.
3. A customer can configure their institution identity, logo, favicon, brand colors, typography, public site, portal appearance and plugin appearance from authorized admin screens without editing code.
4. All customer-specific settings are isolated. A theme, language, timezone, currency, plugin setting, email template or uploaded asset from one tenant must never affect another tenant.
5. The product must remain usable as a single-institution self-hosted installation. SaaS/multi-tenant mode is a separate capability and must remain fail-closed until its activation gates pass.

## International installation profile

Each installation must support the following settings, validated and stored as structured settings rather than scattered hard-coded constants:

- **Institution identity:** display name, legal name, logo, favicon, support email/phone, address, website, legal URLs and configurable sender identity.
- **Localization:** default locale, fallback locale, per-user preferred locale, translated validation/authentication/system messages, locale-aware date/number formatting and right-to-left layout where the chosen locale requires it.
- **Time:** IANA timezone at institution level, user-level display timezone where appropriate, UTC storage for timestamps, and explicit timezone conversion at input/output boundaries.
- **Currency:** ISO 4217 currency code, symbol/decimal formatting, currency precision, locale-aware display and explicit currency snapshots on financial transactions. Never reinterpret historical amounts when the default currency changes.
- **Regional formats:** date, time, week start, decimal/group separators, address and phone formats, tax identifiers and configurable academic-year boundaries.
- **Education model:** configurable academic terms, grading scales, attendance rules, class structures and terminology; country-specific rules must be opt-in profiles, not global assumptions.
- **Accessibility:** keyboard navigation, screen-reader labels, contrast-safe theme previews and reduced-motion support.

## Theme and white-label contract

The Core Appearance service provides validated semantic design tokens. Admins can change the global palette and supported layout options without code. Each installed plugin may declare supported appearance tokens and inherit the global theme by default; a plugin override is isolated and may be reset to inherited values. Plugins must not inject arbitrary CSS, HTML or JavaScript through theme settings. A plugin that uses bespoke hard-coded colors must be adapted to semantic tokens before it can claim full theme compatibility.

## Plugin product contract

Every plugin must have a stable slug, vendor/author, semantic version, compatibility range, license metadata, dependency declarations, permissions, migrations, settings schema, translation resources, documented events/API contracts and install/upgrade/disable/uninstall behavior. Core must expose a supported extension API; plugins must not modify Core files. Plugin failures must be isolated and must not silently disable security controls.

## Tenant and deployment modes

### Self-hosted single institution

- One installation owns one institution profile and one operational database.
- Installer collects the first Admin, institution identity, locale, timezone and currency.
- The first Admin can change these settings from the UI.
- Backups, private storage, mail, queue, cache and scheduled tasks are installation-scoped.

### SaaS / multi-tenant

The current tenant registry is control-plane metadata only. Tenant provisioning and activation remain disabled until the dedicated data plane is implemented and verified. The required design is a central registry plus a dedicated database, private storage root, secrets, queue/cache namespace and backup lifecycle per tenant. Tenant resolution must use verified host-to-tenant mapping and immutable server-side context. No client-supplied tenant ID may select a database.

Before activating any tenant, automated negative tests must prove tenant A cannot read, mutate, infer, export, cache, queue, download or restore tenant B data across every plugin and Core service. Suspension, export, deletion, retention, backup and restore require audited lifecycle controls.

## Global readiness acceptance gates

- [ ] Neutral install defaults; no ALOMS-specific timezone, mail sender or institution profile forced on buyers.
- [ ] Installer wizard captures institution name, locale, timezone, currency, country/region and initial Admin.
- [ ] Admin settings UI validates and updates institution identity, localization, currency and regional formats.
- [ ] All persisted timestamps use UTC; user-facing output uses configured timezone.
- [ ] Money is stored as integer minor units or exact decimal values with currency code; no floating-point financial arithmetic.
- [ ] All financial records snapshot transaction currency and amount; currency changes do not rewrite history.
- [ ] Translation catalog coverage and locale fallback tests exist; RTL layouts have browser tests.
- [ ] Every Core screen and plugin uses semantic appearance tokens or declares a compatibility exception.
- [ ] Plugin manifest validation enforces permissions, compatibility, dependencies, translations and settings schema.
- [ ] Tenant data-plane provisioning and cross-tenant isolation tests pass before SaaS activation.
- [ ] Self-hosted installation, upgrade, backup, restore and rollback are rehearsed on supported hosting.
- [ ] License, update-signature, privacy, retention and security documentation are complete before commercial distribution.

## Implementation sequence

1. **Foundation:** neutral locale/timezone configuration and clean installer defaults.
2. **Institution settings:** admin-editable identity, locale, timezone, currency and regional formats.
3. **Localization:** translation catalog, per-user locale, date/number/currency formatting and RTL.
4. **White-label appearance:** complete semantic token coverage across Core and all plugins.
5. **Plugin marketplace contract:** manifests, permissions, compatibility, licensing and signed updates.
6. **SaaS data plane:** dedicated tenant provisioning, trusted routing and cross-tenant negative tests.
7. **Commercial release:** privacy/legal docs, support diagnostics, license lifecycle and full external staging rehearsals.

Do not mark a gate complete based only on documentation or unit tests that do not exercise the corresponding real workflow.
