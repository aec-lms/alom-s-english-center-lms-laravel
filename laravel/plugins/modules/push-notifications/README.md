# Firebase Push Notifications

This plugin sends Android and iOS notifications through Firebase Cloud Messaging HTTP v1. It can be installed independently, but delivery requires the Mobile App API and Notification Center plugins to be enabled.

## Admin setup

1. Create or select a Firebase project and enable Firebase Cloud Messaging.
2. Create a restricted service account with permission to send FCM messages.
3. In Admin → Plugins → Push Notifications, configure:
   - Firebase Project ID
   - Service Account Email
   - Service Account Private Key (PEM)
4. Enable Mobile App API and Notification Center.
5. Build the student and instructor apps with the Firebase compile-time values below; after sign-in each app requests notification permission and registers its current FCM token with the LMS. Token refreshes are registered automatically.

For both Flutter apps, supply the Firebase project values at build time (do not put the service-account private key in the app):

```bash
--dart-define=FIREBASE_API_KEY=... \\
--dart-define=FIREBASE_APP_ID=... \\
--dart-define=FIREBASE_MESSAGING_SENDER_ID=... \\
--dart-define=FIREBASE_PROJECT_ID=... \\
--dart-define=FIREBASE_IOS_BUNDLE_ID=...
```

For iOS, configure the APNs authentication key/capability in Firebase and the Apple developer account. Android 13+ requires notification permission; the student app manifest declares it. The server-side Firebase service-account private key is configured only in the encrypted LMS plugin settings.

Private keys are stored through the encrypted plugin-settings mechanism. Never commit service-account JSON or private keys to the repository.

## Delivery behavior

- Notifications are queued and delivered by the existing notification queue worker.
- Successful per-device receipts are retained so a retry does not resend to devices already accepted by FCM.
- Invalid/unregistered FCM tokens are deactivated automatically.
- Temporary provider errors are retried by the queue; provider secrets are never written to delivery logs.
- FCM acceptance means the provider accepted the request, not that the handset displayed it.
