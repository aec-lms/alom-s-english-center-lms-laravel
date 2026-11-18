import 'package:material_ui/material_ui.dart';
import 'package:geolocator/geolocator.dart';
import 'package:mobile_scanner/mobile_scanner.dart';

import 'api_client.dart';

String? attendanceTokenFromQr(String raw) {
  final uri = Uri.tryParse(raw);
  final token = uri?.queryParameters['token'] ?? raw.trim();
  return RegExp(r'^[a-f0-9]{64}$').hasMatch(token) ? token : null;
}

Map<String, dynamic> attendanceCheckinPayload({
  required String token,
  bool gpsConsent = false,
  double? latitude,
  double? longitude,
  double? accuracyMeters,
}) {
  final payload = <String, dynamic>{'token': token};
  if (gpsConsent &&
      latitude != null &&
      longitude != null &&
      accuracyMeters != null) {
    payload.addAll({
      'gps_consent': true,
      'latitude': latitude,
      'longitude': longitude,
      'gps_accuracy_meters': accuracyMeters.round().clamp(1, 1000),
    });
  }
  return payload;
}

class QrAttendancePage extends StatefulWidget {
  const QrAttendancePage({required this.api, super.key});

  final MobileApi api;

  @override
  State<QrAttendancePage> createState() => _QrAttendancePageState();
}

class _QrAttendancePageState extends State<QrAttendancePage> {
  bool busy = false;
  bool done = false;
  String? message;

  Future<bool> askGpsConsent() async {
    final consent = await showDialog<bool>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: const Text('Location for attendance'),
        content: const Text(
          'The academy may require your current location to verify this class check-in. '
          'Your location is requested only now, while you are checking in; the app does not track you in the background.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(dialogContext, false),
            child: const Text('Continue without location'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(dialogContext, true),
            child: const Text('Share current location'),
          ),
        ],
      ),
    );
    return consent == true;
  }

  Future<Position> captureForegroundLocation() async {
    final enabled = await Geolocator.isLocationServiceEnabled();
    if (!enabled) {
      throw StateError('Turn on device location services or retry without location.');
    }

    var permission = await Geolocator.checkPermission();
    if (permission == LocationPermission.denied) {
      permission = await Geolocator.requestPermission();
    }
    if (permission == LocationPermission.denied ||
        permission == LocationPermission.deniedForever) {
      throw StateError('Location permission was not granted. You can retry without location.');
    }

    final position = await Geolocator.getCurrentPosition(
      locationSettings: const LocationSettings(
        accuracy: LocationAccuracy.high,
        timeLimit: Duration(seconds: 20),
      ),
    );
    if (position.accuracy > 100) {
      throw StateError('GPS accuracy is too low. Move to an open area and retry.');
    }
    return position;
  }

  Future<void> submit(String raw) async {
    if (busy || done) return;
    final token = attendanceTokenFromQr(raw);
    if (token == null) {
      setState(() => message = 'Invalid Alom attendance QR code.');
      return;
    }

    setState(() {
      busy = true;
      message = null;
    });

    try {
      final gpsConsent = await askGpsConsent();
      if (!mounted) return;

      Map<String, dynamic> payload;
      if (gpsConsent) {
        final position = await captureForegroundLocation();
        payload = attendanceCheckinPayload(
          token: token,
          gpsConsent: true,
          latitude: position.latitude,
          longitude: position.longitude,
          accuracyMeters: position.accuracy,
        );
      } else {
        payload = attendanceCheckinPayload(token: token);
      }

      final result = await widget.api.postJson(
        '/attendance/qr/check-in',
        payload,
      );
      if (mounted) {
        setState(() {
          done = true;
          message = result['message']?.toString() ?? 'Attendance submitted.';
        });
      }
    } catch (error) {
      if (mounted) setState(() => message = error.toString());
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: const Text('QR Attendance')),
        body: Column(
          children: [
            Expanded(
              child: MobileScanner(
                onDetect: (capture) {
                  final value = capture.barcodes.isEmpty
                      ? null
                      : capture.barcodes.first.rawValue;
                  if (value != null) submit(value);
                },
              ),
            ),
            if (busy) const LinearProgressIndicator(),
            if (message != null)
              Padding(
                padding: const EdgeInsets.all(16),
                child: Text(message!, textAlign: TextAlign.center),
              ),
            if (done)
              Padding(
                padding: const EdgeInsets.only(bottom: 24),
                child: FilledButton(
                  onPressed: () => Navigator.pop(context),
                  child: const Text('Done'),
                ),
              ),
          ],
        ),
      );
}
