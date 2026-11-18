# Firebase Push Notifications

This plugin sends Android and iOS notifications through Firebase Cloud Messaging HTTP v1. It is optional and does not require Notification Center or Mobile App API to be installed.

## Admin setup

1. Create or select a Firebase project and enable Firebase Cloud Messaging.
2. Create a restricted service account with permission to send FCM messages.
3. In Admin → Plugins → Push Notifications, configure:
   - Firebase Project ID
   - Service Account Email
   - Service Account Private Key (PEM)
4. Enable Mobile App API and Notification Center.
5. Ensure each app user has registered a device push token.

Private keys are stored through the encrypted plugin-settings mechanism. Never commit service-account JSON or private keys to the repository.

## Delivery behavior

- Notifications are queued and delivered by the existing notification queue worker.
- Successful per-device receipts are retained so a retry does not resend to devices already accepted by FCM.
- Invalid/unregistered FCM tokens are deactivated automatically.
- Temporary provider errors are retried by the queue; provider secrets are never written to delivery logs.
- FCM acceptance means the provider accepted the request, not that the handset displayed it.
