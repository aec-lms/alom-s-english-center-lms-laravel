import 'package:material_ui/material_ui.dart';
import 'package:mobile_scanner/mobile_scanner.dart';
import 'api_client.dart';

String? attendanceTokenFromQr(String raw) {
  final uri = Uri.tryParse(raw);
  final token = uri?.queryParameters['token'] ?? raw.trim();
  return RegExp(r'^[a-f0-9]{64}$').hasMatch(token) ? token : null;
}

class QrAttendancePage extends StatefulWidget {
  const QrAttendancePage({required this.api, super.key});
  final MobileApi api;
  @override
  State<QrAttendancePage> createState() => _QrAttendancePageState();
}

class _QrAttendancePageState extends State<QrAttendancePage> {
  bool busy = false, done = false;
  String? message;
  Future<void> submit(String raw) async {
    if (busy || done) { return; }
    final token = attendanceTokenFromQr(raw);
    if (token == null) { setState(() => message = 'Invalid Alom attendance QR code.'); return; }
    setState(() { busy = true; message = null; });
    try {
      final result = await widget.api.postJson('/attendance/qr/check-in', {'token': token});
      if (mounted) { setState(() { done = true; message = result['message']?.toString() ?? 'Attendance submitted.'; }); }
    } catch (e) {
      if (mounted) { setState(() => message = e.toString()); }
    } finally { if (mounted) { setState(() => busy = false); } }
  }
  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const Text('QR Attendance')),
    body: Column(children: [
      Expanded(child: MobileScanner(onDetect: (capture) {
        final value = capture.barcodes.isEmpty ? null : capture.barcodes.first.rawValue;
        if (value != null) submit(value);
      })),
      if (busy) const LinearProgressIndicator(),
      if (message != null) Padding(padding: const EdgeInsets.all(16), child: Text(message!, textAlign: TextAlign.center)),
      if (done) Padding(padding: const EdgeInsets.only(bottom: 24),
        child: FilledButton(onPressed: () => Navigator.pop(context), child: const Text('Done'))),
    ]),
  );
}
