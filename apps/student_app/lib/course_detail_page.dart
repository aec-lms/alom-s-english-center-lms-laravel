import 'package:material_ui/material_ui.dart';

import 'api_client.dart';

class CourseDetailPage extends StatefulWidget {
  const CourseDetailPage({
    required this.api,
    required this.slug,
    super.key,
  });

  final MobileApi api;
  final String slug;

  @override
  State<CourseDetailPage> createState() => _CourseDetailPageState();
}

class _CourseDetailPageState extends State<CourseDetailPage> {
  late Future<Map<String, dynamic>> course;
  late Future<Map<String, dynamic>> lessons;

  @override
  void initState() {
    super.initState();
    reload();
  }

  void reload() {
    course = widget.api.get('/courses/${Uri.encodeComponent(widget.slug)}');
    lessons = widget.api.get(
      '/courses/${Uri.encodeComponent(widget.slug)}/lessons',
    );
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: const Text('Course details')),
        body: FutureBuilder<Map<String, dynamic>>(
          future: course,
          builder: (context, snapshot) {
            if (snapshot.connectionState != ConnectionState.done) {
              return const Center(child: CircularProgressIndicator());
            }
            if (snapshot.hasError) {
              return Center(
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Text(snapshot.error.toString()),
                    const SizedBox(height: 12),
                    OutlinedButton(
                      onPressed: () => setState(reload),
                      child: const Text('Retry'),
                    ),
                  ],
                ),
              );
            }
            final item = snapshot.data?['data'];
            if (item is! Map) {
              return const Center(child: Text('Course not found.'));
            }
            return RefreshIndicator(
              onRefresh: () async => setState(reload),
              child: ListView(
                padding: const EdgeInsets.all(16),
                children: [
                  Text(
                    item['title']?.toString() ?? 'Course',
                    style: Theme.of(context).textTheme.headlineSmall,
                  ),
                  if ((item['instructor_name']?.toString() ?? '').isNotEmpty)
                    Padding(
                      padding: const EdgeInsets.only(top: 8),
                      child: Text('Instructor: ${item['instructor_name']}'),
                    ),
                  if ((item['price']?.toString() ?? '').isNotEmpty)
                    Padding(
                      padding: const EdgeInsets.only(top: 8),
                      child: Text('Price: ${item['price']}'),
                    ),
                  const SizedBox(height: 16),
                  Text(
                    item['description']?.toString() ?? 'No course description available.',
                  ),
                  const Divider(height: 32),
                  Text(
                    'Lessons',
                    style: Theme.of(context).textTheme.titleLarge,
                  ),
                  const SizedBox(height: 8),
                  FutureBuilder<Map<String, dynamic>>(
                    future: lessons,
                    builder: (context, lessonSnapshot) {
                      if (lessonSnapshot.connectionState !=
                          ConnectionState.done) {
                        return const Padding(
                          padding: EdgeInsets.all(20),
                          child: Center(child: CircularProgressIndicator()),
                        );
                      }
                      if (lessonSnapshot.hasError) {
                        final error = lessonSnapshot.error;
                        if (error is MobileApiException &&
                            error.statusCode == 403) {
                          return const ListTile(
                            leading: Icon(Icons.lock_outline),
                            title: Text('Enrollment required'),
                            subtitle: Text(
                              'Enroll in this course to access its lessons.',
                            ),
                          );
                        }
                        return ListTile(
                          leading: const Icon(Icons.info_outline),
                          title: Text(error.toString()),
                        );
                      }
                      final raw = lessonSnapshot.data?['data'];
                      final items = raw is List ? raw : const <dynamic>[];
                      if (items.isEmpty) {
                        return const ListTile(
                          leading: Icon(Icons.menu_book_outlined),
                          title: Text('No lessons are available yet.'),
                        );
                      }
                      return Column(
                        children: [
                          for (var index = 0; index < items.length; index++)
                            ListTile(
                              leading: CircleAvatar(
                                child: Text('${index + 1}'),
                              ),
                              title: Text(
                                (items[index] as Map)['title']?.toString() ??
                                    'Lesson',
                              ),
                              subtitle: const Text('Course lesson'),
                            ),
                        ],
                      );
                    },
                  ),
                ],
              ),
            );
          },
        ),
      );
}
