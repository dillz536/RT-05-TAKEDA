import 'package:dio/dio.dart';
import 'package:flutter/widgets.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:rt05_takeda/app/config.dart';
import 'package:rt05_takeda/core/health.dart';
import 'package:rt05_takeda/core/models.dart';
import 'package:rt05_takeda/core/session.dart';
import 'package:rt05_takeda/features/auth/account_access.dart';
import 'package:rt05_takeda/features/warga/providers.dart';

import 'support/fakes.dart';

/// Regresi F1: bearer dan epoch logout dipatok saat request dibuat.
/// Sesi pengganti dipasang sebelum `onRequest` (timer Dio) berjalan.
void main() {
  late MemoryTokenStorage storage;

  ProviderContainer container(HttpClientAdapter adapter) {
    final c = ProviderContainer(
      overrides: [
        appConfigProvider.overrideWithValue(AppConfig.parse(flavor: 'staging', apiBaseUrl: 'https://api.example.test')),
        tokenStorageProvider.overrideWithValue(storage),
        fetchMeProvider.overrideWithValue((_) async => Akun(akunJson())),
        httpAdapterProvider.overrideWithValue(adapter),
      ],
    );
    addTearDown(c.dispose);
    return c;
  }

  setUp(() => storage = MemoryTokenStorage());

  ResponseBody noContent() => ResponseBody.fromString(
    '',
    204,
    headers: {
      Headers.contentTypeHeader: [Headers.jsonContentType],
    },
  );

  for (final (label, path, sameToken, unauthorized) in [
    ('logout', '/auth/logout', false, false),
    ('logout-all', '/auth/logout-all', false, false),
    ('logout token sama epoch baru', '/auth/logout', true, true),
  ]) {
    testWidgets('$label mematok bearer lama sebelum onRequest; sesi dan cache baru tetap', (tester) async {
      final adapter = FakeAdapter((o) {
        if (o.uri.path.endsWith('/dues/my')) {
          return jsonBody(200, {
            'data': {
              'tarif_berlaku': 75000,
              'rumah': {'kode_rumah': 'A-01'},
            },
          });
        }
        if (unauthorized) return errorBody(401, 'UNAUTHENTICATED', 'Sesi berakhir.');
        return noContent();
      });
      final c = container(adapter);
      final ref = await bindRef(tester, c);
      final n = c.read(sessionProvider.notifier);
      final oldToken = sameToken ? 'token-sama' : 'token-lama';
      final newToken = sameToken ? 'token-sama' : 'token-baru';
      await n.signIn(oldToken, Akun(akunJson(id: 'a-1')));
      final oldEpoch = n.epoch;
      final keep = c.listen(duesMyProvider, (_, _) {});
      addTearDown(keep.close);
      final dues = c.read(duesMyProvider.future);
      await tester.pump(const Duration(milliseconds: 50));
      expect((await dues)['tarif_berlaku'], 75000);
      final duesCalls = adapter.requests.where((r) => r.uri.path.endsWith('/dues/my')).length;

      final pending = revokeServerSession(ref, path: path, data: path.endsWith('logout-all') ? {'password': 'Rahasia1'} : null);
      await n.signIn(newToken, Akun(akunJson(id: 'a-2')));
      expect(adapter.requests.where((r) => r.uri.path.endsWith(path)), isEmpty, reason: 'signIn selesai sebelum onRequest');
      expect(n.epoch, isNot(oldEpoch));

      await tester.pump(const Duration(milliseconds: 50));
      await pending;

      final logout = adapter.requests.where((r) => r.uri.path.endsWith(path)).single;
      expect(logout.uri.path, endsWith(path));
      expect(logout.headers['Authorization'], 'Bearer $oldToken');
      expect(logout.extra['sessionEpoch'], oldEpoch);
      if (path.endsWith('logout-all')) {
        expect(logout.data, {'password': 'Rahasia1'});
      } else {
        expect(logout.data, isNull);
      }
      final s = c.read(sessionProvider);
      expect(s.signedIn, isTrue);
      expect(s.expired, isFalse);
      expect(s.token, newToken);
      expect(s.akun!.id, 'a-2');
      expect(s.akun!.nama, 'Warga Uji Coba Dengan Nama Cukup Panjang');
      expect(storage.token, newToken);
      expect(storage.log.where((entry) => entry == 'delete'), isEmpty);
      expect(c.read(duesMyProvider).requireValue['tarif_berlaku'], 75000);
      expect(adapter.requests.where((r) => r.uri.path.endsWith('/dues/my')).length, duesCalls);
    });
  }
}

Future<WidgetRef> bindRef(WidgetTester tester, ProviderContainer container) async {
  late WidgetRef ref;
  await tester.pumpWidget(
    UncontrolledProviderScope(
      container: container,
      child: Consumer(
        builder: (context, widgetRef, _) {
          ref = widgetRef;
          return const SizedBox.shrink();
        },
      ),
    ),
  );
  return ref;
}
