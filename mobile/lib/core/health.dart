import 'dart:async';

import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../app/config.dart';

/// Hasil `GET /health/live` yang sehat. `requestId` hanya UUID dari header X-Request-Id
/// (nilai lain dibuang) untuk dicocokkan dengan log API saat QA nonproduction.
class HealthOk {
  const HealthOk({this.requestId});
  final String? requestId;
}

enum HealthFailure { timeout, offline, server, malformed }

class HealthException implements Exception {
  const HealthException(this.kind);
  final HealthFailure kind;

  String get message => switch (kind) {
    HealthFailure.timeout => 'Server tidak merespons. Periksa koneksi lalu coba lagi.',
    HealthFailure.offline => 'Tidak dapat terhubung ke server. Periksa koneksi internet.',
    HealthFailure.server => 'Layanan RT05 sementara tidak tersedia. Coba lagi nanti.',
    HealthFailure.malformed => 'Respons server tidak dikenali. Coba lagi nanti.',
  };

  @override
  String toString() => 'HealthException(${kind.name})';
}

final _uuid = RegExp(r'^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$');

/// Satu request liveness nyata ke API, tanpa token, dengan batas waktu koneksi, terima, dan total.
class HealthClient {
  HealthClient(AppConfig config, {HttpClientAdapter? adapter, this.timeout = const Duration(seconds: 8)})
    : _dio = Dio(
        BaseOptions(
          baseUrl: config.apiBaseUrl,
          connectTimeout: timeout,
          sendTimeout: timeout,
          receiveTimeout: timeout,
          responseType: ResponseType.json,
          headers: {'Accept': 'application/json'},
          validateStatus: (_) => true,
        ),
      ) {
    if (adapter != null) _dio.httpClientAdapter = adapter;
  }

  final Dio _dio;
  final Duration timeout;

  Future<HealthOk> live() async {
    final Response<dynamic> res;
    try {
      // Batas total menutup kasus koneksi menggantung yang tidak terjangkau timeout Dio.
      res = await _dio.get<dynamic>('/health/live').timeout(timeout * 2);
    } on TimeoutException {
      throw const HealthException(HealthFailure.timeout);
    } on DioException catch (e) {
      throw HealthException(switch (e.type) {
        DioExceptionType.connectionTimeout || DioExceptionType.sendTimeout || DioExceptionType.receiveTimeout => HealthFailure.timeout,
        DioExceptionType.badResponse => HealthFailure.server,
        DioExceptionType.unknown when e.error is FormatException => HealthFailure.malformed,
        _ => HealthFailure.offline,
      });
    }
    if (res.statusCode != 200) throw const HealthException(HealthFailure.server);
    final body = res.data;
    if (body is! Map || body['status'] != 'ok') throw const HealthException(HealthFailure.malformed);
    final id = res.headers.value('x-request-id');
    return HealthOk(requestId: id != null && _uuid.hasMatch(id) ? id.toLowerCase() : null);
  }
}

/// Seam injeksi transport HTTP: null di aplikasi (adapter bawaan Dio); tes memberi adapter palsu.
final httpAdapterProvider = Provider<HttpClientAdapter?>((ref) => null);

final healthClientProvider = Provider<HealthClient>((ref) => HealthClient(ref.watch(appConfigProvider), adapter: ref.watch(httpAdapterProvider)));

/// Status health saat bootstrap: null = sedang/memeriksa.
class HealthState {
  const HealthState.checking() : ok = null, error = null;
  const HealthState.ok(HealthOk this.ok) : error = null;
  const HealthState.failed(HealthException this.error) : ok = null;

  final HealthOk? ok;
  final HealthException? error;
  bool get checking => ok == null && error == null;
}

class HealthController extends Notifier<HealthState> {
  int _run = 0;

  @override
  HealthState build() => const HealthState.checking();

  Future<void> check() async {
    final run = ++_run;
    state = const HealthState.checking();
    try {
      final ok = await ref.read(healthClientProvider).live();
      if (run != _run) return;
      if (!ref.read(appConfigProvider).isProduction) debugPrint('RT05 health ok request_id=${ok.requestId ?? '-'}');
      state = HealthState.ok(ok);
    } on HealthException catch (e) {
      if (run != _run) return;
      debugPrint('RT05 health gagal: ${e.kind.name}');
      state = HealthState.failed(e);
    }
  }
}

final healthProvider = NotifierProvider<HealthController, HealthState>(HealthController.new);
