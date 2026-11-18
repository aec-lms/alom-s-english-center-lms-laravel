import 'package:material_ui/material_ui.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:aloms_student_app/api_client.dart';
import 'package:aloms_student_app/student_exams_page.dart';

class FakeExamApi extends MobileApi {
  FakeExamApi() : super(baseUrl: 'https://example.test/api/v1');

  final List<Map<String, dynamic>> calls = [];

  @override
  Future<Map<String, dynamic>> get(String path) async {
    if (path == '/my/exams') {
      return {
        'data': [
          {
            'id': 8,
            'title': 'Present Perfect Test',
            'duration_minutes': 15,
            'pass_marks': 5,
          },
        ],
      };
    }
    throw MobileApiException('Unexpected GET $path', 404);
  }

  @override
  Future<Map<String, dynamic>> postJson(
    String path,
    Map<String, dynamic> body,
  ) async {
    calls.add({'path': path, 'body': body});
    if (path == '/my/exams/8/attempts') {
      return {
        'attempt': {
          'id': 41,
          'started_at': DateTime.now().toIso8601String(),
          'deadline': DateTime.now().add(const Duration(minutes: 5)).toIso8601String(),
        },
        'questions': [
          {
            'id': 90,
            'prompt': 'Choose the correct form.',
            'options': ['has went', 'has gone', 'have went'],
            'marks': 5,
          },
        ],
      };
    }
    if (path == '/my/exams/8/attempts/41/submit') {
      expect(body['answers'], {'90': 1});
      return {
        'data': {
          'attempt_id': 41,
          'score': 5,
          'total_marks': 5,
          'passed': true,
        },
      };
    }
    throw MobileApiException('Unexpected POST $path', 404);
  }
}

void main() {
  testWidgets('student can start, answer and submit a timed exam', (tester) async {
    final api = FakeExamApi();
    await tester.pumpWidget(
      MaterialApp(home: StudentExamsPage(api: api)),
    );
    await tester.pumpAndSettle();

    expect(find.text('Present Perfect Test'), findsOneWidget);
    await tester.tap(find.text('Present Perfect Test'));
    await tester.pumpAndSettle();

    expect(find.text('Choose the correct form.'), findsOneWidget);
    expect(find.text('Time remaining'), findsOneWidget);
    await tester.tap(find.text('has gone'));
    await tester.pump();
    await tester.tap(find.text('Submit exam'));
    await tester.pumpAndSettle();

    expect(find.text('Exam passed'), findsOneWidget);
    expect(find.text('Score: 5 / 5'), findsOneWidget);
    expect(
      api.calls.map((call) => call['path']).toList(),
      [
        '/my/exams/8/attempts',
        '/my/exams/8/attempts/41/submit',
      ],
    );
  });
}
