import 'package:file_picker/file_picker.dart';
import 'package:material_ui/material_ui.dart';

import 'api_client.dart';

class StudentAssignmentsPage extends StatefulWidget {
  const StudentAssignmentsPage({required this.api, super.key});

  final MobileApi api;

  @override
  State<StudentAssignmentsPage> createState() => _StudentAssignmentsPageState();
}

class _StudentAssignmentsPageState extends State<StudentAssignmentsPage> {
  late Future<Map<String, dynamic>> assignments;

  @override
  void initState() {
    super.initState();
    reload();
  }

  void reload() {
    assignments = widget.api.get('/my/assignments');
  }

  Future<void> openAssignment(Map<String, dynamic> assignment) async {
    final id = (assignment['id'] as num?)?.toInt();
    if (id == null) return;
    await Navigator.push(
      context,
      MaterialPageRoute<void>(
        builder: (_) => StudentAssignmentDetailPage(
          api: widget.api,
          assignmentId: id,
        ),
      ),
    );
    if (mounted) setState(reload);
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        body: FutureBuilder<Map<String, dynamic>>(
          future: assignments,
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
              return const Center(child: Text('No assignments available.'));
            }
            return RefreshIndicator(
              onRefresh: () async => setState(reload),
              child: ListView.separated(
                itemCount: items.length,
                separatorBuilder: (_, __) => const Divider(height: 1),
                itemBuilder: (context, index) {
                  final item = Map<String, dynamic>.from(items[index] as Map);
                  final submission = item['submission'];
                  final status = submission is Map
                      ? submission['status']?.toString() ?? 'Not submitted'
                      : 'Not submitted';
                  return ListTile(
                    leading: const Icon(Icons.assignment_outlined),
                    title: Text(item['title']?.toString() ?? 'Assignment'),
                    subtitle: Text(
                      'Due: ${item['due_at'] ?? 'No deadline'} · $status',
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                    ),
                    trailing: const Icon(Icons.chevron_right),
                    onTap: () => openAssignment(item),
                  );
                },
              ),
            );
          },
        ),
      );
}

class StudentAssignmentDetailPage extends StatefulWidget {
  const StudentAssignmentDetailPage({
    required this.api,
    required this.assignmentId,
    super.key,
  });

  final MobileApi api;
  final int assignmentId;

  @override
  State<StudentAssignmentDetailPage> createState() =>
      _StudentAssignmentDetailPageState();
}

class _StudentAssignmentDetailPageState
    extends State<StudentAssignmentDetailPage> {
  late Future<Map<String, dynamic>> detail;
  final answer = TextEditingController();
  PlatformFile? attachment;
  bool sending = false;

  @override
  void initState() {
    super.initState();
    reload();
  }

  @override
  void dispose() {
    answer.dispose();
    super.dispose();
  }

  void reload() {
    detail = widget.api.get('/my/assignments/${widget.assignmentId}');
  }

  Future<void> pickAttachment() async {
    final result = await FilePicker.platform.pickFiles(
      type: FileType.custom,
      allowedExtensions: ['pdf', 'doc', 'docx', 'txt', 'jpg', 'jpeg', 'png', 'zip'],
      withData: false,
    );
    if (!mounted || result == null || result.files.isEmpty) return;
    setState(() => attachment = result.files.first);
  }

  Future<void> submit() async {
    if (sending) return;
    if (answer.text.trim().isEmpty && attachment == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Write an answer or attach a file.')),
      );
      return;
    }
    if (attachment != null && (attachment!.path == null || attachment!.path!.isEmpty)) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('The selected file cannot be opened on this device.')),
      );
      return;
    }

    setState(() => sending = true);
    try {
      await widget.api.postMultipart(
        '/my/assignments/${widget.assignmentId}/submit',
        {'answer_text': answer.text.trim()},
        attachmentPath: attachment?.path,
        attachmentName: attachment?.name,
      );
      answer.clear();
      if (mounted) {
        setState(() {
          attachment = null;
          reload();
        });
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Assignment submitted successfully.')),
        );
      }
    } catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(error.toString())),
        );
      }
    } finally {
      if (mounted) setState(() => sending = false);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: const Text('Assignment')),
        body: FutureBuilder<Map<String, dynamic>>(
          future: detail,
          builder: (context, snapshot) {
            if (snapshot.connectionState != ConnectionState.done) {
              return const Center(child: CircularProgressIndicator());
            }
            if (snapshot.hasError) {
              return Center(child: Text(snapshot.error.toString()));
            }
            final raw = snapshot.data?['data'];
            if (raw is! Map) {
              return const Center(child: Text('Assignment not found.'));
            }
            final item = Map<String, dynamic>.from(raw);
            final submission = item['submission'] is Map
                ? Map<String, dynamic>.from(item['submission'] as Map)
                : null;
            final graded = submission?['status'] == 'graded';
            return ListView(
              padding: const EdgeInsets.all(16),
              children: [
                Text(
                  item['title']?.toString() ?? 'Assignment',
                  style: Theme.of(context).textTheme.headlineSmall,
                ),
                const SizedBox(height: 8),
                Text('Due: ${item['due_at'] ?? 'No deadline'}'),
                Text('Maximum score: ${item['max_score'] ?? '—'}'),
                const Divider(height: 32),
                Text(
                  item['instructions']?.toString() ?? '',
                  style: Theme.of(context).textTheme.bodyLarge,
                ),
                const Divider(height: 32),
                Text('Your submission', style: Theme.of(context).textTheme.titleLarge),
                if (submission != null) ...[
                  ListTile(
                    contentPadding: EdgeInsets.zero,
                    title: Text('Status: ${submission['status'] ?? 'submitted'}'),
                    subtitle: Text('Submitted: ${submission['submitted_at'] ?? '—'}'),
                  ),
                  if (submission['score'] != null)
                    Text('Score: ${submission['score']} / ${item['max_score']}'),
                  if ((submission['feedback']?.toString() ?? '').isNotEmpty)
                    Text('Teacher feedback: ${submission['feedback']}'),
                  if (submission['has_attachment'] == true)
                    const Text('An attachment is included with your submission.'),
                ],
                if (!graded) ...[
                  const SizedBox(height: 12),
                  TextField(
                    controller: answer,
                    minLines: 4,
                    maxLines: 10,
                    maxLength: 30000,
                    decoration: const InputDecoration(
                      labelText: 'Your answer',
                      alignLabelWithHint: true,
                      border: OutlineInputBorder(),
                    ),
                  ),
                  OutlinedButton.icon(
                    onPressed: sending ? null : pickAttachment,
                    icon: const Icon(Icons.attach_file),
                    label: Text(attachment?.name ?? 'Attach a file (optional)'),
                  ),
                  const SizedBox(height: 12),
                  FilledButton(
                    onPressed: sending ? null : submit,
                    child: sending
                        ? const CircularProgressIndicator()
                        : Text(submission == null ? 'Submit assignment' : 'Update submission'),
                  ),
                ] else
                  const Text('This assignment has been graded and can no longer be changed.'),
              ],
            );
          },
        ),
      );
}
