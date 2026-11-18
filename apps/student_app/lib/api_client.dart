import 'dart:convert';
import 'dart:io';
import 'dart:math';

import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:http/http.dart' as http;

class MobileApiException implements Exception {
  const MobileApiException(this.message, [this.statusCode]);

  final String message;
  final int? statusCode;

  @override
  String toString() => message;
}

class MobileApi {
  MobileApi({
    FlutterSecureStorage? storage,
    http.Client? client,
    String? baseUrl,
  })  : storage = storage ?? const FlutterSecureStorage(),
        client = client ?? http.Client(),
        baseUrl = (baseUrl ??
                const String.fromEnvironment(
                  'API_BASE_URL',
                  defaultValue: 'https://your-domain.example/api/v1',
                ))
            .replaceFirst(RegExp(r'/+$'), '');

  static const _tokenKey = 'aec_student_access_token';
  static const _deviceKey = 'aec_student_device_id';

  final FlutterSecureStorage storage;
  final http.Client client;
  final String baseUrl;

  Future<String?> readToken() => storage.read(key: _tokenKey);

  Future<String> _deviceId() async {
    final existing = await storage.read(key: _deviceKey);
    if (existing != null && RegExp(r'^[a-f0-9]{64}$').hasMatch(existing)) {
      return existing;
    }

    final random = Random.secure();
    final value = List<int>.generate(32, (_) => random.nextInt(256))
        .map((byte) => byte.toRadixString(16).padLeft(2, '0'))
        .join();
    await storage.write(key: _deviceKey, value: value);
    return value;
  }

  Future<bool> restoreSession() async {
    if (await readToken() == null) return false;
    try {
      await get('/auth/me');
      return true;
    } on MobileApiException catch (error) {
      if (error.statusCode == 401) {
        await storage.delete(key: _tokenKey);
      }
      return false;
    } catch (_) {
      return false;
    }
  }

  Future<Map<String, dynamic>> login(String email, String password) async {
    final response = await client.post(
      Uri.parse('$baseUrl/auth/login'),
      headers: _jsonHeaders,
      body: jsonEncode({
        'email': email.trim(),
        'password': password,
        'device_name': 'Alom Student App',
        'device_id': await _deviceId(),
        'platform': Platform.isIOS ? 'ios' : 'android',
        'app_version': '1.0.0',
      }),
    );
    final data = _decode(response);
    final token = data['access_token'] ?? data['token'];
    if (token is! String || token.isEmpty) {
      throw const MobileApiException(
        'The server did not return an access token.',
      );
    }
    await storage.write(key: _tokenKey, value: token);
    return data;
  }

  Future<Map<String, dynamic>> get(String path) async {
    final token = await readToken();
    final headers = <String, String>{'Accept': 'application/json'};
    if (token != null) headers['Authorization'] = 'Bearer $token';

    final response = await client.get(
      Uri.parse('$baseUrl$path'),
      headers: headers,
    );
    if (response.statusCode == 401) {
      await storage.delete(key: _tokenKey);
    }
    return _decode(response);
  }

  Future<Map<String, dynamic>> postJson(
    String path,
    Map<String, dynamic> body,
  ) async {
    final token = await readToken();
    if (token == null) {
      throw const MobileApiException('Please sign in again.', 401);
    }

    final response = await client.post(
      Uri.parse('$baseUrl$path'),
      headers: {
        ..._jsonHeaders,
        'Authorization': 'Bearer $token',
      },
      body: jsonEncode(body),
    );
    if (response.statusCode == 401) {
      await storage.delete(key: _tokenKey);
    }
    return _decode(response);
  }

  Future<Map<String, dynamic>> postMultipart(
    String path,
    Map<String, String> fields, {
    String? attachmentPath,
    String? attachmentName,
  }) async {
    final token = await readToken();
    if (token == null || token.isEmpty) {
      throw const MobileApiException('Please sign in again.', 401);
    }

    final request = http.MultipartRequest(
      'POST',
      Uri.parse('$baseUrl$path'),
    )
      ..headers['Accept'] = 'application/json'
      ..headers['Authorization'] = 'Bearer $token'
      ..fields.addAll(fields);

    if (attachmentPath != null && attachmentPath.isNotEmpty) {
      request.files.add(
        await http.MultipartFile.fromPath(
          'attachment',
          attachmentPath,
          filename: attachmentName,
        ),
      );
    }

    final streamed = await client.send(request);
    final response = await http.Response.fromStream(streamed);
    if (response.statusCode == 401 || response.statusCode == 403) {
      await storage.delete(key: _tokenKey);
    }
    return _decode(response);
  }

  Future<void> logout() async {
    final token = await readToken();
    try {
      if (token != null) {
        await client.post(
          Uri.parse('$baseUrl/auth/logout'),
          headers: {
            'Accept': 'application/json',
            'Authorization': 'Bearer $token',
          },
        );
      }
    } finally {
      await storage.delete(key: _tokenKey);
    }
  }

  Map<String, String> get _jsonHeaders => const {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
      };

  Map<String, dynamic> _decode(http.Response response) {
    Map<String, dynamic> data;
    try {
      final value = jsonDecode(response.body);
      data = value is Map<String, dynamic> ? value : {'data': value};
    } catch (_) {
      data = <String, dynamic>{};
    }

    if (response.statusCode < 200 || response.statusCode >= 300) {
      throw MobileApiException(
        data['message']?.toString() ?? 'Request failed: ${response.statusCode}',
        response.statusCode,
      );
    }
    return data;
  }
}
