# AEC Project Identity Policy

## Product identity

This repository is the independently maintained **Alom's English Center Learning Management System (AEC LMS)**.

- Product name: **Alom's English Center LMS**
- Short name: **AEC LMS**
- Product ownership, documentation, user-facing language, and project conventions must consistently reflect Alom's English Center.
- Do not introduce external authoring-tool or assistant branding into repository names, branches, directories, filenames, namespaces, application labels, documentation titles, generated assets, or release packages.
- New project-owned files and directories must use clear AEC/LMS domain terminology.

## Engineering boundaries

- Keep the Laravel application and its established modular plugin architecture authoritative.
- Use vendor-specific names only where technically necessary for a real integration, protocol, package, API, or legally required notice. Such references must describe the actual dependency and must not imply ownership or rebrand the product.
- Preserve third-party copyright, license, attribution, and security notices where legally or contractually required.
- Do not remove or rename a genuine integration, dependency, configuration key, or protocol merely to satisfy a naming convention. First verify its runtime use, compatibility, migration impact, and tests.
- Review imported or externally authored code for security, licensing, maintainability, architecture fit, and AEC product consistency before acceptance.

## Change control

- Check the active ownership register, open issues, pull requests, and branches before editing shared modules or files.
- Use focused branches and pull requests; do not push directly to the default branch.
- Preserve existing data and backward compatibility. Any rename or removal must include a dependency check, migration/rollback plan where applicable, and regression coverage.
- Never describe work as complete until the exact final commit has passed the required checks and review gates.
