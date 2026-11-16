# Mobile API and Native App Release Boundary

The mobile-app-api plugin in this repository is the Laravel backend API and administration layer. It is not a compiled native iOS or Android application.

## Backend API

- Public configuration and app version policy: GET /api/mobile/config
- Public learning catalogue: GET /api/mobile/home and GET /api/mobile/courses
- Account registration and authentication: POST /api/mobile/register and POST /api/mobile/login
- Authenticated profile and course enrollment data: GET /api/mobile/me
- Authenticated device token registration: POST /api/mobile/push-token

Authenticated routes use Laravel Sanctum bearer tokens. Device tokens are stored against a user and platform and can be revoked with the account token.

## Admin setup

1. Open Admin → Mobile App Management.
2. Keep iOS and Android disabled until actual native apps have been built and published.
3. When a store release exists, enable the platform and enter the real bundle/package ID, store URL, minimum supported version and latest published version.
4. Configure the actual deep-link scheme and push provider credentials in their separate provider integration.
5. Save and test version checks from the native client before enabling force-update behavior.

The fresh-install defaults intentionally contain no invented store listing URLs, bundle IDs or release versions. Push token registration is disabled until configured.

## Not included in this Laravel backend

- Xcode/iOS native project, signing certificates, provisioning profiles or App Store submission.
- Android Gradle project, signing keystore or Play Store submission.
- APNs provider credentials or Firebase service-account credentials.
- Published App Store or Google Play binaries.

These require the separately maintained native application projects and merchant/developer accounts. The Laravel API does not claim that a native app is published merely because the API endpoints exist.
