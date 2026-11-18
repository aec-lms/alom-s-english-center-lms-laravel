import 'package:flutter_test/flutter_test.dart';
import 'package:aloms_student_app/api_client.dart';
import 'package:aloms_student_app/support_tickets_page.dart';

class FakeMobileApi extends MobileApi {
  FakeMobileApi() : super(baseUrl: 'https://example.test/api/v1');

  final List<Map<String, dynamic>> tickets = [];
  final List<String> requestedPaths = [];

  @override
  Future<Map<String, dynamic>> get(String path) async {
    requestedPaths.add(path);
    if (path == '/my/support-tickets') {
      return {'data': tickets};
    }
    return {'data': <String, dynamic>{}};
  }

  @override
  Future<Map<String, dynamic>> postJson(
    String path,
    Map<String, dynamic> body,
  ) async {
    requestedPaths.add(path);
    if (path == '/my/support-tickets') {
      tickets.add({
        'id': 1,
        'subject': body['subject'],
        'status': 'open',
        'last_message': body['message'],
      });
      return {'data': tickets.last};
    }
    return {'message': 'Reply sent.'};
  }
}

void main() {
  testWidgets('shows an empty support state and creates a student-owned ticket',
      (tester) async {
    final api = FakeMobileApi();
    await tester.pumpWidget(
      MaterialApp(home: SupportTicketsPage(api: api)),
    );
    await tester.pumpAndSettle();

    expect(find.text('You have no support requests yet.'), findsOneWidget);
    expect(api.requestedPaths, contains('/my/support-tickets'));

    await tester.tap(find.text('New request'));
    await tester.pumpAndSettle();

    await tester.enterText(find.byType(TextField).at(0), 'Course access help');
    await tester.enterText(
      find.byType(TextField).at(1),
      'Please help me access my enrolled course.',
    );
    await tester.tap(find.text('Submit'));
    await tester.pumpAndSettle();

    expect(api.requestedPaths, contains('/my/support-tickets'));
    expect(find.text('Course access help'), findsOneWidget);
    expect(find.text('open · Please help me access my enrolled course.'), findsOneWidget);
  });
}
