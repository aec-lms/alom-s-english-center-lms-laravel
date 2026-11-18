import 'package:material_ui/material_ui.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:aloms_student_app/api_client.dart';
import 'package:aloms_student_app/course_detail_page.dart';

class FakeCourseApi extends MobileApi {
  FakeCourseApi({this.enrolled = true})
      : super(baseUrl: 'https://example.test/api/v1');

  final bool enrolled;

  @override
  Future<Map<String, dynamic>> get(String path) async {
    if (path == '/courses/english-a1') {
      return {
        'data': {
          'id': 7,
          'title': 'English A1',
          'slug': 'english-a1',
          'description': 'Beginner English course.',
          'price': 1500,
          'instructor_name': 'Course Teacher',
        },
      };
    }
    if (path == '/courses/english-a1/lessons') {
      if (!enrolled) {
        throw const MobileApiException('Enrollment required.', 403);
      }
      return {
        'data': [
          {'id': 1, 'title': 'Alphabet and sounds'},
          {'id': 2, 'title': 'Greetings'},
        ],
      };
    }
    throw MobileApiException('Unexpected path: $path', 404);
  }
}

void main() {
  testWidgets('shows course details and lessons for an enrolled student',
      (tester) async {
    await tester.pumpWidget(
      MaterialApp(
        home: CourseDetailPage(api: FakeCourseApi(), slug: 'english-a1'),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('English A1'), findsOneWidget);
    expect(find.text('Beginner English course.'), findsOneWidget);
    expect(find.text('Alphabet and sounds'), findsOneWidget);
    expect(find.text('Greetings'), findsOneWidget);
  });

  testWidgets('does not expose lessons when the student is not enrolled',
      (tester) async {
    await tester.pumpWidget(
      MaterialApp(
        home: CourseDetailPage(
          api: FakeCourseApi(enrolled: false),
          slug: 'english-a1',
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('Enrollment required'), findsOneWidget);
    expect(
      find.text('Enroll in this course to access its lessons.'),
      findsOneWidget,
    );
    expect(find.text('Alphabet and sounds'), findsNothing);
  });
}
