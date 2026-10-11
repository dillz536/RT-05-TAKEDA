import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:rt05_takeda/core/api.dart';
import 'package:rt05_takeda/core/format.dart';
import 'package:rt05_takeda/core/models.dart';
import 'package:rt05_takeda/core/widgets.dart';
import 'package:rt05_takeda/features/pengurus/pengurus_pages.dart';

void main() {
  group('format', () {
    test('rupiah tanpa desimal', () => expect(rupiah(75000), 'Rp75.000'));
    test('periode YYYY-MM ke nama bulan', () => expect(periode('2026-10'), 'Oktober 2026'));
    test('waktu UTC ditampilkan WIB (UTC+7)', () {
      expect(tanggal(DateTime.utc(2026, 10, 9, 18, 30), jam: true), '10 Oktober 2026 • 01.30 WIB');
    });
    test('label status bahasa Indonesia', () {
      expect(statusLabel('belum_bayar'), 'Belum dibayar');
      expect(statusLabel('diproses'), 'Diproses');
    });
  });

  group('model', () {
    test('Laporan anonim tidak memiliki nama pelapor', () {
      final l = Laporan({
        'id': 'x',
        'judul': 'j',
        'deskripsi': 'd',
        'status': 'diajukan',
        'versi': 1,
        'sembunyikan_identitas': true,
        'pelapor': null,
      }, aspirasi: false);
      expect(l.anonim, isTrue);
      expect(l.namaPelapor, isNull);
    });
    test('Tagihan lunas dari status server', () {
      expect(Tagihan({'id': 'x', 'periode': '2026-10', 'nominal': 75000, 'status': 'lunas', 'status_label': 'Lunas'}).lunas, isTrue);
    });
  });

  group('api error', () {
    test('error envelope dipetakan ke ApiException', () {
      final e = ApiException.from(
        DioException(
          requestOptions: RequestOptions(path: '/x'),
          response: Response(
            requestOptions: RequestOptions(path: '/x'),
            statusCode: 409,
            data: {
              'error': {'code': 'CHARGE_ALREADY_PAID', 'message': 'Sebagian periode sudah lunas.', 'request_id': 'r'},
            },
          ),
        ),
      );
      expect(e.status, 409);
      expect(e.code, 'CHARGE_ALREADY_PAID');
      expect(e.isConflict, isTrue);
    });
    test('timeout dianggap hasil belum diketahui', () {
      final e = ApiException.from(
        DioException(
          requestOptions: RequestOptions(path: '/x'),
          type: DioExceptionType.receiveTimeout,
        ),
      );
      expect(e.isUnknownOutcome, isTrue);
    });
    test('field validasi ditampilkan lebih dulu', () {
      final e = ApiException(
        422,
        'VALIDATION_FAILED',
        'Periksa isian.',
        fields: {
          'email': ['Email wajib diisi.'],
        },
      );
      expect(e.display, 'Email wajib diisi.');
    });
  });

  test('Idempotency-Key berformat UUID v4 dan unik', () {
    final a = newKey(), b = newKey();
    expect(RegExp(r'^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$').hasMatch(a), isTrue);
    expect(a, isNot(b));
  });

  testWidgets('StatusChip selalu menampilkan teks, bukan hanya warna', (tester) async {
    await tester.pumpWidget(
      const MaterialApp(
        home: Scaffold(body: Row(children: [StatusChip('belum_bayar'), StatusChip('lunas')])),
      ),
    );
    expect(find.text('Belum dibayar'), findsOneWidget);
    expect(find.text('Lunas'), findsOneWidget);
  });

  testWidgets('BusyButton terkunci selama proses (anti ketuk ganda)', (tester) async {
    var calls = 0;
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: BusyButton(
            label: 'Simpan',
            onPressed: () async {
              calls++;
              await Future<void>.delayed(const Duration(milliseconds: 200));
            },
          ),
        ),
      ),
    );
    await tester.tap(find.text('Simpan'));
    await tester.pump();
    await tester.tap(find.byType(FilledButton), warnIfMissed: false);
    await tester.pump(const Duration(milliseconds: 300));
    expect(calls, 1);
  });

  testWidgets('AsyncView menampilkan pesan error API dan tombol coba lagi', (tester) async {
    await tester.pumpWidget(
      ProviderScope(
        child: MaterialApp(
          home: Scaffold(
            body: AsyncView<int>(
              value: AsyncValue.error(ApiException(503, 'SERVICE_UNAVAILABLE', 'Layanan sementara tidak tersedia.'), StackTrace.empty),
              onRetry: () {},
              builder: (_) => const Text('data'),
            ),
          ),
        ),
      ),
    );
    expect(find.text('Layanan sementara tidak tersedia.'), findsOneWidget);
    expect(find.text('Coba lagi'), findsOneWidget);
  });
}
