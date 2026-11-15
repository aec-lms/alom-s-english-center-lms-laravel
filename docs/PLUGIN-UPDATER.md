# Laravel plugin manager and signed update center

This document applies to the Laravel 12 application under `/laravel`. The earlier root-level JSON-only plugin updater is superseded and is not the Laravel plugin runtime.

## Plugin Center

The Admin Plugin Center is available at:

```text
/admin/plugins
```

The authenticated Admin role with `core.plugins.manage` permission can:

- Discover and synchronize plugin manifests.
- Install catalogued modules and enforce declared dependencies.
- Enable, disable and uninstall modules independently.
- Upload a plugin ZIP, update a plugin from a package, and configure plugin settings.
- Store secret plugin settings encrypted using Laravel Crypt.
- Preserve business data on uninstall; plugin uninstall does not drop shared student, teacher, payment or enrollment records.

Page Builder, Slider Builder, Carousel Builder and each payment gateway are separate plugin modules.

## Plugin ZIP format

The archive must contain either a root `plugin.json` or one top-level directory containing it. The same package root must include `checksums.json`.

Example package:

```text
plugin.json
checksums.json
Providers/ExampleServiceProvider.php
Database/Migrations/2026_11_15_000001_create_example_tables.php
Http/Controllers/ExampleController.php
Models/ExampleRecord.php
Routes/web.php
Resources/views/index.blade.php
```

The `checksums.json` object maps every package file except `checksums.json` itself to its lowercase SHA-256 hash. It must include `plugin.json`. Every file under the package root must be listed and verified; unlisted files, malformed hashes and unsafe paths are rejected.

Example checksum file:

```json
{
  "plugin.json": "64-character-lowercase-sha256",
  "Providers/ExampleServiceProvider.php": "64-character-lowercase-sha256"
}
```

Plugin providers must use a namespace rooted under `Plugins\\Modules\\YourPlugin\\Providers\\`, extend `BasePluginServiceProvider`, and implement `PluginInterface`. The Core dynamically registers the module namespace with Composer's autoloader when it discovers the manifest.

## Important trust boundary

Plugin ZIPs may contain PHP code and Laravel database migrations. They are executable application code, not passive data packages. The ZIP uploader is protected by Admin authorization and strict archive/checksum checks, but checksums provide integrity only; they do not prove publisher identity. Upload only reviewed packages from a trusted source. Never upload an arbitrary third-party ZIP to a production LMS.

Archive protections include:

- ZIP path traversal, absolute paths, Windows paths, symlinks and duplicate entries are rejected.
- Maximum 5,000 entries, 256 MiB per uncompressed entry and 1 GiB total uncompressed content.
- Executable binary/script extensions such as PHAR, PHTML, shell scripts, EXE, DLL and BAT are blocked.
- Plugin packages are staged before deployment; failed operations attempt to restore the previous plugin files and catalog state.
- Uploaded ZIPs and staging directories are deleted after processing.

The Core plugin cannot be replaced through the Plugin Center ZIP uploader. Plugin dependencies are checked before install/enable/disable/uninstall.

## Signed Core and plugin updates

The Universal Update Center is separate from Plugin Center and accepts signed ZIP packages through:

```text
/admin/updates
```

The Admin supplies:
- The SHA-256 hash of the exact ZIP bytes.
- The HMAC-SHA256 signature of that hash, using the private `AEC_PLUGIN_SIGNING_KEY`.
- The signed ZIP containing `update-manifest.json` and the payload files.

The manifest must specify an explicit `target_type` (`core` or `plugin`), a valid target slug, a semantic version newer than the installed version, and compatible Core constraints. Plugin updates require an already-installed plugin. Core updates must target the configured Core identifier.

The update service rejects unsafe/duplicate/symlink/oversized ZIP entries, checks the signature before applying, creates a pre-update encrypted backup, stages files, runs migrations and records an audit/update log. Automatic rollback is best-effort; MySQL DDL can auto-commit, so always test updates on a staging copy and take a verified external backup first.

Remote update feeds must use public HTTPS hostnames on port 443 without credentials, query strings or fragments. Feed checks reject private/reserved DNS addresses and do not follow redirects.

## Operations and limitations

1. Keep `AEC_PLUGIN_SIGNING_KEY` private in the server `.env`; never commit it or paste it into chat.
2. Build plugin packages from reviewed source and generate a complete checksums file before ZIP packaging.
3. Test install, update, disable, uninstall and rollback on a disposable staging installation.
4. Keep a verified database/private-file backup before plugin or Core updates.
5. Do not disable or uninstall a plugin while enabled modules depend on it.
6. The plugin catalog currently contains 39 manifests, but not all modules have complete business workflows or production acceptance tests.
7. The updater and Plugin Center have not yet passed a full live cPanel rehearsal. Do not treat CI success as proof of production readiness.
