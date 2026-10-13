import 'dart:convert';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:rt05_takeda/app/config.dart';
import 'package:rt05_takeda/app/theme.dart';
import 'package:rt05_takeda/core/health.dart';
import 'package:rt05_takeda/core/session.dart';
import 'package:rt05_takeda/features/household/admin_links.dart';
import 'package:rt05_takeda/features/household/family_page.dart';

import 'support/app_harness.dart';
import 'support/fakes.dart';
import 'support/readable_text.dart';

void main() {
  testWidgets('mulai hunian mengirim keluarga tanpa peran', (tester) async {
    Object? body;
    await _open(tester, const OccupancyBeginPage(rumahId: 'r-1', rumahLabel: 'A-01'), (options) {
      if (options.uri.path == '/api/v1/admin/families') {
        return jsonBody(200, {
          'data': [
            {'id': 'k-9', 'status': 'terverifikasi', 'kepala': 'Siti Uji', 'jumlah_anggota': 2, 'rumah': null, 'versi': 1},
          ],
          'meta': {'total': 1},
        });
      }
      if (options.uri.path == '/api/v1/admin/occupancies' && options.method == 'POST') {
        body = _body(options);
        return jsonBody(201, {
          'data': {'id': 'p-1'},
        });
      }
      return jsonBody(200, {'data': {}});
    });
    await tester.tap(find.text('Siti Uji'));
    await tester.pumpAndSettle();
    await tester.tap(find.widgetWithText(FilledButton, 'Mulai hunian'));
    await tester.pumpAndSettle();
    expect(body, {'rumah_id': 'r-1', 'keluarga_id': 'k-9', 'jenis_hunian': 'pemilik'});
    expect((body as Map).containsKey('peran'), isFalse);
    expect((body as Map).containsKey('role'), isFalse);
    expect(find.text('Buka'), findsOneWidget);
  });

  testWidgets('rumah yang masih dihuni menampilkan pesan dan tetap di formulir', (tester) async {
    await _open(tester, const OccupancyBeginPage(rumahId: 'r-1', rumahLabel: 'A-01'), (options) {
      if (options.uri.path == '/api/v1/admin/families') {
        return jsonBody(200, {
          'data': [
            {'id': 'k-9', 'kepala': 'Siti Uji', 'jumlah_anggota': 1, 'versi': 1},
          ],
          'meta': {'total': 1},
        });
      }
      if (options.method == 'POST') {
        return errorBody(409, 'HOUSE_OCCUPIED', 'Rumah masih dihuni keluarga lain.');
      }
      return jsonBody(200, {'data': {}});
    });
    await tester.tap(find.text('Siti Uji'));
    await tester.pumpAndSettle();
    await tester.tap(find.widgetWithText(FilledButton, 'Mulai hunian'));
    await tester.pumpAndSettle();
    expect(find.text('Rumah masih dihuni keluarga lain.'), findsOneWidget);
    expect(find.byType(OccupancyBeginPage), findsOneWidget);
  });

  testWidgets('akhir hunian mengirim alasan', (tester) async {
    Object? body;
    await _open(tester, const OccupancyEndPage(occupancyId: 'p-1'), (options) {
      if (options.method == 'POST') {
        body = _body(options);
        return jsonBody(200, {
          'data': {'id': 'p-1'},
        });
      }
      return jsonBody(200, {'data': {}});
    });
    await tester.enterText(find.byType(TextField).last, 'Pindah ke luar RT');
    await tester.tap(find.widgetWithText(FilledButton, 'Akhiri hunian'));
    await tester.pumpAndSettle();
    await tester.tap(find.widgetWithText(FilledButton, 'Akhiri hunian').last);
    await tester.pumpAndSettle();
    expect(body, {'alasan': 'Pindah ke luar RT'});
    expect(find.text('Buka'), findsOneWidget);
  });

  testWidgets('anggota basi menutup formulir warga', (tester) async {
    useCompactScreen(tester, width: 320, textScale: 2, height: 700);
    await _open(
      tester,
      MemberPage(household: {'versi': 4}, member: {'id': 'w-2', 'nama': 'Ani Uji', 'hubungan_keluarga': 'Anak', 'is_kepala': false, 'punya_akun': false}),
      (options) {
        if (options.method == 'DELETE') {
          return errorBody(409, 'VERSION_CONFLICT', 'Data keluarga sudah berubah. Muat ulang lalu coba lagi.');
        }
        return jsonBody(200, {'data': {}});
      },
    );
    await scrollUntilReadable(tester, find.text('Tandai pindah'));
    await tester.tap(find.text('Tandai pindah'));
    await tester.pumpAndSettle();
    await tester.tap(find.widgetWithText(FilledButton, 'Tandai pindah'));
    await tester.pumpAndSettle();
    expect(find.byType(MemberPage), findsOneWidget);
    expect(tester.widget<TextField>(find.widgetWithText(TextField, 'Nama lengkap')).controller!.text, 'Ani Uji');
    const kept = 'Data keluarga sudah berubah. Muat ulang lalu coba lagi. Teks yang Anda ketik tetap ada. Periksa data terbaru, lalu kirim lagi.';
    await scrollUntilReadable(tester, find.text(kept));
    expectTextFullyVisible(tester, kept);
    expect(tester.takeException(), isNull);
  });

  testWidgets('formulir hunian skala 2.0 tidak overflow', (tester) async {
    useCompactScreen(tester, width: 320, textScale: 2);
    await _pump(
      tester,
      const OccupancyBeginPage(rumahId: 'r-1', rumahLabel: 'A-01'),
      (options) => jsonBody(200, {
        'data': [
          {'id': 'k-9', 'kepala': 'Siti Uji', 'jumlah_anggota': 2, 'versi': 1},
        ],
        'meta': {'total': 1},
      }),
    );
    expect(find.text('Siti Uji'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets('formulir hunian skala 1.3 tidak overflow', (tester) async {
    useCompactScreen(tester, width: 360, textScale: 1.3);
    await _pump(tester, const OccupancyEndPage(occupancyId: 'p-1'), (_) => jsonBody(200, {'data': {}}));
    expect(find.text('Akhiri hunian'), findsWidgets);
    expect(tester.takeException(), isNull);
  });
}

Future<void> _open(WidgetTester tester, Widget page, FakeHandler handler) async {
  await _pump(
    tester,
    Scaffold(
      body: Builder(
        builder: (context) => TextButton(
          onPressed: () => Navigator.push<void>(context, MaterialPageRoute(builder: (_) => page)),
          child: const Text('Buka'),
        ),
      ),
    ),
    handler,
  );
  await tester.tap(find.text('Buka'));
  await tester.pumpAndSettle();
}

Future<void> _pump(WidgetTester tester, Widget home, FakeHandler handler) async {
  await tester.pumpWidget(
    ProviderScope(
      overrides: [
        appConfigProvider.overrideWithValue(AppConfig.parse(flavor: 'staging', apiBaseUrl: 'https://api.example.test')),
        tokenStorageProvider.overrideWithValue(MemoryTokenStorage()),
        httpAdapterProvider.overrideWithValue(FakeAdapter(handler)),
      ],
      child: MaterialApp(theme: buildTheme(), home: home),
    ),
  );
  await tester.pumpAndSettle();
}

Object? _body(RequestOptions options) {
  final data = options.data;
  if (data is Map) return Map<String, dynamic>.from(data);
  if (data is String && data.isNotEmpty) return jsonDecode(data);
  return data;
}
