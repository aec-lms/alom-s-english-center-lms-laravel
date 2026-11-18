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
}
