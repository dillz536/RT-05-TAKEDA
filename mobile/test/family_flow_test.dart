import 'dart:convert';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:rt05_takeda/app/config.dart';
import 'package:rt05_takeda/app/theme.dart';
import 'package:rt05_takeda/core/health.dart';
import 'package:rt05_takeda/core/session.dart';
import 'package:rt05_takeda/features/auth/auth_pages.dart';
import 'package:rt05_takeda/features/household/family_page.dart';

import 'support/app_harness.dart';
import 'support/fakes.dart';
import 'support/readable_text.dart';

void main() {
  testWidgets('akun tambahan hanya membaca keluarga sendiri', (tester) async {
    final fixtures = shellFixtures();
    ((fixtures['/api/v1/household']! as Map)['data'] as Map)['dapat_ubah'] = false;
    await pumpApp(
      tester,
      storage: MemoryTokenStorage('t'),
      handler: apiHandler(
        me: () => akunJson(jenis: 'tambahan'),
        fixtures: fixtures,
      ),
    );
    await tester.tap(find.widgetWithText(NavigationDestination, 'Akun'));
    await tester.pumpAndSettle();
    expect(find.text('Kepala Keluarga Uji'), findsOneWidget);
    expect(find.text('Anggota Keluarga Uji'), findsOneWidget);
    expect(find.text('Perubahan data keluarga dilakukan oleh akun utama.'), findsOneWidget);
    expect(find.text('Ubah data keluarga'), findsNothing);
    expect(find.text('Tambah anggota'), findsNothing);
    expect(find.text('Tandai pindah'), findsNothing);
    expect(find.text('Keluarga baru'), findsNothing);
    expect(find.text('Ubah alamat'), findsNothing);
    expect(find.text('Pindah akun utama'), findsNothing);
  });

  testWidgets('akun menunggu tidak membuka data keluarga', (tester) async {
    final adapter = await pumpApp(
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
    expect(find.text('Keluarga'), findsNothing);
    expect(adapter.requests.where((request) => request.uri.path.contains('household')), isEmpty);
  });

  testWidgets('konflik versi mempertahankan teks, memuat ulang, lalu mengirim versi baru', (tester) async {
    var householdGets = 0;
    var patches = 0;
    Object? patch;
    final base = apiHandler(me: akunJson);
    await pumpApp(
      tester,
      storage: MemoryTokenStorage('t'),
      handler: (options) {
        if (options.uri.path == '/api/v1/household' && options.method == 'GET') {
          householdGets += 1;
          final versi = householdGets == 1 ? 1 : 2;
          final nama = householdGets == 1 ? 'Kepala Keluarga Uji' : 'Nama Baru';
          return jsonBody(200, {
            'data': {
              'id': 'k-1',
              'versi': versi,
              'status': 'terverifikasi',
              'jenis_hunian': 'pemilik',
              'dapat_ubah': true,
              'rumah': {'kode_rumah': 'A-01', 'alamat': 'Jalan Uji Coba Nomor 1 Blok A'},
              'anggota': [
                {'id': 'w-1', 'nama': nama, 'hubungan_keluarga': 'Kepala keluarga', 'is_kepala': true, 'punya_akun': true},
              ],
            },
          });
        }
        if (options.uri.path == '/api/v1/household' && options.method == 'PATCH') {
          patches += 1;
          patch = _body(options);
          if (patches == 1) return errorBody(409, 'VERSION_CONFLICT', 'Data keluarga sudah berubah. Muat ulang lalu coba lagi.');
          return jsonBody(200, {
            'data': {'id': 'k-1', 'versi': 2},
          });
        }
        return base(options);
      },
    );
    await tester.tap(find.widgetWithText(NavigationDestination, 'Akun'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Ubah data keluarga'));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField).first, 'Nama Ditulis Duluan');
    await tester.tap(find.widgetWithText(FilledButton, 'Simpan perubahan'));
    await tester.pumpAndSettle();

    expect(patch, {'versi': 1, 'nama_kepala': 'Nama Ditulis Duluan', 'whatsapp': null});
    expect((patch as Map).containsKey('peran'), isFalse);
    expect(find.byType(FamilyEditPage), findsOneWidget);
    expect(tester.widget<TextField>(find.byType(TextField).first).controller!.text, 'Nama Ditulis Duluan');
    const kept = 'Data keluarga sudah berubah. Muat ulang lalu coba lagi. Teks yang Anda ketik tetap ada. Periksa data terbaru, lalu kirim lagi.';
    await scrollUntilReadable(tester, find.text(kept));
    expectTextFullyVisible(tester, kept);
    expect(find.text('Data terbaru: Nama Baru.'), findsOneWidget);
    expect(householdGets, greaterThanOrEqualTo(2));

    await tester.tap(find.widgetWithText(FilledButton, 'Simpan perubahan'));
    await tester.pumpAndSettle();
    expect(patch, {'versi': 2, 'nama_kepala': 'Nama Ditulis Duluan', 'whatsapp': null});
    expect(find.byType(FamilyEditPage), findsNothing);
  });

  testWidgets('arsip anggota mengirim status pindah dan kepala tidak bisa diarsipkan', (tester) async {
    Object? body;
    String? method;
    final base = apiHandler(me: akunJson);
    await pumpApp(
      tester,
      storage: MemoryTokenStorage('t'),
      handler: (options) {
        if (options.uri.path == '/api/v1/household/members/w-2') {
          method = options.method;
          body = _body(options);
          return ResponseBody.fromString(
            '',
            204,
            headers: {
              Headers.contentTypeHeader: [Headers.jsonContentType],
            },
          );
        }
        return base(options);
      },
    );
    await tester.tap(find.widgetWithText(NavigationDestination, 'Akun'));
    await tester.pumpAndSettle();
    await tester.ensureVisible(find.text('Kepala Keluarga Uji'));
    await tester.tap(find.text('Kepala Keluarga Uji'));
    await tester.pumpAndSettle();
    expect(find.text('Tandai pindah'), findsNothing);
    await tester.tap(find.byType(BackButton));
    await tester.pumpAndSettle();
    await tester.ensureVisible(find.text('Anggota Keluarga Uji'));
    await tester.tap(find.text('Anggota Keluarga Uji'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Tandai pindah'));
    await tester.pumpAndSettle();
    await tester.tap(find.widgetWithText(FilledButton, 'Tandai pindah'));
    await tester.pumpAndSettle();
    expect(method, 'DELETE');
    expect(body, {'status': 'pindah', 'versi': 1});
    expect(find.byType(MemberPage), findsNothing);
  });

  testWidgets('formulir keluarga skala 2.0 tidak overflow', (tester) async {
    useCompactScreen(tester, width: 320, textScale: 2, height: 700);
    await _pumpForm(
      tester,
      FamilyEditPage(
        household: {
          'versi': 1,
          'anggota': [
            {'nama': 'Kepala Keluarga Uji', 'is_kepala': true, 'whatsapp': '081200000001'},
          ],
        },
      ),
    );
    expect(find.text('Data keluarga'), findsOneWidget);
    expect(tester.takeException(), isNull);

    await _pumpForm(tester, const MemberPage());
    expect(find.text('Anggota baru'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets('validasi keluarga dan anggota terbaca utuh pada 320 skala 2', (tester) async {
    useCompactScreen(tester, width: 320, textScale: 2, height: 700);
    const waError = 'Nomor 8–25 karakter: angka, spasi, plus, atau tanda hubung.';
    await _pumpForm(
      tester,
      FamilyEditPage(
        household: {
          'versi': 1,
          'anggota': [
            {'nama': 'Kepala Keluarga Uji', 'is_kepala': true, 'whatsapp': '081200000001'},
          ],
        },
      ),
    );
    await tester.enterText(find.widgetWithText(TextField, 'Nama kepala'), '');
    await scrollUntilReadable(tester, find.widgetWithText(TextField, 'WhatsApp'));
    await tester.enterText(find.widgetWithText(TextField, 'WhatsApp'), 'abc');
    await scrollUntilReadable(tester, find.widgetWithText(FilledButton, 'Simpan perubahan'));
    await tester.tap(find.widgetWithText(FilledButton, 'Simpan perubahan'));
    await tester.pumpAndSettle();
    await scrollUntilReadable(tester, find.text('Nama kepala wajib diisi.'));
    expectTextFullyVisible(tester, 'Nama kepala wajib diisi.');
    await scrollUntilReadable(tester, find.text(waError));
    expectTextFullyVisible(tester, waError);
    expect(tester.widget<TextField>(find.widgetWithText(TextField, 'WhatsApp')).controller!.text, 'abc');
    expect(tester.takeException(), isNull);

    await _pumpForm(tester, const MemberPage());
    await scrollUntilReadable(tester, find.widgetWithText(FilledButton, 'Tambah anggota'));
    await tester.tap(find.widgetWithText(FilledButton, 'Tambah anggota'));
    await tester.pumpAndSettle();
    await scrollUntilReadable(tester, find.text('Nama wajib diisi.'));
    expectTextFullyVisible(tester, 'Nama wajib diisi.');
    await scrollUntilReadable(tester, find.text('Hubungan wajib diisi.'));
    expectTextFullyVisible(tester, 'Hubungan wajib diisi.');
    expect(tester.takeException(), isNull);
  });

  testWidgets('konflik 409 keluarga terbaca utuh pada 320 skala 2', (tester) async {
    useCompactScreen(tester, width: 320, textScale: 2, height: 700);
    const kept = 'Data keluarga sudah berubah. Muat ulang lalu coba lagi. Teks yang Anda ketik tetap ada. Periksa data terbaru, lalu kirim lagi.';
    await _pumpForm(
      tester,
      FamilyEditPage(
        household: {
          'versi': 1,
          'dapat_ubah': true,
          'anggota': [
            {'nama': 'Kepala Keluarga Uji', 'is_kepala': true, 'whatsapp': '081200000001'},
          ],
        },
      ),
      handler: (options) {
        if (options.method == 'PATCH') return errorBody(409, 'VERSION_CONFLICT', 'Data keluarga sudah berubah. Muat ulang lalu coba lagi.');
        if (options.method == 'GET') {
          return jsonBody(200, {
            'data': {
              'versi': 2,
              'dapat_ubah': true,
              'anggota': [
                {'nama': 'Nama Baru', 'is_kepala': true, 'whatsapp': '081200000009'},
              ],
            },
          });
        }
        return healthOk();
      },
    );
    await tester.enterText(find.widgetWithText(TextField, 'Nama kepala'), 'Nama Ditulis Duluan');
    await scrollUntilReadable(tester, find.widgetWithText(TextField, 'WhatsApp'));
    await tester.enterText(find.widgetWithText(TextField, 'WhatsApp'), '081211122233');
    await scrollUntilReadable(tester, find.widgetWithText(FilledButton, 'Simpan perubahan'));
    await tester.tap(find.widgetWithText(FilledButton, 'Simpan perubahan'));
    await tester.pumpAndSettle();
    expect(tester.widget<TextField>(find.widgetWithText(TextField, 'Nama kepala')).controller!.text, 'Nama Ditulis Duluan');
    expect(tester.widget<TextField>(find.widgetWithText(TextField, 'WhatsApp')).controller!.text, '081211122233');
    await scrollUntilReadable(tester, find.text(kept));
    expectTextFullyVisible(tester, kept);
    expect(tester.takeException(), isNull);
  });

  testWidgets('sesi lama masih boleh ubah tetapi keluarga baru tidak: baca saja', (tester) async {
    final fixtures = shellFixtures();
    ((fixtures['/api/v1/household']! as Map)['data'] as Map)['dapat_ubah'] = false;
    await pumpApp(
      tester,
      storage: MemoryTokenStorage('t'),
      handler: apiHandler(me: akunJson, fixtures: fixtures),
    );
    await tester.tap(find.widgetWithText(NavigationDestination, 'Akun'));
    await tester.pumpAndSettle();
    expect(find.text('Ubah data keluarga'), findsNothing);
    expect(find.text('Tambah anggota'), findsNothing);
    await tester.tap(find.text('Anggota Keluarga Uji'));
    await tester.pumpAndSettle();
    expect(find.byType(MemberPage), findsNothing);
    expect(find.text('Perubahan data keluarga dilakukan oleh akun utama.'), findsOneWidget);
  });

  testWidgets('sesi lama tidak boleh ubah tetapi keluarga baru boleh: tombol ubah', (tester) async {
    final fixtures = shellFixtures();
    ((fixtures['/api/v1/household']! as Map)['data'] as Map)['dapat_ubah'] = true;
    await pumpApp(
      tester,
      storage: MemoryTokenStorage('t'),
      handler: apiHandler(
        me: () => akunJson(jenis: 'tambahan'),
        fixtures: fixtures,
      ),
    );
    await tester.tap(find.widgetWithText(NavigationDestination, 'Akun'));
    await tester.pumpAndSettle();
    await scrollUntilReadable(tester, find.text('Ubah data keluarga'));
    expect(find.text('Ubah data keluarga'), findsOneWidget);
    expect(find.text('Tambah anggota'), findsOneWidget);
    expect(find.text('Perubahan data keluarga dilakukan oleh akun utama.'), findsNothing);
    await tester.tap(find.text('Ubah data keluarga'));
    await tester.pumpAndSettle();
    expect(find.byType(FamilyEditPage), findsOneWidget);
  });

  testWidgets('403 mempertahankan nama, WhatsApp, dan anggota lalu menutup tombol ubah', (tester) async {
    useCompactScreen(tester, width: 320, textScale: 2, height: 800);
    const forbidden = 'Hanya akun utama yang dapat mengubah data keluarga.';
    var denied = false;
    var meCount = 0;
    final base = apiHandler(
      me: () {
        meCount += 1;
        return meCount == 1 ? akunJson() : akunJson(jenis: 'tambahan');
      },
    );
    await pumpApp(
      tester,
      storage: MemoryTokenStorage('t'),
      handler: (options) {
        if (options.uri.path == '/api/v1/household' && options.method == 'GET') {
          return jsonBody(200, _household(dapatUbah: !denied));
        }
        if (options.uri.path == '/api/v1/household' && options.method == 'PATCH') {
          denied = true;
          return errorBody(403, 'PRIMARY_ACCOUNT_REQUIRED', forbidden);
        }
        if (options.uri.path == '/api/v1/household/members/w-2' && options.method == 'PATCH') {
          denied = true;
          return errorBody(403, 'PRIMARY_ACCOUNT_REQUIRED', forbidden);
        }
        return base(options);
      },
    );
    await tester.tap(find.widgetWithText(NavigationDestination, 'Akun'));
    await tester.pumpAndSettle();
    await scrollUntilReadable(tester, find.text('Ubah data keluarga'));
    await tester.tap(find.text('Ubah data keluarga'));
    await tester.pumpAndSettle();
    await tester.enterText(find.widgetWithText(TextField, 'Nama kepala'), 'Nama Ditulis Duluan');
    await scrollUntilReadable(tester, find.widgetWithText(TextField, 'WhatsApp'));
    await tester.enterText(find.widgetWithText(TextField, 'WhatsApp'), '081299988877');
    await scrollUntilReadable(tester, find.widgetWithText(FilledButton, 'Simpan perubahan'));
    await tester.tap(find.widgetWithText(FilledButton, 'Simpan perubahan'));
    await tester.pumpAndSettle();
    expect(find.byType(FamilyEditPage), findsOneWidget);
    expect(tester.widget<TextField>(find.widgetWithText(TextField, 'Nama kepala')).controller!.text, 'Nama Ditulis Duluan');
    expect(tester.widget<TextField>(find.widgetWithText(TextField, 'WhatsApp')).controller!.text, '081299988877');
    await scrollUntilReadable(tester, find.text(forbidden));
    expectTextFullyVisible(tester, forbidden);
    expect(meCount, greaterThanOrEqualTo(2));
    expect(tester.takeException(), isNull);
    await tester.tap(find.byType(BackButton));
    await tester.pumpAndSettle();
    expect(find.text('Ubah data keluarga'), findsNothing);
    expect(find.text('Tambah anggota'), findsNothing);
    await scrollUntilReadable(tester, find.text('Perubahan data keluarga dilakukan oleh akun utama.'));
    expectTextFullyVisible(tester, 'Perubahan data keluarga dilakukan oleh akun utama.');
    expect(tester.takeException(), isNull);
  });

  testWidgets('403 anggota mempertahankan nama, hubungan, dan WhatsApp', (tester) async {
    useCompactScreen(tester, width: 320, textScale: 2, height: 800);
    const forbidden = 'Hanya akun utama yang dapat mengubah data keluarga.';
    var denied = false;
    var meCount = 0;
    final base = apiHandler(
      me: () {
        meCount += 1;
        return meCount == 1 ? akunJson() : akunJson(jenis: 'tambahan');
      },
    );
    await pumpApp(
      tester,
      storage: MemoryTokenStorage('t'),
      handler: (options) {
        if (options.uri.path == '/api/v1/household' && options.method == 'GET') {
          return jsonBody(200, _household(dapatUbah: !denied));
        }
        if (options.uri.path == '/api/v1/household/members/w-2' && options.method == 'PATCH') {
          denied = true;
          return errorBody(403, 'PRIMARY_ACCOUNT_REQUIRED', forbidden);
        }
        return base(options);
      },
    );
    await tester.tap(find.widgetWithText(NavigationDestination, 'Akun'));
    await tester.pumpAndSettle();
    await scrollUntilReadable(tester, find.text('Anggota Keluarga Uji'));
    await tester.tap(find.text('Anggota Keluarga Uji'));
    await tester.pumpAndSettle();
    await tester.enterText(find.widgetWithText(TextField, 'Nama lengkap'), 'Ani Ditulis Duluan');
    await scrollUntilReadable(tester, find.widgetWithText(TextField, 'Hubungan'));
    await tester.enterText(find.widgetWithText(TextField, 'Hubungan'), 'Keponakan');
    await scrollUntilReadable(tester, find.widgetWithText(TextField, 'WhatsApp'));
    await tester.enterText(find.widgetWithText(TextField, 'WhatsApp'), '081255566677');
    await scrollUntilReadable(tester, find.widgetWithText(FilledButton, 'Simpan perubahan'));
    await tester.tap(find.widgetWithText(FilledButton, 'Simpan perubahan'));
    await tester.pumpAndSettle();
    expect(find.byType(MemberPage), findsOneWidget);
    expect(tester.widget<TextField>(find.widgetWithText(TextField, 'Nama lengkap')).controller!.text, 'Ani Ditulis Duluan');
    expect(tester.widget<TextField>(find.widgetWithText(TextField, 'Hubungan')).controller!.text, 'Keponakan');
    expect(tester.widget<TextField>(find.widgetWithText(TextField, 'WhatsApp')).controller!.text, '081255566677');
    await scrollUntilReadable(tester, find.text(forbidden));
    expectTextFullyVisible(tester, forbidden);
    expect(meCount, greaterThanOrEqualTo(2));
    expect(tester.takeException(), isNull);
  });

  testWidgets('pengurus menonaktifkan akun lain tanpa mengirim peran', (tester) async {
    Object? patch;
    final fixtures = shellFixtures();
    fixtures['/api/v1/admin/accounts'] = {
      'data': [
        {'id': 'a-1', 'nama': 'Pengurus Uji', 'email': 'pengurus@example.test', 'peran': 'pengurus', 'jenis': 'utama', 'status': 'aktif'},
        {'id': 'a-2', 'nama': 'Warga Lain', 'email': 'lain@example.test', 'peran': 'warga', 'jenis': 'tambahan', 'status': 'aktif'},
        {'id': 'a-3', 'nama': 'Pemohon Uji', 'email': 'tunggu@example.test', 'peran': 'warga', 'jenis': 'tambahan', 'status': 'menunggu'},
      ],
      'meta': {'total': 3},
    };
    final base = apiHandler(
      me: () => akunJson(id: 'a-1', peran: 'pengurus'),
      fixtures: fixtures,
    );
    await pumpApp(
      tester,
      storage: MemoryTokenStorage('t'),
      handler: (options) {
        if (options.uri.path == '/api/v1/admin/accounts/a-2' && options.method == 'PATCH') {
          patch = _body(options);
          return jsonBody(200, {'data': fixtures['/api/v1/admin/accounts']});
        }
        return base(options);
      },
    );
    await tester.tap(find.widgetWithText(NavigationDestination, 'Warga'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Akun warga'));
    await tester.pumpAndSettle();
    expect(find.text('Status akun sendiri tidak diubah dari perangkat ini.'), findsOneWidget);
    expect(find.text('Akun menunggu diproses melalui permohonan.'), findsOneWidget);
    final nonaktif = find.text('Nonaktifkan');
    expect(nonaktif, findsOneWidget);
    await tester.ensureVisible(nonaktif);
    await tester.pumpAndSettle();
    await tester.tap(nonaktif);
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField), 'Tidak lagi tinggal di RT');
    await tester.tap(find.widgetWithText(FilledButton, 'Nonaktifkan'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 400));
    await tester.tap(find.widgetWithText(FilledButton, 'Nonaktifkan').last);
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 400));
    expect(patch, {'status': 'nonaktif', 'alasan': 'Tidak lagi tinggal di RT'});
    expect((patch as Map).containsKey('peran'), isFalse);
    expect((patch as Map).containsKey('role'), isFalse);
    await tester.pump();
    await tester.pump(const Duration(seconds: 1));
  });
}

Future<void> _pumpForm(WidgetTester tester, Widget page, {FakeHandler? handler}) async {
  await tester.pumpWidget(
    ProviderScope(
      overrides: [
        appConfigProvider.overrideWithValue(AppConfig.parse(flavor: 'staging', apiBaseUrl: 'https://api.example.test')),
        tokenStorageProvider.overrideWithValue(MemoryTokenStorage()),
        httpAdapterProvider.overrideWithValue(FakeAdapter(handler ?? (_) => healthOk())),
      ],
      child: MaterialApp(theme: buildTheme(), home: page),
    ),
  );
  await tester.pumpAndSettle();
}

Map<String, Object?> _household({required bool dapatUbah, int versi = 1}) => {
  'data': {
    'id': 'k-1',
    'versi': versi,
    'status': 'terverifikasi',
    'jenis_hunian': 'pemilik',
    'dapat_ubah': dapatUbah,
    'rumah': {'kode_rumah': 'A-01', 'alamat': 'Jalan Uji Coba Nomor 1 Blok A'},
    'anggota': [
      {'id': 'w-1', 'nama': 'Kepala Keluarga Uji', 'hubungan_keluarga': 'Kepala keluarga', 'is_kepala': true, 'punya_akun': true, 'whatsapp': '081200000001'},
      {'id': 'w-2', 'nama': 'Anggota Keluarga Uji', 'hubungan_keluarga': 'Anak', 'is_kepala': false, 'punya_akun': false, 'whatsapp': '081200000002'},
    ],
  },
};

Object? _body(RequestOptions options) {
  final data = options.data;
  if (data is Map) return Map<String, dynamic>.from(data);
  if (data is String && data.isNotEmpty) return jsonDecode(data);
  return data;
}
