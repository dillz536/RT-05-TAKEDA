import 'dart:async';

import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:rt05_takeda/app/config.dart';
import 'package:rt05_takeda/core/api.dart';
import 'package:rt05_takeda/core/health.dart';
import 'package:rt05_takeda/core/models.dart';
import 'package:rt05_takeda/core/session.dart';

import 'support/fakes.dart';

void main() {
  late MemoryTokenStorage storage;

  ProviderContainer container(FetchMe fetchMe, {HttpClientAdapter? adapter}) {
    final c = ProviderContainer(
      overrides: [
        appConfigProvider.overrideWithValue(AppConfig.parse(flavor: 'staging', apiBaseUrl: 'https://api.example.test')),
        tokenStorageProvider.overrideWithValue(storage),
        fetchMeProvider.overrideWithValue(fetchMe),
        httpAdapterProvider.overrideWithValue(adapter),
      ],
    );
    addTearDown(c.dispose);
    return c;
  }

  setUp(() => storage = MemoryTokenStorage('token-lama'));

  test('restore sukses: akun dari server, token tetap', () async {
    final c = container((t) async => Akun(akunJson()));
    await c.read(sessionProvider.notifier).restore();
    final s = c.read(sessionProvider);
    expect(s.signedIn, isTrue);
    expect(s.loading, isFalse);
    expect(storage.token, 'token-lama');
  });

  test('restore 401 (otoritatif): token dihapus dan sesi berakhir', () async {
    final c = container((t) async => throw ApiException(401, 'UNAUTHENTICATED', 'Sesi berakhir.'));
    await c.read(sessionProvider.notifier).restore();
    final s = c.read(sessionProvider);
    expect(storage.token, isNull);
    expect(s.signedIn, isFalse);
    expect(s.expired, isTrue);
    expect(s.unavailable, isNull);
  });

  for (final (label, error) in [
    ('offline', ApiException(0, 'NETWORK_ERROR', 'Tidak dapat terhubung ke server.')),
    ('timeout', ApiException(0, 'NETWORK_TIMEOUT', 'Koneksi lambat.')),
    ('503', ApiException(503, 'SERVICE_UNAVAILABLE', 'Layanan sementara tidak tersedia.')),
    ('403 akun nonaktif', ApiException(403, 'FORBIDDEN', 'Akses ditolak.')),
  ]) {
    test('restore $label: token dipertahankan, status tidak tersedia tanpa data akun', () async {
      final c = container((t) async => throw error);
      await c.read(sessionProvider.notifier).restore();
      final s = c.read(sessionProvider);
      expect(storage.token, 'token-lama');
      expect(storage.log, isNot(contains('delete')));
      expect(s.signedIn, isFalse, reason: 'data akun lama tidak boleh dianggap sah');
      expect(s.akun, isNull);
      expect(s.unavailable, same(error));
      expect(s.token, 'token-lama');
    });
  }

  test('restore tanpa token: tamu, tanpa request /auth/me', () async {
    storage.token = null;
    var calls = 0;
    final c = container((t) async {
      calls++;
      return Akun(akunJson());
    });
    await c.read(sessionProvider.notifier).restore();
    expect(c.read(sessionProvider).signedIn, isFalse);
    expect(c.read(sessionProvider).loading, isFalse);
    expect(calls, 0);
  });

  test('"Coba lagi" setelah offline memulihkan sesi dengan token yang sama', () async {
    var online = false;
    final c = container((t) async {
      if (!online) throw ApiException(0, 'NETWORK_ERROR', 'offline');
      expect(t, 'token-lama');
      return Akun(akunJson());
    });
    await c.read(sessionProvider.notifier).restore();
    expect(c.read(sessionProvider).unavailable, isNotNull);
    online = true;
    await c.read(sessionProvider.notifier).restore();
    expect(c.read(sessionProvider).signedIn, isTrue);
  });

  test('hasil restore lama tidak menimpa logout yang terjadi lebih dulu', () async {
    final pending = Completer<Akun>();
    final c = container((t) => pending.future);
    final restoring = c.read(sessionProvider.notifier).restore();
    await pumpEventQueue();
    await c.read(sessionProvider.notifier).signOut();
    pending.complete(Akun(akunJson()));
    await restoring;
    expect(c.read(sessionProvider).signedIn, isFalse);
    expect(storage.token, isNull);
  });

  test('401 lama dari restore tidak menghapus token login baru', () async {
    final pending = Completer<Akun>();
    final c = container((t) => pending.future);
    final restoring = c.read(sessionProvider.notifier).restore();
    await pumpEventQueue();
    await c.read(sessionProvider.notifier).signIn('token-baru', Akun(akunJson(id: 'a-2')));
    pending.completeError(ApiException(401, 'UNAUTHENTICATED', 'Sesi berakhir.'));
    await restoring;
    final s = c.read(sessionProvider);
    expect(s.signedIn, isTrue);
    expect(s.akun!.id, 'a-2');
    expect(storage.token, 'token-baru');
  });

  test('logout lalu login cepat: hapus tidak menyusul tulis token baru', () async {
    final c = container((t) async => Akun(akunJson()));
    final n = c.read(sessionProvider.notifier);
    final out = n.signOut();
    final inn = n.signIn('token-baru', Akun(akunJson()));
    await Future.wait([out, inn]);
    expect(storage.log.sublist(storage.log.length - 2), ['delete', 'write']);
    expect(storage.token, 'token-baru');
    expect(c.read(sessionProvider).signedIn, isTrue);
  });

  test('logout server yang selesai setelah signIn baru tidak menghapus token, akun, atau cache', () async {
    final c = container((t) async => Akun(akunJson()));
    final n = c.read(sessionProvider.notifier);
    await n.signIn('token-lama', Akun(akunJson(id: 'a-1')));
    final epoch = n.epoch;
    await n.signIn('token-baru', Akun(akunJson(id: 'a-2')));
    await n.signOutIfCurrent(token: 'token-lama', epoch: epoch);
    final s = c.read(sessionProvider);
    expect(s.signedIn, isTrue);
    expect(s.token, 'token-baru');
    expect(s.akun!.id, 'a-2');
    expect(s.akun!.nama, 'Warga Uji Coba Dengan Nama Cukup Panjang');
    expect(storage.token, 'token-baru');
    expect(storage.log.where((entry) => entry == 'delete'), isEmpty);
  });

  test('logout server tertunda dengan token string yang sama tidak menghapus epoch baru', () async {
    final c = container((t) async => Akun(akunJson()));
    final n = c.read(sessionProvider.notifier);
    await n.signIn('token-sama', Akun(akunJson(id: 'a-1')));
    final epoch = n.epoch;
    final pending = n.signOutIfCurrent(token: 'token-sama', epoch: epoch);
    await n.signIn('token-sama', Akun(akunJson(id: 'a-2')));
    await pending;
    final s = c.read(sessionProvider);
    expect(s.signedIn, isTrue);
    expect(s.token, 'token-sama');
    expect(s.akun!.id, 'a-2');
    expect(storage.token, 'token-sama');
    expect(storage.log.where((entry) => entry == 'delete'), isEmpty);
  });

  test('logout server menghapus token bila epoch dan token masih sama', () async {
    final c = container((t) async => Akun(akunJson()));
    final n = c.read(sessionProvider.notifier);
    await n.signIn('token-lama', Akun(akunJson(id: 'a-1')));
    await n.signOutIfCurrent(token: 'token-lama', epoch: n.epoch);
    expect(c.read(sessionProvider).signedIn, isFalse);
    expect(c.read(sessionProvider).akun, isNull);
    expect(storage.token, isNull);
  });

  test('updateAkun dari epoch lama tidak menimpa akun sesi pengganti', () async {
    final c = container((t) async => Akun(akunJson()));
    final n = c.read(sessionProvider.notifier);
    await n.signIn('token-lama', Akun(akunJson(id: 'a-1')));
    final epoch = n.epoch;
    await n.signIn('token-baru', Akun(akunJson(id: 'a-2')));
    n.updateAkun(Akun(akunJson(id: 'a-lama')), epoch: epoch);
    expect(c.read(sessionProvider).akun!.id, 'a-2');
    expect(c.read(sessionProvider).token, 'token-baru');
    n.updateAkun(Akun(akunJson(id: 'a-segar')), epoch: n.epoch);
    expect(c.read(sessionProvider).akun!.id, 'a-segar');
    expect(storage.token, 'token-baru');
  });

  group('interceptor 401 Api', () {
    test('401 untuk token sesi aktif → sesi berakhir dan token dihapus', () async {
      final adapter = FakeAdapter((o) => errorBody(401, 'UNAUTHENTICATED', 'Sesi berakhir.'));
      final c = container((t) async => Akun(akunJson()), adapter: adapter);
      await c.read(sessionProvider.notifier).restore();
      await expectLater(c.read(apiProvider).get('/dues/my'), throwsA(isA<ApiException>().having((e) => e.status, 'status', 401)));
      await pumpEventQueue();
      expect(adapter.requests.single.headers['Authorization'], 'Bearer token-lama');
      expect(c.read(sessionProvider).expired, isTrue);
      expect(storage.token, isNull);
    });

    test('401 terlambat dengan token sama tetapi epoch baru tidak mengakhiri sesi', () async {
      final gate = Completer<void>();
      final adapter = FakeAdapter((o) async {
        await gate.future;
        return errorBody(401, 'UNAUTHENTICATED', 'Sesi berakhir.');
      });
      final c = container((t) async => Akun(akunJson()), adapter: adapter);
      await c.read(sessionProvider.notifier).restore();
      final old = c.read(apiProvider).get('/dues/my');
      await pumpEventQueue();
      await c.read(sessionProvider.notifier).signIn('token-lama', Akun(akunJson(id: 'a-2')));
      gate.complete();
      await expectLater(old, throwsA(isA<ApiException>()));
      await pumpEventQueue();
      expect(c.read(sessionProvider).signedIn, isTrue);
      expect(c.read(sessionProvider).akun!.id, 'a-2');
      expect(c.read(sessionProvider).expired, isFalse);
      expect(storage.token, 'token-lama');
    });

    test('401 terlambat milik token lama tidak mengakhiri sesi baru', () async {
      final gate = Completer<void>();
      final adapter = FakeAdapter((o) async {
        await gate.future;
        return errorBody(401, 'UNAUTHENTICATED', 'Sesi berakhir.');
      });
      final c = container((t) async => Akun(akunJson()), adapter: adapter);
      await c.read(sessionProvider.notifier).restore();
      final old = c.read(apiProvider).get('/dues/my');
      await pumpEventQueue();
      await c.read(sessionProvider.notifier).signIn('token-baru', Akun(akunJson(id: 'a-2')));
      gate.complete();
      await expectLater(old, throwsA(isA<ApiException>()));
      await pumpEventQueue();
      expect(c.read(sessionProvider).signedIn, isTrue);
      expect(storage.token, 'token-baru');
    });

    test('503 tanpa envelope (gateway) dipetakan SERVICE_UNAVAILABLE dan sesi tetap', () async {
      final adapter = FakeAdapter(
        (o) => ResponseBody.fromString(
          '<html>Bad gateway</html>',
          503,
          headers: {
            Headers.contentTypeHeader: ['text/html'],
          },
        ),
      );
      final c = container((t) async => Akun(akunJson()), adapter: adapter);
      await c.read(sessionProvider.notifier).restore();
      await expectLater(c.read(apiProvider).get('/dues/my'), throwsA(isA<ApiException>().having((e) => e.code, 'code', 'SERVICE_UNAVAILABLE')));
      expect(c.read(sessionProvider).signedIn, isTrue);
      expect(storage.token, 'token-lama');
    });
  });
}
