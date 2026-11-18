# Plugin ZIP signature key management

Alom's English Center requires a trusted detached Ed25519 signature for every plugin ZIP uploaded through Admin → Plugin Center. First-party modules shipped with the application remain managed by the signed application release; external ZIP uploads fail closed when unsigned or signed by an unknown key.

## Security model

- Generate the signing key pair on a trusted offline workstation, not on cPanel.
- Keep the private key outside the repository, LMS server, web root, GitHub Actions logs and chat. Keep an encrypted backup in an approved offline vault.
- Put only the base64 public key in the LMS private .env file.
- Use a distinct key ID for each release key. Retain old public keys while packages signed by them remain installed.
- The signing helper verifies every package file against checksums.json, rejects symlinks/untracked files, and signs the plugin slug, version, checksum-manifest hash and plugin-manifest hash.

## 1. Generate a release key pair

From a trusted computer with PHP 8.3+ and the sodium extension:

    php tools/plugin-signing.php generate /secure/offline/aec-plugin-release-2026

The command creates:

- plugin-release-private.key — secret Ed25519 key, mode 0600; never upload or commit it.
- plugin-release-public.key — public key; safe to place in the private LMS configuration.

Keep the key directory backed up securely. Loss of the private key prevents signing future releases under that key. Never replace an existing key directory.

## 2. Configure the trusted public key

Read the single-line base64 contents of plugin-release-public.key. In the Laravel application's private .env, set:

    LMS_PLUGIN_SIGNATURE_PUBLIC_KEYS='{"release-2026":"BASE64_PUBLIC_KEY_HERE"}'

For rotation, include both old and new public keys until every installed package has been re-signed and verified:

    LMS_PLUGIN_SIGNATURE_PUBLIC_KEYS='{"release-2026":"OLD_BASE64_PUBLIC_KEY","release-2027":"NEW_BASE64_PUBLIC_KEY"}'

Do not put a private key in .env. After changing configuration, clear/rebuild Laravel config cache from the application root using the hosting operator's approved process.

## 3. Sign a plugin package

Prepare the plugin directory with plugin.json and a correct checksums.json. The checksums file must contain the SHA-256 for every regular package file except checksums.json and the detached signature sidecar itself.

    php tools/plugin-signing.php sign /path/to/plugin-directory release-2026 /secure/offline/aec-plugin-release-2026/plugin-release-private.key /secure/output/plugin-signature.json

The helper stops if a file is missing, changed, unchecksummed, symlinked, or unsafe. It writes a detached JSON signature outside the plugin directory. Package the plugin directory as a ZIP and upload both the ZIP and signature JSON in Admin → Plugin Center.

The uploaded signature file must be the sidecar created for the exact same plugin version and exact same package bytes. Do not edit files or regenerate checksums.json after signing.

## 4. Key rotation and incident response

1. Add the new public key to the trusted public-key JSON while retaining the old key.
2. Sign and publish new plugin versions using the new key ID.
3. Update installed packages through the signed ZIP workflow and confirm the Admin Plugin Center displays the new key ID.
4. Remove the old public key only after all packages signed by it are updated, or after deliberately uninstalling those packages.
5. If a private key is suspected compromised, remove its public key from trust configuration, review plugin-signature audit events, and re-sign trusted packages with a new key.

Never disable signature checks to work around an unknown key or failed signature. Resolve the package/key mismatch instead.
