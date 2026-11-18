import 'dart:async';

import 'package:firebase_messaging/firebase_messaging.dart';

import 'api_client.dart';

/// Registers this signed-in app installation for server-sent FCM notifications.
class AecPushNotificationService {
  AecPushNotificationService(this.api);

  final MobileApi api;
  StreamSubscription<String>? _tokenRefreshSubscription;
  bool _initializing = false;
  bool _initialized = false;

  Future<void> initialize() async {
    if (_initializing || _initialized) return;
    _initializing = true;
    try {
      final messaging = FirebaseMessaging.instance;
      final settings = await messaging.requestPermission(
        alert: true,
        badge: true,
        sound: true,
      );
      if (settings.authorizationStatus == AuthorizationStatus.denied) {
        _initialized = true;
        return;
      }

      final token = await messaging.getToken();
      if (token != null && token.isNotEmpty) {
        await api.registerPushToken(token);
      }
      _tokenRefreshSubscription ??= messaging.onTokenRefresh.listen((nextToken) async {
        try {
          await api.registerPushToken(nextToken);
        } catch (_) {
          // The next authenticated app start retries registration.
        }
      });
      _initialized = true;
    } catch (_) {
      // Firebase is optional; the LMS remains usable without Firebase/APNs setup.
      _initialized = false;
    } finally {
      _initializing = false;
    }
  }

  Future<void> dispose() async {
    await _tokenRefreshSubscription?.cancel();
    _tokenRefreshSubscription = null;
  }
}
