import 'dart:convert';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:rt05_takeda/app/config.dart';
import 'package:rt05_takeda/app/theme.dart';
import 'package:rt05_takeda/core/health.dart';
import 'package:rt05_takeda/core/session.dart';
import 'package:rt05_takeda/features/household/admin_edits.dart';
import 'package:rt05_takeda/features/household/admin_links.dart';

import 'support/app_harness.dart';
import 'support/fakes.dart';
import 'support/readable_text.dart';

void main() {
  testWidgets('keluarga baru mengirim kepala dan anggota tanpa peran', (tester) async {
    tester.view.physicalSize = const Size(1200, 2800);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    Object? body;
    await _open(tester, const FamilyCreatePage(), (options) {
      if (options.method == 'POST') {
        body = _body(options);
        return jsonBody(201, {
          'data': {'id': 'k-3', 'versi': 1},
        });
      }
      return jsonBody(200, {'data': {}});
    });
    await tester.enterText(find.widgetWithText(TextField, 'Nama kepala'), 'Siti Baru');
    await tester.tap(find.text('Tambah satu anggota'));
    await tester.pumpAndSettle();
    await tester.enterText(find.widgetWithText(TextField, 'Nama anggota'), 'Ani Baru');
    await tester.enterText(find.widgetWithText(TextField, 'Hubungan anggota'), 'Anak');
    await _reveal(tester, find.text('Simpan keluarga'));
    await tester.tap(find.text('Simpan keluarga'));
    await tester.pumpAndSettle();
    expect(body, {
      'nama_kepala': 'Siti Baru',
      'anggota': [
        {'nama': 'Ani Baru', 'hubungan_keluarga': 'Anak'},
      ],
    });
    expect((body as Map).containsKey('peran'), isFalse);
    expect((body as Map).containsKey('role'), isFalse);
    expect((body as Map).containsKey('rumah_id'), isFalse);
    expect(find.text('Buka'), findsOneWidget);
  });

  testWidgets('pengurus menambah anggota dengan versi keluarga', (tester) async {
    Object? body;
    String? path;
    await _open(tester, const AdminAddMemberPage(familyId: 'k-1', versi: 5), (options) {
      if (options.method == 'POST') {
        path = options.uri.path;
        body = _body(options);
        return jsonBody(201, {
          'data': {'id': 'w-9'},
        });
      }
      return jsonBody(200, {'data': {}});
    });
    await tester.enterText(find.widgetWithText(TextField, 'Nama lengkap'), 'Budi Baru');
    await tester.enterText(find.widgetWithText(TextField, 'Hubungan'), 'Ayah');
    await tester.tap(find.widgetWithText(FilledButton, 'Tambah anggota'));
    await tester.pumpAndSettle();
    expect(path, '/api/v1/admin/families/k-1/members');
    expect(body, {'nama': 'Budi Baru', 'hubungan_keluarga': 'Ayah', 'versi': 5});
    expect((body as Map).containsKey('peran'), isFalse);
    expect(find.text('Buka'), findsOneWidget);
  });

  testWidgets('pindah akun utama mengirim akun, versi, dan alasan', (tester) async {
    Object? body;
    await _open(tester, const TransferPrimaryPage(familyId: 'k-1', versi: 6), (options) {
      if (options.uri.path == '/api/v1/admin/families/k-1' && options.method == 'GET') {
        return jsonBody(200, {
          'data': {
            'id': 'k-1',
            'versi': 6,
            'anggota': [
              {'nama': 'Kepala Uji', 'is_kepala': true},
            ],
            'akun': [
              {
                'id': 't-1',
                'is_utama': true,
                'selesai_at': null,
                'akun': {'id': 'a-1', 'nama': 'Akun Utama', 'status': 'aktif'},
              },
              {
                'id': 't-2',
                'is_utama': false,
                'selesai_at': null,
                'akun': {'id': 'a-2', 'nama': 'Akun Tambahan', 'status': 'aktif'},
              },
            ],
          },
        });
      }
      if (options.method == 'POST') {
        body = _body(options);
        return jsonBody(200, {
          'data': {'id': 'k-1', 'versi': 7},
        });
      }
      return jsonBody(200, {'data': {}});
    });
    await tester.tap(find.text('Akun Tambahan'));
    await tester.pumpAndSettle();
    await tester.enterText(find.widgetWithText(TextField, 'Alasan'), 'Akun utama pindah');
    await tester.tap(find.widgetWithText(FilledButton, 'Pindah akun utama'));
    await tester.pumpAndSettle();
    await tester.tap(find.widgetWithText(FilledButton, 'Pindah akun utama').last);
    await tester.pumpAndSettle();
    expect(body, {'akun_id': 'a-2', 'alasan': 'Akun utama pindah', 'versi': 6});
    expect((body as Map).containsKey('peran'), isFalse);
    expect((body as Map).containsKey('role'), isFalse);
    expect(find.text('Buka'), findsOneWidget);
  });

  testWidgets('pindah anggota mengirim versi keluarga asal', (tester) async {
    Object? body;
    String? path;
    await _open(tester, const MoveResidentPage(residentId: 'w-2', familyId: 'k-1', versi: 8, nama: 'Ani Uji'), (options) {
      if (options.uri.path == '/api/v1/admin/families') {
        return jsonBody(200, {
          'data': [
            {'id': 'k-1', 'kepala': 'Keluarga Asal', 'jumlah_anggota': 2, 'versi': 8},
            {'id': 'k-9', 'kepala': 'Keluarga Tujuan', 'jumlah_anggota': 1, 'versi': 3},
          ],
          'meta': {'page': 1, 'per_page': 100, 'total': 2, 'last_page': 1},
        });
      }
      if (options.method == 'POST') {
        path = options.uri.path;
        body = _body(options);
        return jsonBody(200, {
          'data': {'id': 'w-2'},
        });
      }
      return jsonBody(200, {'data': {}});
    });
    expect(find.text('Keluarga Asal'), findsNothing);
    await tester.tap(find.text('Keluarga Tujuan'));
    await tester.pumpAndSettle();
    await tester.enterText(find.widgetWithText(TextField, 'Hubungan di keluarga tujuan'), 'Anak');
    await tester.enterText(find.widgetWithText(TextField, 'Alasan'), 'Ikut keluarga');
    await tester.ensureVisible(find.widgetWithText(FilledButton, 'Pindah ke keluarga lain'));
    await tester.tap(find.widgetWithText(FilledButton, 'Pindah ke keluarga lain'));
    await tester.pumpAndSettle();
    await tester.tap(find.widgetWithText(FilledButton, 'Pindah ke keluarga lain').last);
    await tester.pumpAndSettle();
    expect(path, '/api/v1/admin/residents/w-2/move');
    expect(body, {'keluarga_id': 'k-9', 'hubungan_keluarga': 'Anak', 'alasan': 'Ikut keluarga', 'versi': 8});
    expect((body as Map).containsKey('peran'), isFalse);
    expect(find.text('Buka'), findsOneWidget);
  });

  testWidgets('alamat tidak mengirim pemilik dan konflik 409 mempertahankan isian', (tester) async {
    useCompactScreen(tester, width: 320, textScale: 2);
    var patches = 0;
    Object? body;
    await _pump(tester, HouseAddressPage(house: {'id': 'r-1', 'kode_rumah': 'A-01', 'blok': 'A', 'jalan': 'Jalan Lama', 'nomor': '1', 'versi': 3}), (options) {
      if (options.method == 'PATCH') {
        patches += 1;
        body = _body(options);
        if (patches == 1) return errorBody(409, 'VERSION_CONFLICT', 'Data rumah sudah berubah. Muat ulang lalu coba lagi.');
        return jsonBody(200, {
          'data': {'id': 'r-1', 'versi': 4},
        });
      }
      if (options.method == 'GET') {
        return jsonBody(200, {
          'data': {'id': 'r-1', 'versi': 4, 'alamat': 'Jalan Baru', 'blok': 'B', 'jalan': 'Jalan Baru', 'nomor': '9', 'kode_rumah': 'A-01'},
        });
      }
      return jsonBody(200, {'data': {}});
    });
    await tester.enterText(find.widgetWithText(TextField, 'Jalan'), 'Jalan Ditulis Duluan');
    await _reveal(tester, find.widgetWithText(TextField, 'Alasan'));
    await tester.enterText(find.widgetWithText(TextField, 'Alasan'), 'Koreksi alamat');
    await _reveal(tester, find.text('Simpan alamat'));
    await tester.tap(find.text('Simpan alamat'));
    await tester.pumpAndSettle();
    expect(patches, 1);
    final first = Map<String, dynamic>.from(body as Map);
    expect(first['jalan'], 'Jalan Ditulis Duluan');
    expect(first['versi'], 3);
    expect(first.containsKey('pemilik'), isFalse);
    expect(first.containsKey('tagihan_aktif'), isFalse);
    expect(first.containsKey('peran'), isFalse);
    expect(find.byType(HouseAddressPage), findsOneWidget);
    expect(tester.widget<TextField>(find.widgetWithText(TextField, 'Jalan')).controller!.text, 'Jalan Ditulis Duluan');
    expect(tester.widget<TextField>(find.widgetWithText(TextField, 'Alasan')).controller!.text, 'Koreksi alamat');
    const kept = 'Data rumah sudah berubah. Muat ulang lalu coba lagi. Teks yang Anda ketik tetap ada. Periksa data terbaru, lalu kirim lagi.';
    await scrollUntilReadable(tester, find.text(kept));
    expectTextFullyVisible(tester, kept);
    expect(find.text('Data terbaru: Jalan Baru.'), findsOneWidget);
    expect(tester.takeException(), isNull);
    await _reveal(tester, find.text('Simpan alamat'));
    await tester.tap(find.text('Simpan alamat'));
    await tester.pumpAndSettle();
    expect(patches, 2);
    final second = Map<String, dynamic>.from(body as Map);
    expect(second['versi'], 4);
    expect(second['jalan'], 'Jalan Ditulis Duluan');
    expect(tester.takeException(), isNull);
  });

  testWidgets('pemilik tidak mengirim alamat dan lepas mengirim null', (tester) async {
    final bodies = <Object?>[];
    await _open(
      tester,
      HouseOwnerPage(
        house: {
          'id': 'r-1',
          'versi': 4,
          'jalan': 'Jalan Uji',
          'pemilik': {'nama': 'Pemilik Lama', 'whatsapp': null, 'catatan': null},
        },
      ),
      (options) {
        if (options.method == 'PATCH') {
          bodies.add(_body(options));
          return jsonBody(200, {
            'data': {'id': 'r-1', 'versi': 5},
          });
        }
        return jsonBody(200, {'data': {}});
      },
    );
    await tester.enterText(find.widgetWithText(TextField, 'Nama pemilik'), 'Budi Pemilik');
    await tester.enterText(find.widgetWithText(TextField, 'Alasan'), 'Catat pemilik');
    await tester.tap(find.widgetWithText(FilledButton, 'Simpan pemilik'));
    await tester.pumpAndSettle();
    final saved = bodies.single as Map;
    expect(saved['pemilik'], {'nama': 'Budi Pemilik', 'whatsapp': null, 'catatan': null});
    expect(saved.containsKey('jalan'), isFalse);
    expect(saved.containsKey('blok'), isFalse);
    expect(saved.containsKey('nomor'), isFalse);
    expect(saved.containsKey('tagihan_aktif'), isFalse);
    expect(saved.containsKey('peran'), isFalse);
    expect(find.text('Buka'), findsOneWidget);

    await tester.tap(find.text('Buka'));
    await tester.pumpAndSettle();
    await tester.enterText(find.widgetWithText(TextField, 'Alasan'), 'Tidak lagi tercatat');
    await tester.ensureVisible(find.text('Lepas pemilik'));
    await tester.tap(find.text('Lepas pemilik'));
    await tester.pumpAndSettle();
    await tester.tap(find.widgetWithText(FilledButton, 'Lepas pemilik'));
    await tester.pumpAndSettle();
    final cleared = bodies.last as Map;
    expect(cleared['pemilik'], isNull);
    expect(cleared['alasan'], 'Tidak lagi tercatat');
    expect(cleared.containsKey('jalan'), isFalse);
  });

  testWidgets('daftar keluarga memuat halaman berikutnya dari meta', (tester) async {
    final pages = <String>[];
    await _pump(tester, const FamiliesAdminPage(), (options) {
      if (options.uri.path == '/api/v1/admin/families') {
        final page = '${options.uri.queryParameters['page']}';
        pages.add(page);
        if (page == '2') {
          return jsonBody(200, {
            'data': [
              {'id': 'k-2', 'kepala': 'Halaman Dua', 'jumlah_anggota': 1, 'versi': 1},
            ],
            'meta': {'page': 2, 'per_page': 100, 'total': 2, 'last_page': 2},
          });
        }
        return jsonBody(200, {
          'data': [
            {'id': 'k-1', 'kepala': 'Halaman Satu', 'jumlah_anggota': 1, 'versi': 1},
          ],
          'meta': {'page': 1, 'per_page': 100, 'total': 2, 'last_page': 2},
        });
      }
      return jsonBody(200, {'data': {}});
    });
    expect(find.text('Halaman Satu'), findsOneWidget);
    expect(find.text('Keluarga baru'), findsOneWidget);
    expect(find.textContaining('Menampilkan 1 dari 2.'), findsOneWidget);
    await tester.tap(find.text('Muat halaman berikutnya'));
    await tester.pumpAndSettle();
    expect(pages, contains('2'));
    expect(find.text('Halaman Dua'), findsOneWidget);
    expect(find.textContaining('Menampilkan 2 dari 2.'), findsOneWidget);
    expect(find.text('Muat halaman berikutnya'), findsNothing);
  });

  testWidgets('riwayat akun menampilkan tautan tanpa kontrol peran', (tester) async {
    await _open(tester, const AccountDetailPage(id: 'a-2'), (options) {
      if (options.uri.path == '/api/v1/admin/accounts/a-2') {
        return jsonBody(200, {
          'data': {
            'id': 'a-2',
            'nama': 'Warga Lain',
            'email': 'lain@example.test',
            'status': 'aktif',
            'jenis': 'tambahan',
            'peran': 'warga',
            'tautan': [
              {
                'id': 't-1',
                'is_utama': false,
                'mulai_at': '2026-01-02T00:00:00+07:00',
                'selesai_at': null,
                'warga': {'id': 'w-2', 'nama': 'Anak Uji'},
              },
            ],
            'permohonan': {'status': 'disetujui', 'nama_pemohon': 'Warga Lain', 'hubungan_keluarga': 'Anak', 'alamat_diajukan': 'Blok A'},
          },
        });
      }
      return jsonBody(200, {'data': {}});
    });
    expect(find.text('Anak Uji'), findsOneWidget);
    expect(find.text('Riwayat tautan'), findsWidgets);
    expect(find.text('Peran tidak diubah dari aplikasi. Setiap pengurus memiliki hak yang sama.'), findsOneWidget);
    expect(find.text('Ubah peran'), findsNothing);
    expect(find.byType(DropdownButtonFormField<String>), findsNothing);
    expect(find.textContaining('password'), findsNothing);
  });
}

Future<void> _reveal(WidgetTester tester, Finder target) async {
  final list = find.byType(ListView);
  for (var i = 0; i < 12; i++) {
    if (target.hitTestable().evaluate().isNotEmpty) return;
    await tester.drag(list, const Offset(0, -320));
    await tester.pump();
  }
  expect(target.hitTestable(), findsOneWidget);
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
