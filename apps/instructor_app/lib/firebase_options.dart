import 'package:firebase_core/firebase_core.dart';

class AecFirebaseOptions {
  static FirebaseOptions? get currentPlatform {
    const apiKey = String.fromEnvironment('FIREBASE_API_KEY');
    const appId = String.fromEnvironment('FIREBASE_APP_ID');
    const senderId = String.fromEnvironment('FIREBASE_MESSAGING_SENDER_ID');
    const projectId = String.fromEnvironment('FIREBASE_PROJECT_ID');
    const iosBundleId = String.fromEnvironment('FIREBASE_IOS_BUNDLE_ID');

    if (apiKey.isEmpty || appId.isEmpty || senderId.isEmpty || projectId.isEmpty) {
      return null;
    }

    return FirebaseOptions(
      apiKey: apiKey,
      appId: appId,
      messagingSenderId: senderId,
      projectId: projectId,
      iosBundleId: iosBundleId.isEmpty ? null : iosBundleId,
    );
  }
}

Future<bool> initializeAecFirebase() async {
  final options = AecFirebaseOptions.currentPlatform;
  if (options == null) return false;

  try {
    if (Firebase.apps.isEmpty) {
      await Firebase.initializeApp(options: options);
    }
    return true;
  } catch (_) {
    // Push is optional; the LMS remains usable until Firebase app settings exist.
    return false;
  }
}
