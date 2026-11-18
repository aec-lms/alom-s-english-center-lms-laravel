import 'package:flutter_test/flutter_test.dart';
import 'package:aloms_student_app/qr_attendance_page.dart';

void main() {
  test('accepts a 64-character token from the attendance URL', () {
    final token = List.filled(64, 'a').join();
    expect(attendanceTokenFromQr('https://example.test/attendance/qr/check-in?token=$token'), token);
  });

  test('rejects malformed and non-attendance QR payloads', () {
    expect(attendanceTokenFromQr('https://example.test/not-attendance'), isNull);
    expect(attendanceTokenFromQr('abc123'), isNull);
  });
  test('never sends GPS coordinates without explicit consent', () {
    final payload = attendanceCheckinPayload(
      token: List.filled(64, 'b').join(),
      latitude: 23.7,
      longitude: 90.4,
      accuracyMeters: 12,
    );

    expect(payload.keys, ['token']);
    expect(payload.containsKey('latitude'), isFalse);
    expect(payload.containsKey('gps_consent'), isFalse);
  });

  test('includes foreground GPS only after consent', () {
    final payload = attendanceCheckinPayload(
      token: List.filled(64, 'c').join(),
      gpsConsent: true,
      latitude: 23.7,
      longitude: 90.4,
      accuracyMeters: 12.4,
    );

    expect(payload['gps_consent'], isTrue);
    expect(payload['latitude'], 23.7);
    expect(payload['longitude'], 90.4);
    expect(payload['gps_accuracy_meters'], 12);
  });

}
