import 'dart:async';

import 'package:firebase_messaging/firebase_messaging.dart';

import 'api_client.dart';

typedef AecPushMessageHandler = void Function(String title, String body);

/// Registers this signed-in app installation and surfaces foreground FCM messages.
class AecPushNotificationService {
  AecPushNotificationService(this.api, {this.onMessage});

  final MobileApi api;
  final AecPushMessageHandler? onMessage;
  StreamSubscription<String>? _tokenRefreshSubscription;
  StreamSubscription<RemoteMessage>? _foregroundSubscription;
  StreamSubscription<RemoteMessage>? _openedSubscription;
  bool _initializing = false;
  bool _initialized = false;

  void _notify(RemoteMessage message) {
    final title = message.notification?.title ?? 'Alom English Center';
    final body = message.notification?.body ?? '';
    onMessage?.call(title, body);
  }

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
      _foregroundSubscription ??= FirebaseMessaging.onMessage.listen(_notify);
      _openedSubscription ??= FirebaseMessaging.onMessageOpenedApp.listen(_notify);

      final initialMessage = await messaging.getInitialMessage();
      if (initialMessage != null) _notify(initialMessage);

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
    await _foregroundSubscription?.cancel();
    await _openedSubscription?.cancel();
    _tokenRefreshSubscription = null;
    _foregroundSubscription = null;
    _openedSubscription = null;
  }
}
