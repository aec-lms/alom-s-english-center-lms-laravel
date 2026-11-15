# Isolated Plugin Package Manager

The LMS core is not a plugin installation target. The updater stores packages only under the private directory returned by `aec_private_storage_dir('plugin-manager')`, outside the public document root. It never writes into the application source tree, public web root, or LMS database schema.

## Security model

- Only an authenticated `super_admin` can list, install, or roll back packages; POST operations require the LMS CSRF token.
- Packages are signed with HMAC-SHA256 over the exact `manifest.json` bytes. Configure `AEC_PLUGIN_SIGNING_KEY` as a random secret of at least 32 characters in the hosting environment or private server configuration. Never commit or expose it.
- ZIP entries must exactly match the manifest. Only JSON files under `payload/` are accepted; PHP, JavaScript, HTML, CSS, binaries, path traversal, undeclared files, and oversized entries are rejected.
- Every payload SHA-256 is checked. Upload limit: 5 MB; up to 50 JSON payloads, each at most 256 KB.
- A package is installed into a staging directory and switched into the active data directory by rename. Existing JSON payloads and metadata are snapshotted before replacement.
- New packages are installed disabled. This endpoint deliberately does not execute plugin code. Runtime integrations must be implemented by reviewed, first-party LMS adapters; arbitrary third-party executable plugins are not supported.
- All data is private and is not web-served. The endpoint creates an audit log entry for successful installs.

## Package layout

```
manifest.json
signature.txt
payload/settings.json
payload/catalog.json
```

Example manifest (the `files` values are SHA-256 hashes of the exact payload bytes):

```json
{
  "id": "example_connector",
  "name": "Example Connector",
  "version": "1.2.0",
  "files": {
    "payload/settings.json": "64-character-lowercase-sha256"
  }
}
```

Generate the signature as the lowercase hex HMAC-SHA256 of the exact manifest file using `AEC_PLUGIN_SIGNING_KEY`. Do not include the signature itself in the manifest.

## API

- `GET /api/plugin-updater.php?action=list` — list installed data packages.
- `POST /api/plugin-updater.php?action=install` — multipart form upload; field name `package`, plus the authenticated LMS session and `X-CSRF-Token` header.
- `POST /api/plugin-updater.php?action=rollback` — JSON body `{"id":"example_connector","snapshot":"YYYYMMDD_HHMMSS_abcdef"}`, plus CSRF header.

The updater requires PHP 8.3+, PDO MySQL, and the PHP ZIP extension. The storage path must be writable by PHP and outside the public document root. If these prerequisites or the signing key are missing, updates fail closed and the LMS core continues to operate.

## Operational safeguards

1. Take a hosting-level database and file backup before the first production rollout.
2. Configure the signing key out-of-band; never paste it into GitHub or the LMS UI.
3. Test installation and rollback on a staging copy first.
4. Keep the existing Plugin Center UI as a catalog/configuration surface until it is connected to this API; it does not itself upload/install packages.
5. This is not a full LMS release updater. It updates data-only plugin packages, not application PHP/JS code or database migrations.
