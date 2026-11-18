# AL Branding & Loader and Phonetics — Integration Review

**Source:** `alom-english-center-loading-screen (6).zip` (23 files; 132,698 bytes uncompressed)  
**Target:** AEC LMS Laravel 12 application under `/laravel`  
**Branch:** `feat/alom-loader-phonetics-brand-integration`  
**Release status:** Not merged or production-approved. Final CI and package verification are still required.

## Required architecture

1. **AL Branding & Loader** is one plugin with two separate feature areas:
   - **AL Branding** links to the existing Core Site Identity settings for logo, favicon, site identity and tagline, avoiding a competing identity store.
   - **Loader** has its own settings and branded page/link loading screen.
2. **Phonetics** is a separate independent plugin. It has its own manifest, provider, routes, permissions, models, migrations and views. Course Management is optional, not a hard dependency.
3. Neither plugin depends on the other. Do not create a third Branding plugin and do not combine Phonetics with Branding/Loader.

## Implemented on the integration branch

### AL Branding & Loader
- Native `al-branding-loader` manifest and `BasePluginServiceProvider`.
- Separate Branding entry point and Loader configuration screen.
- Loader options include headline/subtext, optional raster logo, animation style, colors, blur, progress indicator, reduced-motion preference, minimum/fade/timeout durations, trigger mode and excluded paths.
- Loader renders in the shared application layout only when the plugin is enabled and the current path is not excluded.
- Settings updates are permission-protected and audited; newly uploaded media is stored before the old logo is removed.

### Independent Phonetics
- Native `phonetics` plugin with dedicated permission and admin menu.
- UK/US IPA catalogue, pronunciation notes, example sentences, safe card images, UK/US audio uploads and playback, optional slow playback, per-card colors and animations, named theme profiles and custom colors.
- Admins can preview, replace and explicitly remove existing audio/image files. Words are archived rather than hard-deleted so practice history and media remain available.
- Practice challenges are issued server-side, expire after 15 minutes and are consumed once. The attempt endpoint accepts only the selected answer; correctness is calculated server-side. Client-supplied entry IDs, accent, quiz mode and correctness flags are ignored.
- Course scope and active enrollment are checked on both practice display and attempt submission. If enrollment enforcement is enabled but Course Management tables are unavailable, the endpoint fails closed.
- Loader and Phonetics settings/content changes write audit-log records.
- SVG uploads are rejected. Media uses random filenames under plugin-specific public upload directories; replacements are persisted before old files are deleted, and failed database writes clean up newly stored files.

### Tests added or extended
- Plugin manifest independence and optional Course Management dependency.
- Admin route permission middleware.
- Enrollment enforcement and client score-tampering regression coverage.
- Safe media uploads, SVG rejection, explicit media removal and cleanup.
- Named theme profile and slow-playback setting.
- Loader settings, Core Site Identity link and audit record.
- Independent enable/disable/reinstall behavior and Phonetics data retention after uninstall without purge.

## Verification and remaining release gates

- The original ZIP passed integrity checking and all 21 supplied PHP files passed `php -l` on PHP 8.4.24. Those checks applied to the original ZIP, not to the current integration branch.
- A previous implementation checkpoint passed 301 Laravel tests, 301 MySQL tests, Browser E2E, dependency audit and cPanel package verification. Those results predate the latest implementation changes and do not certify this branch.
- The latest code changes have not yet received a complete successful CI result. The branch must pass Laravel tests, MySQL migration/schema tests, Browser E2E and dependency audit before merge.
- Still required: verify all new PHP/Blade files through CI, test migration rollback on MySQL, confirm media cleanup under failed uploads/database writes, and build/inspect the final cPanel ZIP from the exact approved commit.
- No production deployment or production database changes have been made.

**Decision:** Keep PR #104 open and unmerged until all current-head CI checks pass and the final installable package is verified.