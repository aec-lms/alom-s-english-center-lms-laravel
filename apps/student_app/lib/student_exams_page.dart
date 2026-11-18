import 'dart:async';

import 'package:material_ui/material_ui.dart';

import 'api_client.dart';

class StudentExamsPage extends StatefulWidget {
  const StudentExamsPage({required this.api, super.key});

  final MobileApi api;

  @override
  State<StudentExamsPage> createState() => _StudentExamsPageState();
}

class _StudentExamsPageState extends State<StudentExamsPage> {
  late Future<Map<String, dynamic>> exams;

  @override
  void initState() {
    super.initState();
    reload();
  }

  void reload() {
    exams = widget.api.get('/my/exams');
  }

  Future<void> openExam(Map<String, dynamic> exam) async {
    final id = (exam['id'] as num?)?.toInt();
    if (id == null) return;
    await Navigator.push(
      context,
      MaterialPageRoute<void>(
        builder: (_) => StudentExamAttemptPage(
          api: widget.api,
          examId: id,
          title: exam['title']?.toString() ?? 'Exam',
        ),
      ),
    );
    if (mounted) setState(reload);
  }

  @override
  Widget build(BuildContext context) => FutureBuilder<Map<String, dynamic>>(
        future: exams,
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
                  OutlinedButton(
                    onPressed: () => setState(reload),
                    child: const Text('Retry'),
                  ),
                ],
              ),
            );
          }
          final raw = snapshot.data?['data'];
          final items = raw is List ? raw : const <dynamic>[];
          if (items.isEmpty) {
            return const Center(child: Text('No exams are available.'));
          }
          return RefreshIndicator(
            onRefresh: () async => setState(reload),
            child: ListView.separated(
              itemCount: items.length,
              separatorBuilder: (_, __) => const Divider(height: 1),
              itemBuilder: (context, index) {
                final exam = Map<String, dynamic>.from(items[index] as Map);
                return ListTile(
                  leading: const Icon(Icons.quiz_outlined),
                  title: Text(exam['title']?.toString() ?? 'Exam'),
                  subtitle: Text(
                    '${exam['duration_minutes'] ?? '—'} minutes · '
                    'Pass marks: ${exam['pass_marks'] ?? '—'}',
                  ),
                  trailing: const Icon(Icons.chevron_right),
                  onTap: () => openExam(exam),
                );
              },
            ),
          );
        },
      );
}

class StudentExamAttemptPage extends StatefulWidget {
  const StudentExamAttemptPage({
    required this.api,
    required this.examId,
    required this.title,
    super.key,
  });

  final MobileApi api;
  final int examId;
  final String title;

  @override
  State<StudentExamAttemptPage> createState() => _StudentExamAttemptPageState();
}

class _StudentExamAttemptPageState extends State<StudentExamAttemptPage> {
  late Future<Map<String, dynamic>> attempt;
  final Map<String, int> answers = {};
  Timer? countdown;
  DateTime? deadline;
  Duration remaining = Duration.zero;
  bool submitting = false;
  Map<String, dynamic>? result;
  String? error;

  @override
  void initState() {
    super.initState();
    attempt = startAttempt();
  }

  @override
  void dispose() {
    countdown?.cancel();
    super.dispose();
  }

  Future<Map<String, dynamic>> startAttempt() async {
    try {
      final response = await widget.api.postJson(
        '/my/exams/${widget.examId}/attempts',
        {},
      );
      final attemptData = response['attempt'];
      if (attemptData is! Map) {
        throw const MobileApiException('The exam server returned an invalid attempt.');
      }
      final parsedDeadline = DateTime.tryParse(
        attemptData['deadline']?.toString() ?? '',
      );
      if (parsedDeadline == null) {
        throw const MobileApiException('The exam deadline is missing.');
      }
      deadline = parsedDeadline.toLocal();
      remaining = deadline!.difference(DateTime.now());
      if (remaining.isNegative || remaining == Duration.zero) {
        remaining = Duration.zero;
      } else {
        countdown?.cancel();
        countdown = Timer.periodic(const Duration(seconds: 1), (_) {
          if (!mounted || deadline == null || result != null) return;
          final next = deadline!.difference(DateTime.now());
          setState(() => remaining = next.isNegative ? Duration.zero : next);
          if (next <= Duration.zero) {
            countdown?.cancel();
            submit();
          }
        });
      }
      return {
        ...response,
        'attempt_id': attemptData['id'],
      };
    } catch (exception) {
      error = exception.toString();
      rethrow;
    }
  }

  String get remainingLabel {
    final seconds = remaining.inSeconds < 0 ? 0 : remaining.inSeconds;
    final hours = seconds ~/ 3600;
    final minutes = (seconds % 3600) ~/ 60;
    final rest = seconds % 60;
    if (hours > 0) {
      return '${hours.toString().padLeft(2, '0')}:${minutes.toString().padLeft(2, '0')}:${rest.toString().padLeft(2, '0')}';
    }
    return '${minutes.toString().padLeft(2, '0')}:${rest.toString().padLeft(2, '0')}';
  }

