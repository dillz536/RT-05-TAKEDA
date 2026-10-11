import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:rt05_takeda/app/router.dart';
import 'package:rt05_takeda/core/api.dart';
import 'package:rt05_takeda/core/health.dart';
import 'package:rt05_takeda/core/models.dart';
import 'package:rt05_takeda/core/session.dart';
import 'package:rt05_takeda/features/auth/auth_pages.dart';

import 'support/app_harness.dart';
import 'support/fakes.dart';

void main() {
  group('sessionRedirect (guard murni)', () {
    const healthy = HealthState.ok(HealthOk());
    final guest = const SessionState(loading: false);
    final active = SessionState(loading: false, token: 't', akun: Akun(akunJson()));
    final pending = SessionState(
      loading: false,
      token: 't',
      akun: Akun(akunJson(status: 'menunggu', jenis: 'tambahan')),
    );
    final unavailable = SessionState(loading: false, token: 't', unavailable: ApiException(503, 'SERVICE_UNAVAILABLE', 'x'));

    String? go(HealthState h, SessionState s, String loc) => sessionRedirect(health: h, session: s, location: loc);

    test('server belum terverifikasi atau gagal → splash', () {
      expect(go(const HealthState.checking(), active, Routes.home), Routes.splash);
      expect(go(const HealthState.failed(HealthException(HealthFailure.offline)), guest, Routes.login), Routes.splash);
      expect(go(const HealthState.checking(), guest, Routes.splash), isNull);
    });

    test('sesi dipulihkan atau tidak tersedia → splash, data akun tidak dibuka', () {
      expect(go(healthy, const SessionState(), Routes.home), Routes.splash);
      expect(go(healthy, unavailable, Routes.home), Routes.splash);
      expect(go(healthy, unavailable, Routes.notifications), Routes.splash);
    });

    test('tamu hanya login/daftar/lupa sandi', () {
      expect(go(healthy, guest, Routes.splash), Routes.login);
      expect(go(healthy, guest, Routes.home), Routes.login);
      expect(go(healthy, guest, Routes.notifications), Routes.login);
      expect(go(healthy, guest, Routes.pending), Routes.login);
      for (final r in Routes.guest) {
        expect(go(healthy, guest, r), isNull, reason: r);
      }
    });

    test('akun menunggu persetujuan terkunci di pending', () {
      for (final r in [Routes.home, Routes.notifications, Routes.login, Routes.splash]) {
        expect(go(healthy, pending, r), Routes.pending, reason: r);
      }
      expect(go(healthy, pending, Routes.pending), isNull);
    });

    test('akun aktif: beranda/notifikasi; halaman tamu, pending, rute asing → beranda', () {
      expect(go(healthy, active, Routes.home), isNull);
      expect(go(healthy, active, Routes.notifications), isNull);
      for (final r in [Routes.splash, Routes.login, Routes.register, Routes.forgot, Routes.pending, '/tidak-ada']) {
        expect(go(healthy, active, r), Routes.home, reason: r);
      }
    });

    test('sesi kedaluwarsa → login', () {
      expect(go(healthy, const SessionState(loading: false, expired: true), Routes.home), Routes.login);
    });
  });

  group('aplikasi utuh', () {
    testWidgets('tamu: health nyata lalu halaman login dengan diagnostik request_id', (tester) async {
      final adapter = await pumpApp(
        tester,
        storage: MemoryTokenStorage(),
        handler: apiHandler(me: akunJson),
      );
      expect(find.byType(LoginPage), findsOneWidget);
      expect(adapter.requests.map((r) => r.uri.path), contains('/health/live'));
      expect(adapter.requests.map((r) => r.uri.path), isNot(contains('/api/v1/auth/me')));
      expect(find.textContaining(healthRequestId), findsOneWidget);
    });

    testWidgets('akun tambahan menunggu → halaman pending', (tester) async {
      await pumpApp(
        tester,
        storage: MemoryTokenStorage('t'),
        handler: apiHandler(
          me: () => akunJson(status: 'menunggu', jenis: 'tambahan'),
          fixtures: {
            '/api/v1/auth/additional-account-status': {
              'data': {
                'permohonan': {'status': 'menunggu'},
              },
            },
          },
        ),
      );
      expect(find.byType(PendingPage), findsOneWidget);
      expect(find.text('Menunggu persetujuan'), findsOneWidget);
    });

    testWidgets('warga aktif → shell warga', (tester) async {
      await pumpApp(
        tester,
        storage: MemoryTokenStorage('t'),
        handler: apiHandler(me: akunJson),
      );
      expect(find.widgetWithText(NavigationDestination, 'Beranda'), findsOneWidget);
      expect(find.text('Iuran bulan ini'), findsOneWidget);
    });

    testWidgets('pengurus aktif → shell pengurus (peran dari server)', (tester) async {
      await pumpApp(
        tester,
        storage: MemoryTokenStorage('t'),
        handler: apiHandler(
          me: () => akunJson(id: 'p-1', peran: 'pengurus'),
        ),
      );
      expect(find.widgetWithText(NavigationDestination, 'Ringkasan'), findsOneWidget);
      expect(find.text('Saldo kas RT'), findsOneWidget);
    });

    testWidgets('notifikasi dibuka lewat rute /notifications', (tester) async {
      await pumpApp(
        tester,
        storage: MemoryTokenStorage('t'),
        handler: apiHandler(me: akunJson),
      );
      await tester.tap(find.byTooltip('Notifikasi'));
      await tester.pumpAndSettle();
      expect(find.text('Status pengaduan berubah'), findsOneWidget);
    });

    testWidgets('401 saat dipakai → token dihapus, kembali ke login dengan banner sesi berakhir', (tester) async {
      final storage = MemoryTokenStorage('t');
      var revoked = false;
      final base = apiHandler(me: akunJson);
      await pumpApp(
        tester,
        storage: storage,
        handler: (o) => revoked && o.uri.path.startsWith('/api/') ? errorBody(401, 'UNAUTHENTICATED', 'Sesi berakhir.') : base(o),
      );
      expect(find.text('Iuran bulan ini'), findsOneWidget);

      // Token dicabut server; tarik-untuk-muat-ulang memicu request baru → 401.
      revoked = true;
      await tester.fling(find.text('Iuran bulan ini'), const Offset(0, 400), 1000);
      await tester.pumpAndSettle();
      expect(find.byType(LoginPage), findsOneWidget);
      expect(find.text('Sesi berakhir. Silakan masuk kembali.'), findsOneWidget);
      expect(storage.token, isNull);
    });

    testWidgets('server offline: splash + coba lagi, token tetap; setelah online masuk beranda', (tester) async {
      final storage = MemoryTokenStorage('t');
      var online = false;
      final base = apiHandler(me: akunJson);
      await pumpApp(tester, storage: storage, handler: (o) => online ? base(o) : offline(o));

      expect(find.text('Tidak dapat terhubung ke server. Periksa koneksi internet.'), findsOneWidget);
      expect(find.text('Coba lagi'), findsOneWidget);
      expect(find.text('Iuran bulan ini'), findsNothing);
      expect(storage.token, 't');

      online = true;
      await tester.tap(find.text('Coba lagi'));
      await tester.pumpAndSettle();
      expect(find.text('Iuran bulan ini'), findsOneWidget);
      expect(storage.token, 't');
    });

    testWidgets('/auth/me 503: sesi tidak tersedia tanpa data akun; Keluar menghapus token', (tester) async {
      final storage = MemoryTokenStorage('t');
      final base = apiHandler(me: akunJson);
      await pumpApp(
        tester,
        storage: storage,
        handler: (o) => o.uri.path == '/api/v1/auth/me' ? errorBody(503, 'SERVICE_UNAVAILABLE', 'Layanan sementara tidak tersedia.') : base(o),
      );

      expect(find.text('Layanan sementara tidak tersedia.'), findsOneWidget);
      expect(find.textContaining('Warga Uji'), findsNothing);
      expect(storage.token, 't');

      await tester.tap(find.text('Keluar dari perangkat ini'));
      await tester.pumpAndSettle();
      expect(find.byType(LoginPage), findsOneWidget);
      expect(storage.token, isNull);
    });
  });
}
