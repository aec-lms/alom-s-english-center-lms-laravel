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
    reload();
  }

  void reload() {
    tickets = widget.api.get('/my/support-tickets');
  }

  Future<void> createTicket() async {
    final values = await showDialog<Map<String, String>>(
      context: context,
      builder: (_) => const _NewSupportTicketDialog(),
    );
    if (values == null || !mounted) return;

    try {
      await widget.api.postJson('/my/support-tickets', values);
      if (!mounted) return;
      setState(reload);
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Support request submitted.')),
      );
    } catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(error.toString())),
        );
      }
    }
  }

  Future<void> openTicket(Map<String, dynamic> ticket) async {
    final id = (ticket['id'] as num?)?.toInt();
    if (id == null) return;
    await Navigator.push(
      context,
      MaterialPageRoute<void>(
        builder: (_) => SupportTicketDetailPage(api: widget.api, ticketId: id),
      ),
    );
    if (mounted) setState(reload);
  }

  @override
  Widget build(BuildContext context) => Scaffold(
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
                    Text(snapshot.error.toString(), textAlign: TextAlign.center),
                    const SizedBox(height: 12),
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
              return const Center(child: Text('You have no support requests yet.'));
            }
            return RefreshIndicator(
              onRefresh: () async => setState(reload),
              child: ListView.separated(
                itemCount: items.length,
                separatorBuilder: (_, __) => const Divider(height: 1),
                itemBuilder: (context, index) {
                  final item = Map<String, dynamic>.from(items[index] as Map);
                  return ListTile(
                    leading: const Icon(Icons.support_agent),
                    title: Text(item['subject']?.toString() ?? 'Support request'),
                    subtitle: Text(
                      '${item['status'] ?? 'open'} · ${item['last_message'] ?? ''}',
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                    ),
                    trailing: const Icon(Icons.chevron_right),
                    onTap: () => openTicket(item),
                  );
                },
              ),
            );
          },
        ),
        floatingActionButton: FloatingActionButton.extended(
          onPressed: createTicket,
          icon: const Icon(Icons.add),
          label: const Text('New request'),
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
  late Future<Map<String, dynamic>> detail;
  final reply = TextEditingController();
  bool sending = false;

  @override
  void initState() {
    super.initState();
    load();
  }

  @override
  void dispose() {
    reply.dispose();
    super.dispose();
  }

  void load() {
    detail = widget.api.get('/my/support-tickets/${widget.ticketId}');
  }

  Future<void> sendReply() async {
    final message = reply.text.trim();
    if (message.length < 2 || sending) return;
    setState(() => sending = true);
    try {
      await widget.api.postJson(
        '/my/support-tickets/${widget.ticketId}/messages',
        {'message': message},
      );
      reply.clear();
      if (mounted) setState(load);
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
            final ticket = snapshot.data?['data'];
            if (ticket is! Map) {
              return const Center(child: Text('Support request not found.'));
            }
            final messages = ticket['messages'] is List
                ? ticket['messages'] as List
                : const <dynamic>[];
            final closed = ticket['status'] == 'closed';
            return Column(
              children: [
                ListTile(
                  title: Text(ticket['subject']?.toString() ?? 'Support request'),
                  subtitle: Text('Status: ${ticket['status'] ?? 'open'}'),
                ),
                const Divider(height: 1),
                Expanded(
                  child: ListView.builder(
                    padding: const EdgeInsets.all(16),
                    itemCount: messages.length,
                    itemBuilder: (context, index) {
                      final item = Map<String, dynamic>.from(messages[index] as Map);
                      return Card(
                        child: ListTile(
                          title: Text(item['sender_name']?.toString() ?? 'Support'),
                          subtitle: Padding(
                            padding: const EdgeInsets.only(top: 8),
                            child: Text(item['body']?.toString() ?? ''),
                          ),
                        ),
                      );
                    },
                  ),
                ),
                if (closed)
                  const Padding(
                    padding: EdgeInsets.all(16),
                    child: Text('This request is closed. Create a new request if you need more help.'),
                  )
                else
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
                                hintText: 'Write a reply',
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
                                    child: CircularProgressIndicator(strokeWidth: 2),
                                  )
                                : const Icon(Icons.send),
                          ),
                        ],
                      ),
                    ),
                  ),
              ],
            );
          },
        ),
      );
}


class _NewSupportTicketDialog extends StatefulWidget {
  const _NewSupportTicketDialog();

  @override
  State<_NewSupportTicketDialog> createState() => _NewSupportTicketDialogState();
}

class _NewSupportTicketDialogState extends State<_NewSupportTicketDialog> {
  final subject = TextEditingController();
  final message = TextEditingController();

  @override
  void dispose() {
    subject.dispose();
    message.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => AlertDialog(
        title: const Text('New support request'),
        content: SingleChildScrollView(
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
                minLines: 4,
                maxLines: 7,
                maxLength: 5000,
                decoration: const InputDecoration(
                  labelText: 'How can we help?',
                  border: OutlineInputBorder(),
                ),
              ),
            ],
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: subject.text.trim().length < 5 ||
                    message.text.trim().length < 5
                ? null
                : () => Navigator.pop(context, {
                      'subject': subject.text.trim(),
                      'message': message.text.trim(),
                    }),
            child: const Text('Submit'),
          ),
        ],
      );
}
