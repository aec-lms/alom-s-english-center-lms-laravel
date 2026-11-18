import 'package:material_ui/material_ui.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:aloms_student_app/api_client.dart';
import 'package:aloms_student_app/student_assignments_page.dart';
import 'package:aloms_student_app/student_wishlist_page.dart';

class FakeStudentApi extends MobileApi {
  FakeStudentApi() : super(baseUrl: 'https://example.test/api/v1');

  final List<Map<String, dynamic>> wishlistItems = [
    {
      'id': 4,
      'title': 'Saved English Course',
      'slug': 'saved-english',
      'price': 900,
    },
  ];
  Map<String, dynamic>? submission;
  String? submittedPath;
  Map<String, String>? submittedFields;

  @override
  Future<Map<String, dynamic>> get(String path) async {
    if (path == '/my/wishlist') {
      return {'data': wishlistItems};
    }
    if (path == '/my/assignments') {
      return {
        'data': [
          {
            'id': 12,
            'title': 'Grammar Task',
            'due_at': null,
            'submission': submission,
          },
        ],
      };
    }
    if (path == '/my/assignments/12') {
      return {
        'data': {
          'id': 12,
          'title': 'Grammar Task',
          'instructions': 'Write five sentences using the present perfect.',
          'due_at': null,
          'max_score': 10,
          'submission': submission,
        },
      };
    }
    throw MobileApiException('Unexpected path: $path', 404);
  }

  @override
  Future<Map<String, dynamic>> deleteJson(String path) async {
    if (path == '/my/wishlist/4') {
      wishlistItems.removeWhere((item) => item['id'] == 4);
      return {'removed': true};
    }
    throw MobileApiException('Unexpected path: $path', 404);
  }

  @override
  Future<Map<String, dynamic>> postMultipart(
    String path,
    Map<String, String> fields, {
    String? attachmentPath,
    String? attachmentName,
  }) async {
    submittedPath = path;
    submittedFields = fields;
    submission = {
      'id': 31,
      'status': 'submitted',
      'answer_text': fields['answer_text'],
      'has_attachment': attachmentPath != null,
    };
    return {'message': 'Assignment submitted successfully.'};
  }
}

void main() {
  testWidgets('student can remove a saved course from their wishlist',
      (tester) async {
    final api = FakeStudentApi();
    await tester.pumpWidget(
      MaterialApp(home: StudentWishlistPage(api: api)),
    );
    await tester.pumpAndSettle();

    expect(find.text('Saved English Course'), findsOneWidget);
    await tester.tap(find.byTooltip('Remove from wishlist'));
    await tester.pumpAndSettle();

    expect(find.text('Your wishlist is empty.'), findsOneWidget);
    expect(api.wishlistItems, isEmpty);
  });

  testWidgets('student can open an assignment and submit a written answer',
      (tester) async {
    final api = FakeStudentApi();
    await tester.pumpWidget(
      MaterialApp(home: StudentAssignmentsPage(api: api)),
    );
    await tester.pumpAndSettle();

    expect(find.text('Grammar Task'), findsOneWidget);
    await tester.tap(find.text('Grammar Task'));
    await tester.pumpAndSettle();

    expect(
      find.text('Write five sentences using the present perfect.'),
      findsOneWidget,
    );
    await tester.enterText(
      find.byType(TextField),
      'I have studied English for two years.',
    );
    await tester.tap(find.text('Submit assignment'));
    await tester.pumpAndSettle();

    expect(api.submittedPath, '/my/assignments/12/submit');
    expect(
      api.submittedFields?['answer_text'],
      'I have studied English for two years.',
    );
  });
}
