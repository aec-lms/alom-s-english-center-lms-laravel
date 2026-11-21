# Alom's English Center — Project Branding and Naming Standard

This is a mandatory repository-wide rule for all current and future development.

## First-party identity

- The product name is **Alom's English Center**.
- Use the repository/project identity `aec` or a clear Alom's English Center name for internal packages, namespaces, folders, routes, assets, UI labels, documentation and generated artifacts.
- Product-facing interfaces must present the LMS as Alom's English Center, not as a code-generation platform, template vendor or third-party AI tool.

## Prohibited branding residue

- Do not introduce Google AI Studio, Google Studio, Gemini, or any other external AI builder/platform name as a product name, folder, module, package, namespace, UI label, route prefix, asset name, documentation heading or default configuration value.
- Do not copy template/demo branding, vendor logos, sample organization names, generated-by notices, hidden metadata or attribution strings into the shipped product.
- Do not create folders or files named after an external AI builder.
- Existing third-party libraries may retain legally required upstream package names and license/copyright notices. Do not rename dependencies in ways that break Composer/npm resolution or remove legally required notices. Keep those references confined to dependency metadata and license notices, not the LMS product identity.

## Review checklist for every PR

1. Search changed files and relevant repository paths for external builder/platform names, vendor branding, demo names and generated-by comments.
2. Inspect file paths, namespaces, route names, config keys, database seeds, UI strings, email templates, PDFs, exports, ZIP contents and deployment docs.
3. Replace inappropriate product-facing residue with Alom's English Center / `aec` naming.
4. Preserve required open-source license notices, dependency identifiers, API protocol names and factual compatibility documentation.
5. Add a regression check when a branding leak could reappear through generated assets, seeds, installer output or release packaging.
6. Do not mark the branding audit complete based only on a search returning no matches; inspect relevant paths and the actual changed files.

## Scope

This standard applies to the core LMS, every plugin/module, installer, admin and learner UI, mobile/PWA assets, API, documentation, tests, CI workflows, release ZIP and future feature branches.
