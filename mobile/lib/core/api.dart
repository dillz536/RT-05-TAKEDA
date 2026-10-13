import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../app/config.dart';
import 'health.dart';
import 'session.dart';

/// Error API v1 terstruktur: {"error": {code, message, fields?, request_id}}.
class ApiException implements Exception {
  ApiException(this.status, this.code, this.message, {this.fields = const {}, this.requestId});

  final int status;
  final String code;
  final String message;
  final Map<String, List<String>> fields;
  final String? requestId;

  bool get isConflict => status == 409;
  bool get isUnknownOutcome => code == 'NETWORK_TIMEOUT';

  /// Pesan pertama yang layak ditampilkan (validasi per field atau pesan umum).
  String get display => fields.values.expand((e) => e).firstOrNull ?? message;

  @override
  String toString() => display;

  static ApiException from(DioException e) {
    final data = e.response?.data;
    if (data is Map && data['error'] is Map) {
      final err = data['error'] as Map;
      final fields = <String, List<String>>{};
      (err['fields'] as Map?)?.forEach((k, v) => fields['$k'] = (v as List).map((x) => '$x').toList());
      return ApiException(e.response?.statusCode ?? 0, '${err['code']}', '${err['message']}', fields: fields, requestId: err['request_id'] as String?);
    }
    return switch (e.type) {
      DioExceptionType.connectionTimeout ||
      DioExceptionType.receiveTimeout ||
      DioExceptionType.sendTimeout => ApiException(0, 'NETWORK_TIMEOUT', 'Koneksi lambat. Hasil belum diketahui; periksa data sebelum mencoba lagi.'),
      DioExceptionType.connectionError => ApiException(0, 'NETWORK_ERROR', 'Tidak dapat terhubung ke server. Periksa koneksi internet.'),
      // 5xx tanpa envelope (mis. proxy/gateway saat deploy): layanan belum tersedia, bukan sesi tidak sah.
      _ when (e.response?.statusCode ?? 0) >= 500 => ApiException(
        e.response!.statusCode!,
        'SERVICE_UNAVAILABLE',
        'Layanan sementara tidak tersedia. Coba lagi nanti.',
      ),
      _ => ApiException(e.response?.statusCode ?? 0, 'UNKNOWN', 'Terjadi kesalahan. Coba lagi.'),
    };
  }
}

/// Klien HTTP API RT05 TAKEDA. Token bearer dari secure storage; 401 → sesi dihapus.
/// Tidak ada auto-retry untuk POST (keuangan memakai Idempotency-Key yang sama saat retry manual).
class Api {
  Api(this._ref) {
    _base = _ref.read(appConfigProvider).apiBaseUrl;
    _dio = Dio(
      BaseOptions(
        baseUrl: '$_base/api/v1',
        connectTimeout: const Duration(seconds: 15),
        receiveTimeout: const Duration(seconds: 30),
        headers: {'Accept': 'application/json'},
      ),
    );
    final adapter = _ref.read(httpAdapterProvider);
    if (adapter != null) _dio.httpClientAdapter = adapter;
    _dio.interceptors.add(
      InterceptorsWrapper(
        onRequest: (options, handler) {
          final token = _ref.read(sessionProvider).token;
          // Nilai yang dipasang pemanggil saat request dibuat tidak ditimpa sesi yang lebih baru.
          if (token != null) options.headers.putIfAbsent('Authorization', () => 'Bearer $token');
          options.extra.putIfAbsent('sessionEpoch', () => _ref.read(sessionProvider.notifier).epoch);
          handler.next(options);
        },
        onError: (e, handler) {
          final auth = e.requestOptions.headers['Authorization'];
          final epoch = e.requestOptions.extra['sessionEpoch'];
          if (e.response?.statusCode == 401 && auth is String && auth.startsWith('Bearer ')) {
            _ref.read(sessionProvider.notifier).expire(token: auth.substring(7), epoch: epoch is int ? epoch : null);
          }
          handler.next(e);
        },
      ),
    );
  }

  final Ref _ref;
  late final String _base;
  late final Dio _dio;

  String absolute(String path) => path.startsWith('http') ? path : '$_base$path';
  Map<String, String> get authHeaders => {if (_ref.read(sessionProvider).token != null) 'Authorization': 'Bearer ${_ref.read(sessionProvider).token}'};

  Future<T> _wrap<T>(Future<Response<dynamic>> Function() call, T Function(dynamic body) parse) async {
    try {
      final res = await call();
      return parse(res.data);
    } on DioException catch (e) {
      throw ApiException.from(e);
    }
  }

  /// Mengembalikan `data` dari envelope.
  Future<dynamic> get(String path, {Map<String, dynamic>? query}) => _wrap(() => _dio.get(path, queryParameters: query), (b) => (b as Map)['data']);

  /// Mengembalikan envelope lengkap (data + meta) untuk daftar berhalaman.
  Future<Map<String, dynamic>> page(String path, {Map<String, dynamic>? query}) =>
      _wrap(() => _dio.get(path, queryParameters: query), (b) => Map<String, dynamic>.from(b as Map));

  /// [bearer] dan [sessionEpoch] dipasang sekarang, sebelum interceptor Dio berjalan.
  Future<dynamic> post(String path, {Object? data, String? idempotencyKey, String? bearer, int? sessionEpoch}) => _wrap(
    () => _dio.post(
      path,
      data: data,
      options: Options(
        headers: {'Idempotency-Key': ?idempotencyKey, if (bearer != null) 'Authorization': 'Bearer $bearer'},
        extra: {'sessionEpoch': ?sessionEpoch},
      ),
    ),
    (b) => b is Map ? b['data'] : null,
  );

  Future<dynamic> patch(String path, {Object? data}) => _wrap(() => _dio.patch(path, data: data), (b) => b is Map ? b['data'] : null);

  Future<dynamic> put(String path, {Object? data}) => _wrap(() => _dio.put(path, data: data), (b) => b is Map ? b['data'] : null);

  Future<void> delete(String path, {Object? data}) => _wrap(() => _dio.delete(path, data: data), (_) {});
}

final apiProvider = Provider<Api>((ref) => Api(ref));
