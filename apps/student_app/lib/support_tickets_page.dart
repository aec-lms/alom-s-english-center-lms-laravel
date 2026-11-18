import 'package:material_ui/material_ui.dart';

import 'api_client.dart';

class SupportTicketsPage extends StatefulWidget {
  const SupportTicketsPage({required this.api, super.key});

  final MobileApi api;

  @override
  State<SupportTicketsPage> createState() => _SupportTicketsPageState();
}

class _SupportTicketsPageState extends State<SupportTicketsPage> {
  late Future<Map<String, dynamic>> tickets;

  @override
  void initState() {
    super.initState();
    tickets = widget.api.get('/my/support-tickets');
  }

  Future<void> reload() async {
    final next = widget.api.get('/my/support-tickets');
    setState(() => tickets = next);
    await next;
  }

  Future<void> createTicket() async {
    final subject = TextEditingController();
    final message = TextEditingController();
    final values = await showDialog<Map<String, String>>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: const Text('New support request'),
        content: SizedBox(
          width: 440,
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              TextField(
                controller: subject,
                maxLength: 160,
                decoration: const InputDecoration(
                  labelText: 'Subject',
                  border: OutlineInputBorder(),
                ),
              ),
              const SizedBox(height: 12),
              TextField(
                controller: message,
                minLines: 3,
                maxLines: 5,
                maxLength: 5000,
                decoration: const InputDecoration(
                  labelText: 'Describe your issue',
                  border: OutlineInputBorder(),
                ),
              ),
            ],
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(dialogContext),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: () {
              if (subject.text.trim().length < 5 ||
                  message.text.trim().length < 5) {
                return;
              }
              Navigator.pop(dialogContext, {
                'subject': subject.text.trim(),
                'message': message.text.trim(),
              });
            },
            child: const Text('Submit'),
          ),
        ],
      ),
    );
    subject.dispose();
    message.dispose();
    if (values == null || !mounted) return;

    try {
      await widget.api.postJson('/my/support-tickets', values);
      await reload();
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Your support request was submitted.')),
        );
      }
    } catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(error.toString())),
        );
      }
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        floatingActionButton: FloatingActionButton.extended(
          onPressed: createTicket,
          icon: const Icon(Icons.add),
          label: const Text('New ticket'),
        ),
        body: FutureBuilder<Map<String, dynamic>>(
          future: tickets,
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
                      onPressed: reload,
                      child: const Text('Try again'),
                    ),
                  ],
                ),
              );
            }

            final raw = snapshot.data?['data'];
            final items = raw is List ? raw : const <dynamic>[];
            if (items.isEmpty) {
              return RefreshIndicator(
                onRefresh: reload,
                child: ListView(
                  children: const [
                    SizedBox(height: 140),
                    Icon(Icons.support_agent, size: 56),
                    SizedBox(height: 12),
                    Center(child: Text('No support tickets yet.')),
                    Center(child: Text('Create a ticket if you need help.')),
                  ],
                ),
              );
            }

            return RefreshIndicator(
              onRefresh: reload,
              child: ListView(
                padding: const EdgeInsets.fromLTRB(12, 12, 12, 88),
                children: [
                  for (final rawTicket in items)
                    Builder(builder: (context) {
                      final ticket =
                          Map<String, dynamic>.from(rawTicket as Map);
                      return Card(
                        child: ListTile(
                          leading: const CircleAvatar(
                            child: Icon(Icons.support_agent),
                          ),
                          title: Text(ticket['subject']?.toString() ?? 'Support ticket'),
                          subtitle: Text(
                            '${ticket['status'] ?? 'open'} · '
                            '${ticket['last_message'] ?? 'No messages yet'}',
                            maxLines: 2,
                            overflow: TextOverflow.ellipsis,
                          ),
                          trailing: const Icon(Icons.chevron_right),
                          onTap: () async {
                            await Navigator.push(
                              context,
                              MaterialPageRoute(
                                builder: (_) => SupportTicketDetailPage(
                                  api: widget.api,
                                  ticketId: ticket['id'] as int,
                                ),
                              ),
                            );
                            if (mounted) await reload();
                          },
                        ),
                      );
                    }),
                ],
              ),
            );
          },
        ),
      );
}

class SupportTicketDetailPage extends StatefulWidget {
  const SupportTicketDetailPage({
    required this.api,
    required this.ticketId,
    super.key,
  });

