import 'package:material_ui/material_ui.dart';
import 'api_client.dart';
import 'qr_attendance_page.dart';

void main() => runApp(const AlomStudentApp());

class AlomStudentApp extends StatelessWidget {
  const AlomStudentApp({super.key});
  @override
  Widget build(BuildContext context) => MaterialApp(
    title: "Alom's English Center",
    theme: ThemeData(colorScheme: ColorScheme.fromSeed(seedColor: const Color(0xff1769aa)), useMaterial3: true),
    home: const StudentGate(),
  );
}

class StudentGate extends StatefulWidget {
  const StudentGate({super.key});
  @override
  State<StudentGate> createState() => _StudentGateState();
}
class _StudentGateState extends State<StudentGate> {
  final api = MobileApi();
  bool loading = true, signedIn = false;
  @override
  void initState() {
    super.initState();
    api.restoreSession().then((v) { if (mounted) { setState(() { signedIn = v; loading = false; }); } });
  }
  @override
  Widget build(BuildContext context) {
    if (loading) { return const Scaffold(body: Center(child: CircularProgressIndicator())); }
    return signedIn ? StudentHome(api: api, onLogout: () => setState(() => signedIn = false))
      : StudentLogin(api: api, onLogin: () => setState(() => signedIn = true));
  }
}

class StudentLogin extends StatefulWidget {
  const StudentLogin({required this.api, required this.onLogin, super.key});
  final MobileApi api;
  final VoidCallback onLogin;
  @override
  State<StudentLogin> createState() => _StudentLoginState();
}
class _StudentLoginState extends State<StudentLogin> {
  final email = TextEditingController(), password = TextEditingController();
  bool busy = false;
  String? error;
  @override
  void dispose() { email.dispose(); password.dispose(); super.dispose(); }
  Future<void> submit() async {
    setState(() { busy = true; error = null; });
    try { await widget.api.login(email.text, password.text); widget.onLogin(); }
    catch (e) { if (mounted) { setState(() => error = e.toString()); } }
    finally { if (mounted) { setState(() => busy = false); } }
  }
  @override
  Widget build(BuildContext context) => Scaffold(body: Center(child: ConstrainedBox(
    constraints: const BoxConstraints(maxWidth: 440),
    child: Padding(padding: const EdgeInsets.all(24), child: Column(mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        const Icon(Icons.school, size: 64),
        Text("Alom's English Center", textAlign: TextAlign.center, style: Theme.of(context).textTheme.headlineSmall),
        const SizedBox(height: 24),
        TextField(controller: email, keyboardType: TextInputType.emailAddress,
          decoration: const InputDecoration(labelText: 'Email', border: OutlineInputBorder())),
        const SizedBox(height: 12),
        TextField(controller: password, obscureText: true,
          decoration: const InputDecoration(labelText: 'Password', border: OutlineInputBorder())),
        if (error != null) Padding(padding: const EdgeInsets.all(12), child: Text(error!, style: TextStyle(color: Theme.of(context).colorScheme.error))),
        FilledButton(onPressed: busy ? null : submit, child: busy ? const CircularProgressIndicator() : const Text('Sign in')),
      ]),
    ),
  )));
}

class StudentHome extends StatefulWidget {
  const StudentHome({required this.api, required this.onLogout, super.key});
  final MobileApi api;
  final VoidCallback onLogout;
  @override
  State<StudentHome> createState() => _StudentHomeState();
}
class _StudentHomeState extends State<StudentHome> {
  int tab = 0;
  late Future<Map<String, dynamic>> home, profile, notices;
  @override
  void initState() { super.initState(); refresh(); }
  void refresh() {
    home = widget.api.get('/home');
    profile = widget.api.get('/auth/me');
    notices = widget.api.get('/notices');
  }
  Future<void> logout() async { await widget.api.logout(); if (mounted) widget.onLogout(); }
  @override
  Widget build(BuildContext context) {
    final pages = [
      _DataList(title: 'Courses', future: home, keyName: 'courses', labelKey: 'title', onRefresh: () => setState(refresh)),
      _DataList(title: 'My Learning', future: profile, keyName: 'enrollments', labelKey: 'course', onRefresh: () => setState(refresh)),
      _DataList(title: 'Notices', future: notices, keyName: 'data', labelKey: 'title', onRefresh: () => setState(refresh)),
    ];
    return Scaffold(
      appBar: AppBar(title: const Text("Alom's English Center"), actions: [
        IconButton(tooltip: 'QR attendance', onPressed: () => Navigator.push(context,
          MaterialPageRoute(builder: (_) => QrAttendancePage(api: widget.api))), icon: const Icon(Icons.qr_code_scanner)),
        IconButton(tooltip: 'Sign out', onPressed: logout, icon: const Icon(Icons.logout)),
      ]),
      body: pages[tab],
      bottomNavigationBar: NavigationBar(selectedIndex: tab, onDestinationSelected: (v) => setState(() => tab = v),
        destinations: const [
          NavigationDestination(icon: Icon(Icons.menu_book), label: 'Courses'),
          NavigationDestination(icon: Icon(Icons.school), label: 'My Learning'),
          NavigationDestination(icon: Icon(Icons.notifications), label: 'Notices'),
        ]),
    );
  }
}

class _DataList extends StatelessWidget {
  const _DataList({required this.title, required this.future, required this.keyName, required this.labelKey, required this.onRefresh});
  final String title, keyName, labelKey;
  final Future<Map<String, dynamic>> future;
  final VoidCallback onRefresh;
  @override
  Widget build(BuildContext context) => FutureBuilder<Map<String, dynamic>>(future: future, builder: (context, snapshot) {
    if (snapshot.connectionState != ConnectionState.done) { return const Center(child: CircularProgressIndicator()); }
    if (snapshot.hasError) { return Center(child: Text(snapshot.error.toString())); }
    final value = snapshot.data?[keyName];
    final List<dynamic> items = value is List ? value : value is Map && value['data'] is List ? value['data'] as List : [];
    if (items.isEmpty) { return Center(child: Text('No $title available.')); }
    return RefreshIndicator(onRefresh: () async { onRefresh(); },
      child: ListView.builder(itemCount: items.length, itemBuilder: (_, i) {
        final item = Map<String, dynamic>.from(items[i] as Map);
        final label = item[labelKey];
        final display = label is Map ? label['title']?.toString() : label?.toString();
        return ListTile(title: Text(display ?? title), subtitle: Text(item['status']?.toString() ?? ''));
      }));
  });
}
