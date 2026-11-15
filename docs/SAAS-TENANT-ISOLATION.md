# SaaS tenant isolation: security model and activation gate

**Status: NOT IMPLEMENTED — SaaS tenant provisioning and activation must remain disabled.**

The current SaaS tables are a control-plane registry only. They store tenant metadata, plan/subscription records and custom-domain ownership challenges. They do not create isolated tenant databases, scope the LMS operational APIs, or establish a tenant-specific data plane. A verified custom domain is proof of domain control only; it is not proof of data isolation.

## Chosen data-plane direction

Use a dedicated database (or schema with an independently scoped database account where the host supports it) for each tenant. Keep the central registry database separate and limited to tenant lifecycle metadata. Do not retrofit a shared tenant_id filter into every current table as a shortcut: the existing application has many cross-linked modules, reports, uploads, background tasks and financial ledgers, and one missed predicate could disclose another school's data.

Each tenant database must have:
- A unique database and least-privilege database account.
- Its own migration state and backup/restore lifecycle.
- A tenant-specific private-storage root outside every public document root.
- A tenant-scoped application connection selected only by trusted server-side routing.
- Independent audit and retention policy.

## Trusted tenant resolution

1. Normalize the incoming Host header and reject malformed or ambiguous host values.
2. Resolve the exact host against a verified, active domain record in the central registry.
3. Do not trust a client-supplied tenant ID, query parameter, request body, cookie, forwarded-host header or unverified subdomain.
4. The resolved tenant context is created server-side after the domain lookup and is immutable for the request.
5. Resolve the tenant database credentials from private server-side configuration/secrets; never return them to the browser.
6. If host resolution, tenant status, database health or isolation checks fail, deny the request. Never fall back to the central registry connection for tenant data.

Reverse proxies must be explicitly configured with a trusted-proxy allowlist before forwarded host headers are considered. DNS verification alone does not activate a tenant.

## Isolation coverage required before activation

Every data-bearing surface must use the resolved tenant database/context, including:
- Authentication, sessions, users, roles and permissions.
- Courses, lessons, enrollments, exams, attendance, grades and certificates.
- CMS, notices, marketing, Boost campaigns and analytics.
- Payments, refunds, invoices, journals, expenses, commissions and reports.
- Uploads, media downloads, exports, caches, search indexes and temporary files.
- Email/SMS queues, scheduled tasks, webhooks, notifications and audit logs.
- AI proposals/backups, plugin package metadata and support diagnostics.

No tenant may be activated until automated negative tests prove tenant A cannot read, infer, mutate, export or download tenant B data by changing IDs, hostnames, request headers, query parameters or object paths.

## Current fail-closed controls

- Tenant registration creates only a pending registry record and returns provisioned=false.
- Domain challenges are cryptographically random; only a SHA-256 hash is stored and the challenge expires after 24 hours.
- Challenge creation is limited to three requests per domain per hour.
- DNS verification is limited to ten attempts per domain per hour.
- Verified domain ownership does not provision or activate a tenant.
- The API rejects every request to set a tenant to active, including reactivation from suspended, until data-plane provisioning and cross-tenant tests exist.
- Do not manually change tenant status in SQL or bypass these checks.

## Provisioning lifecycle (future implementation)

A separate reviewed provisioning service must:
1. Create the isolated database, database user, private storage and tenant-specific secrets.
2. Apply the complete migration set and seed only tenant-local data.
3. Run schema, permission and isolation probes.
4. Verify the custom domain and bind it to the immutable tenant context.
5. Create the first tenant Admin through a one-time, expiring invitation.
6. Produce a tenant backup and restore rehearsal.
7. Atomically mark the tenant provisioned only after all checks pass, with an audit event and idempotency key.

Suspension must deny new sessions and revoke active sessions. Deletion must use an explicit retention/export policy and never cascade-delete a tenant's data without an independently verified backup.

## Release gate

Issue #10 remains open until the provisioning service, full tenant-scoped request routing, all-module negative tests, lifecycle controls, security review and CI evidence are complete. The current LMS is a single-institution deployment; the registry must not be represented as a functioning multi-tenant SaaS product.
