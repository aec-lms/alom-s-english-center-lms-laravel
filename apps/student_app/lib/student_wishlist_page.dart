import 'package:material_ui/material_ui.dart';

import 'api_client.dart';
import 'course_detail_page.dart';

class StudentWishlistPage extends StatefulWidget {
  const StudentWishlistPage({required this.api, super.key});

  final MobileApi api;

  @override
  State<StudentWishlistPage> createState() => _StudentWishlistPageState();
}

class _StudentWishlistPageState extends State<StudentWishlistPage> {
  late Future<Map<String, dynamic>> wishlist;
  final Set<int> removing = {};

  @override
  void initState() {
    super.initState();
    reload();
  }

  void reload() {
    wishlist = widget.api.get('/my/wishlist');
  }

  Future<void> removeCourse(int id) async {
    setState(() => removing.add(id));
    try {
      await widget.api.deleteJson('/my/wishlist/$id');
      if (mounted) setState(reload);
    } catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(error.toString())),
        );
      }
    } finally {
      if (mounted) setState(() => removing.remove(id));
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        body: FutureBuilder<Map<String, dynamic>>(
          future: wishlist,
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
              return const Center(child: Text('Your wishlist is empty.'));
            }
            return RefreshIndicator(
              onRefresh: () async => setState(reload),
              child: ListView.separated(
                itemCount: items.length,
                separatorBuilder: (_, __) => const Divider(height: 1),
                itemBuilder: (context, index) {
                  final item = Map<String, dynamic>.from(items[index] as Map);
                  final id = (item['id'] as num?)?.toInt();
                  final slug = item['slug']?.toString();
                  return ListTile(
                    leading: const Icon(Icons.favorite_outline),
                    title: Text(item['title']?.toString() ?? 'Saved course'),
                    subtitle: Text('Price: ${item['price'] ?? '—'}'),
                    onTap: slug == null || slug.isEmpty
                        ? null
                        : () => Navigator.push(
                              context,
                              MaterialPageRoute(
                                builder: (_) => CourseDetailPage(
                                  api: widget.api,
                                  slug: slug,
                                ),
                              ),
                            ),
                    trailing: id == null
                        ? null
                        : IconButton(
                            tooltip: 'Remove from wishlist',
                            onPressed:
                                removing.contains(id) ? null : () => removeCourse(id),
                            icon: removing.contains(id)
                                ? const SizedBox(
                                    width: 18,
                                    height: 18,
                                    child: CircularProgressIndicator(strokeWidth: 2),
                                  )
                                : const Icon(Icons.delete_outline),
                          ),
                  );
                },
              ),
            );
          },
        ),
      );
}

class StudentMorePage extends StatelessWidget {
  const StudentMorePage({required this.api, super.key});

  final MobileApi api;

  @override
  Widget build(BuildContext context) => ListView(
        padding: const EdgeInsets.all(16),
        children: [
          ListTile(
            leading: const Icon(Icons.favorite_outline),
            title: const Text('My Wishlist'),
            subtitle: const Text('Courses you saved for later'),
            trailing: const Icon(Icons.chevron_right),
            onTap: () => Navigator.push(
              context,
              MaterialPageRoute(
                builder: (_) => StudentWishlistPage(api: api),
              ),
            ),
          ),
          const Divider(height: 1),
          ListTile(
            leading: const Icon(Icons.support_agent),
            title: const Text('Student Support'),
            subtitle: const Text('Contact the academy and follow replies'),
            trailing: const Icon(Icons.chevron_right),
            onTap: () => Navigator.push(
              context,
              MaterialPageRoute(
                builder: (_) => SupportTicketsPage(api: api),
              ),
            ),
          ),
        ],
      );
}
