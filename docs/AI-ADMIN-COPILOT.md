# AI Admin Copilot

## Current capability

The Admin Panel includes an administrator-only AI assistant for LMS guidance and a restricted set of safe changes. The model may propose one typed action; it never executes it directly.

Supported action types:
- Update an allowlist of marketing and SEO settings (GA4, GTM, Meta Pixel ID, Search Console verification token, SEO title/description, canonical URL and robots text).
- Create or update a Notice Board item; publish, draft or archive an item.
- Create a course/institution/branch Boost campaign; activate, pause or cancel a campaign.

No user roles/passwords, payments, financial records, arbitrary course SQL, plugin code, PHP, shell commands, filesystem changes or deployment actions are supported.

## Approval and rollback workflow

1. The model returns a structured JSON reply and, if appropriate, a typed proposal.
2. The server validates the action type and every field against a strict allowlist.
3. The Admin Panel asks the server for a read-only before/after preview. Proposals expire after ten minutes.
4. The proposal is bound to the creating Admin. A different Admin cannot approve or roll it back.
5. To apply, the Admin must type the exact phrase `APPLY`. The server revalidates the payload, locks the target row, checks that the data has not changed since preview, stores a before-image backup, applies the change in a database transaction and writes an audit event.
6. To undo, the Admin must type `ROLLBACK`. The server checks that the target still matches the applied snapshot; if someone changed it afterward, rollback is refused rather than overwriting newer work.

Proposal metadata and backups are stored in the private LMS database tables `ai_action_proposals` and `ai_action_backups`. Chat prompts and normal replies are not persisted by the assistant endpoint. Do not put passwords, API keys, payment data or other secrets in prompts.

## Server configuration

Add these values to the private configuration file outside the public document root (the installer uses `$HOME/.aec-lms/config.php`):

```php
return [
    // Keep this credential server-side and never commit it.
    'AEC_AI_API_KEY' => 'YOUR_PROVIDER_API_KEY',
    'AEC_AI_MODEL' => 'gpt-4.1-mini',
];
```

The API key is read only by PHP. The assistant endpoint requires an active Admin session and CSRF protection, accepts up to 4,000 characters and is limited to 12 requests per 10 minutes per session. Provider failures are logged without credentials or response bodies.

## Further safe expansion

New action types must be added as typed server handlers with validation, preview, before-image backup, explicit approval, audit and tested rollback. Code changes must continue through isolated Git branches, CI and pull requests. Never let the chat agent push to `main` or edit live files.

The plugin package updater supports signed data-only plugin packages; it is not a PHP/JavaScript runtime or a whole-application code deployer.