  Future<void> submit() async {
    if (submitting || result != null) return;
    final data = await attempt;
    final attemptId = (data['attempt_id'] as num).toInt();
    setState(() => submitting = true);
    countdown?.cancel();
    try {
      final response = await widget.api.postJson(
        '/my/exams/${widget.examId}/attempts/$attemptId/submit',
        {'answers': Map<String, dynamic>.from(answers)},
      );
      if (mounted) {
        setState(() {
          result = response['data'] is Map
              ? Map<String, dynamic>.from(response['data'] as Map)
              : <String, dynamic>{};
        });
      }
    } catch (exception) {
      if (mounted) {
        setState(() => error = exception.toString());
      }
    } finally {
      if (mounted) setState(() => submitting = false);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(widget.title)),
        body: FutureBuilder<Map<String, dynamic>>(
          future: attempt,
          builder: (context, snapshot) {
            if (snapshot.connectionState != ConnectionState.done) {
              return const Center(child: CircularProgressIndicator());
            }
            if (snapshot.hasError) {
              return Center(
                child: Padding(
                  padding: const EdgeInsets.all(24),
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Text(error ?? snapshot.error.toString()),
                      const SizedBox(height: 12),
                      OutlinedButton(
                        onPressed: () => Navigator.pop(context),
                        child: const Text('Back to exams'),
                      ),
                    ],
                  ),
                ),
              );
            }
            if (result != null) {
              final passed = result!['passed'] == true;
              return Center(
                child: Padding(
                  padding: const EdgeInsets.all(24),
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Icon(
                        passed ? Icons.emoji_events_outlined : Icons.assignment_turned_in_outlined,
                        size: 56,
                      ),
                      const SizedBox(height: 12),
                      Text(
                        passed ? 'Exam passed' : 'Exam submitted',
                        style: Theme.of(context).textTheme.headlineSmall,
                      ),
                      const SizedBox(height: 8),
                      Text(
                        'Score: ${result!['score'] ?? 0} / ${result!['total_marks'] ?? 0}',
                      ),
                      const SizedBox(height: 20),
                      FilledButton(
                        onPressed: () => Navigator.pop(context),
                        child: const Text('Back to exams'),
                      ),
                    ],
                  ),
                ),
              );
            }
            final rawQuestions = snapshot.data?['questions'];
            final questions = rawQuestions is List ? rawQuestions : const <dynamic>[];
            if (questions.isEmpty) {
              return const Center(child: Text('This exam has no questions.'));
            }
            return Column(
              children: [
                Material(
                  color: Theme.of(context).colorScheme.surfaceContainerHighest,
                  child: Padding(
                    padding: const EdgeInsets.all(12),
                    child: Row(
                      children: [
                        const Icon(Icons.timer_outlined),
                        const SizedBox(width: 8),
                        const Text('Time remaining'),
                        const Spacer(),
                        Text(
                          remainingLabel,
                          style: Theme.of(context).textTheme.titleLarge,
                        ),
                      ],
                    ),
                  ),
                ),
                if (error != null)
                  Padding(
                    padding: const EdgeInsets.all(8),
                    child: Text(
                      error!,
                      style: TextStyle(color: Theme.of(context).colorScheme.error),
                    ),
                  ),
                Expanded(
                  child: ListView.builder(
                    padding: const EdgeInsets.all(16),
                    itemCount: questions.length,
                    itemBuilder: (context, index) {
                      final question = Map<String, dynamic>.from(questions[index] as Map);
                      final id = question['id'].toString();
                      final options = question['options'] is List
                          ? question['options'] as List
                          : const <dynamic>[];
                      return Card(
                        margin: const EdgeInsets.only(bottom: 16),
                        child: Padding(
                          padding: const EdgeInsets.all(12),
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(
                                'Question ${index + 1}',
                                style: Theme.of(context).textTheme.labelLarge,
                              ),
                              const SizedBox(height: 6),
                              Text(
                                question['prompt']?.toString() ?? '',
                                style: Theme.of(context).textTheme.titleMedium,
                              ),
                              Text('Marks: ${question['marks'] ?? 0}'),
                              const SizedBox(height: 8),
                              RadioGroup<int>(
                                groupValue: answers[id],
                                onChanged: (value) {
                                  if (submitting || result != null || value == null) return;
                                  setState(() => answers[id] = value);
                                },
                                child: Column(
                                  children: [
                                    for (var optionIndex = 0; optionIndex < options.length; optionIndex++)
                                      RadioListTile<int>(
                                        contentPadding: EdgeInsets.zero,
                                        dense: true,
                                        enabled: !submitting && result == null,
                                        value: optionIndex,
                                        title: Text(options[optionIndex].toString()),
                                      ),
                                  ],
                                ),
                              ),
                            ],
                          ),
                        ),
                      );
                    },
                  ),
                ),
                SafeArea(
                  child: Padding(
                    padding: const EdgeInsets.all(12),
                    child: SizedBox(
                      width: double.infinity,
                      child: FilledButton(
                        onPressed: submitting ? null : submit,
                        child: submitting
                            ? const CircularProgressIndicator()
                            : const Text('Submit exam'),
                      ),
                    ),
                  ),
                ),
              ],
            );
          },
        ),
      );
}
