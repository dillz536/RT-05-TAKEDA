import 'dart:async';

import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:rt05_takeda/app/config.dart';
import 'package:rt05_takeda/core/health.dart';

import 'support/fakes.dart';

void main() {
  final config = AppConfig.parse(flavor: 'staging', apiBaseUrl: 'https://api.example.test');

  HealthClient client(FakeHandler handler, {Duration timeout = const Duration(seconds: 8)}) =>
      HealthClient(config, adapter: FakeAdapter(handler), timeout: timeout);

  Matcher fails(HealthFailure kind) => throwsA(isA<HealthException>().having((e) => e.kind, 'kind', kind));

  test('GET /health/live tanpa token, status ok, request_id UUID dipertahankan', () async {
    final adapter = FakeAdapter((o) => healthOk());
    final ok = await HealthClient(config, adapter: adapter).live();
    expect(ok.requestId, healthRequestId);
    final req = adapter.requests.single;
    expect(req.uri.toString(), 'https://api.example.test/health/live');
    expect(req.method, 'GET');
    expect(req.headers.containsKey('Authorization'), isFalse);
    expect(req.connectTimeout, const Duration(seconds: 8));
    expect(req.receiveTimeout, const Duration(seconds: 8));
  });

  test('request_id bukan UUID dibuang (tidak meneruskan header sembarang)', () async {
    final ok = await client((o) => jsonBody(200, {'status': 'ok'}, headers: {'x-request-id': '<script>token=abc'})).live();
    expect(ok.requestId, isNull);
  });

  test('body tidak sesuai kontrak → malformed', () async {
    await expectLater(client((o) => jsonBody(200, {'status': 'down'})).live(), fails(HealthFailure.malformed));
    await expectLater(client((o) => jsonBody(200, ['ok'])).live(), fails(HealthFailure.malformed));
    await expectLater(
      client(
        (o) => ResponseBody.fromString(
          '<html>ok</html>',
          200,
          headers: {
            Headers.contentTypeHeader: ['text/html'],
          },
        ),
      ).live(),
      fails(HealthFailure.malformed),
    );
  });

  test('HTTP 503/500/404 → server tidak tersedia', () async {
    for (final status in [503, 500, 404]) {
      await expectLater(client((o) => jsonBody(status, {'status': 'not_ready'})).live(), fails(HealthFailure.server), reason: '$status');
    }
  });

  test('tidak ada koneksi → offline', () async {
    await expectLater(client(offline).live(), fails(HealthFailure.offline));
  });

  test('timeout koneksi/terima Dio → timeout', () async {
    await expectLater(client((o) => throw DioException.connectionTimeout(timeout: o.connectTimeout!, requestOptions: o)).live(), fails(HealthFailure.timeout));
    await expectLater(client((o) => throw DioException.receiveTimeout(timeout: o.receiveTimeout!, requestOptions: o)).live(), fails(HealthFailure.timeout));
  });

  test('server menggantung → dibatasi batas waktu total', () async {
    final sw = Stopwatch()..start();
    await expectLater(client((o) => Completer<ResponseBody>().future, timeout: const Duration(milliseconds: 50)).live(), fails(HealthFailure.timeout));
    expect(sw.elapsed, lessThan(const Duration(seconds: 2)));
  });

  test('HealthController: gagal lalu "Coba lagi" berhasil', () async {
    var up = false;
    final c = ProviderContainer(
      overrides: [appConfigProvider.overrideWithValue(config), httpAdapterProvider.overrideWithValue(FakeAdapter((o) => up ? healthOk() : offline(o)))],
    );
    addTearDown(c.dispose);
    expect(c.read(healthProvider).checking, isTrue);
    await c.read(healthProvider.notifier).check();
    expect(c.read(healthProvider).error?.kind, HealthFailure.offline);
    up = true;
    await c.read(healthProvider.notifier).check();
    expect(c.read(healthProvider).ok?.requestId, healthRequestId);
    expect(c.read(healthProvider).error, isNull);
  });
}