  final MobileApi api;
  final int ticketId;

  @override
  State<SupportTicketDetailPage> createState() =>
      _SupportTicketDetailPageState();
}

class _SupportTicketDetailPageState extends State<SupportTicketDetailPage> {
  final reply = TextEditingController();
  late Future<Map<String, dynamic>> detail;
  bool sending = false;

  @override
  void initState() {
    super.initState();
    detail = widget.api.get('/my/support-tickets/${widget.ticketId}');
  }

  @override
  void dispose() {
    reply.dispose();
    super.dispose();
  }

  Future<void> reload() async {
    final next = widget.api.get('/my/support-tickets/${widget.ticketId}');
    setState(() => detail = next);
    await next;
  }

  Future<void> sendReply() async {
    final body = reply.text.trim();
    if (body.length < 2 || sending) return;
    setState(() => sending = true);
    try {
      await widget.api.postJson(
        '/my/support-tickets/${widget.ticketId}/messages',
        {'message': body},
      );
      reply.clear();
      await reload();
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
        appBar: AppBar(title: const Text('Support conversation')),
        body: FutureBuilder<Map<String, dynamic>>(
          future: detail,
          builder: (context, snapshot) {
            if (snapshot.connectionState != ConnectionState.done) {
              return const Center(child: CircularProgressIndicator());
            }
            if (snapshot.hasError) {
              return Center(child: Text(snapshot.error.toString()));
            }
            final data = Map<String, dynamic>.from(
              (snapshot.data?['data'] as Map?) ?? const {},
            );
            final messages = data['messages'] is List
                ? data['messages'] as List
                : const <dynamic>[];
            final isClosed = data['status'] == 'closed';

            return Column(
              children: [
                ListTile(
                  title: Text(data['subject']?.toString() ?? 'Support ticket'),
                  subtitle: Text('Status: ${data['status'] ?? 'open'}'),
                ),
                const Divider(height: 1),
                Expanded(
                  child: RefreshIndicator(
                    onRefresh: reload,
                    child: ListView(
                      padding: const EdgeInsets.all(16),
                      children: [
                        for (final rawMessage in messages)
                          Builder(builder: (context) {
                            final item =
                                Map<String, dynamic>.from(rawMessage as Map);
                            final mine = item['sender_role'] == 'student';
                            return Align(
                              alignment: mine
                                  ? Alignment.centerRight
                                  : Alignment.centerLeft,
                              child: Container(
                                constraints: const BoxConstraints(maxWidth: 520),
                                margin: const EdgeInsets.only(bottom: 12),
                                padding: const EdgeInsets.all(12),
                                decoration: BoxDecoration(
                                  color: mine
                                      ? Theme.of(context).colorScheme.primaryContainer
                                      : Theme.of(context).colorScheme.surfaceContainerHighest,
                                  borderRadius: BorderRadius.circular(14),
                                ),
                                child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    Text(
                                      item['sender_name']?.toString() ??
                                          (mine ? 'You' : 'Support'),
                                      style: Theme.of(context).textTheme.labelLarge,
                                    ),
                                    const SizedBox(height: 5),
                                    Text(item['body']?.toString() ?? ''),
                                  ],
                                ),
                              ),
                            );
                          }),
                      ],
                    ),
                  ),
                ),
                if (!isClosed)
                  SafeArea(
                    child: Padding(
                      padding: const EdgeInsets.all(12),
                      child: Row(
                        children: [
                          Expanded(
                            child: TextField(
                              controller: reply,
                              minLines: 1,
                              maxLines: 4,
                              decoration: const InputDecoration(
                                hintText: 'Write a reply…',
                                border: OutlineInputBorder(),
                              ),
                            ),
                          ),
                          const SizedBox(width: 8),
                          IconButton.filled(
                            onPressed: sending ? null : sendReply,
                            icon: sending
                                ? const SizedBox(
                                    width: 18,
                                    height: 18,
                                    child: CircularProgressIndicator(
                                      strokeWidth: 2,
                                    ),
                                  )
                                : const Icon(Icons.send),
                          ),
                        ],
                      ),
                    ),
                  )
                else
                  const Padding(
                    padding: EdgeInsets.all(16),
                    child: Text('This ticket is closed.'),
                  ),
              ],
            );
          },
        ),
      );
}
