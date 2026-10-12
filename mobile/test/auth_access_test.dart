import 'dart:async';
import 'dart:convert';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:rt05_takeda/core/api.dart';
import 'package:rt05_takeda/core/models.dart';
import 'package:rt05_takeda/core/session.dart';
import 'package:rt05_takeda/features/auth/account_access.dart';
import 'package:rt05_takeda/features/auth/auth_pages.dart';
import 'package:rt05_takeda/features/warga/warga_pages.dart';

import 'support/app_harness.dart';
import 'support/fakes.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  group('validator', () {
    test('token enam digit tetap string dan sandi menolak yang lemah', () {
      expect(validateEmailAddress('warga.uji@example.test'), isNull);
      expect(validateRegistrationToken('012345'), isNull);
      expect(validateRegistrationToken('01234'), 'Token harus 6 digit.');
      expect(validateRegistrationToken('01234a'), isNotNull);
      expect(validateNewPassword('rahasia'), isNotNull);
      expect(validateNewPassword('Rahasia1'), isNull);
      expect(validateNewPassword('${'é' * 6}1'), isNotNull);
      expect(validateNewPassword('${'é' * 7}1'), isNull);
      expect(validateNewPassword('a1${'😀' * 5}'), isNotNull);
      expect(validateNewPassword('a1${'😀' * 6}'), isNull);
      expect(validateNewPassword('A1${'b' * 70}'), isNull);
      expect(validateNewPassword('A1${'b' * 71}'), passwordTooLongMessage);
      expect(validateNewPassword('A1${'é' * 40}'), passwordTooLongMessage);
      expect(validateNewPassword('A1${'你' * 24}'), passwordTooLongMessage);
      expect(validateNewPassword('${'😀' * 17}a1'), isNull);
      expect(validateNewPassword('${'😀' * 18}a1'), passwordTooLongMessage);
      expect(validateNewPassword('Rahasia1\u0000'), passwordNulMessage);
      expect(forgotQueuedMessage.contains('dikirim'), isFalse);
      expect(forgotSuccessText({'message': 'Jika email terdaftar, tautan reset kata sandi telah dikirim.'}), forgotQueuedMessage);
      expect(
        forgotSuccessText({'message': 'Jika email terdaftar, permintaan reset telah diterima.'}),
        'Jika email terdaftar, permintaan reset telah diterima.',
      );
      expect(validatePasswordConfirmation('Rahasia2', 'Rahasia1'), isNotNull);
      expect(validatePasswordConfirmation('Rahasia1', 'Rahasia1'), isNull);
      expect(validateWhatsapp('0812'), isNotNull);
      expect(validateWhatsapp('08123456789'), isNull);
      expect(keptSessionMessage(ApiException(0, 'NETWORK_ERROR', 'Tidak dapat terhubung ke server. Periksa koneksi internet.')), contains('tetap tersimpan'));
      expect(keptSessionMessage(ApiException(422, 'VALIDATION_FAILED', 'Kata sandi salah.')), 'Kata sandi salah.');
    });
  });

  testWidgets('login keyboard tidak mengirim dua kali', (tester) async {
    final release = Completer<void>();
    var calls = 0;
    Object? body;
    final base = apiHandler(me: akunJson);
    await pumpApp(
      tester,
      storage: MemoryTokenStorage(),
      handler: (options) async {
        if (options.uri.path == '/api/v1/auth/mobile-login') {
          calls++;
          body = requestBody(options);
          await release.future;
          return jsonBody(200, {
            'data': {'token': 'tok-login', 'akun': akunJson()},
          });
        }
        return base(options);
      },
    );
    await tester.enterText(find.byKey(const ValueKey('field-login-email')), 'warga.uji@example.test');
    await tester.enterText(find.byKey(const ValueKey('field-login-password')), 'Rahasia1');
    final field = tester.widget<TextField>(find.descendant(of: find.byKey(const ValueKey('field-login-password')), matching: find.byType(TextField)));
    field.onSubmitted!.call('Rahasia1');
    field.onSubmitted!.call('Rahasia1');
    for (var i = 0; i < 6 && calls == 0; i++) {
      await tester.pump(const Duration(milliseconds: 50));
    }
    final masuk = tester.widget<FilledButton>(find.widgetWithText(FilledButton, 'Masuk'));
    expect(calls, 1, reason: 'tombol=${masuk.onPressed != null} ${visibleTexts(tester)}');
    expect(body, {'email': 'warga.uji@example.test', 'password': 'Rahasia1', 'device_name': 'Android RT05'});
    release.complete();
    await tester.pumpAndSettle();
    expect(tester.takeException(), isNull);
  });

  testWidgets('daftar utama: token awalan nol, alamat wajib, anggota, dan kembali tertahan saat menunggu', (tester) async {
    final storage = MemoryTokenStorage();
    final release = Completer<void>();
    var calls = 0;
    Object? body;
    final base = apiHandler(me: akunJson);
    await pumpApp(
      tester,
      storage: storage,
      handler: (options) async {
        if (options.uri.path == '/api/v1/auth/register') {
          calls++;
          body = requestBody(options);
          if (calls == 1) return memberError();
          await release.future;
          return jsonBody(201, {
            'data': {'token': 'tok-daftar', 'akun': akunJson()},
          });
        }
        return base(options);
      },
    );
    await openRegister(tester);

    await scrollAuth(tester, find.text('Daftar'));
    await tester.tap(find.text('Daftar'));
    await tester.pumpAndSettle();
    expect(calls, 0);
    expect(find.text('Wajib diisi'), findsWidgets);
    expect(find.text('Token harus 6 digit.'), findsNothing);

    await enter(tester, 'field-token', '012');
    await scrollAuth(tester, find.text('Daftar'));
    await tester.tap(find.text('Daftar'));
    await tester.pumpAndSettle();
    expect(calls, 0);
    expect(find.text('Token harus 6 digit.'), findsOneWidget);

    await enter(tester, 'field-token', '012345');
    await enter(tester, 'field-email', 'kepala.uji@example.test');
    await enter(tester, 'field-password', 'rahasia');
    await enter(tester, 'field-password2', 'lain1234');
    await enter(tester, 'field-nama', 'Kepala Uji');
    await enter(tester, 'field-jalan', 'Jalan Uji');
    await enter(tester, 'field-nomor', '1');
    await scrollAuth(tester, find.text('Daftar'));
    await tester.tap(find.text('Daftar'));
    await tester.pumpAndSettle();
    expect(calls, 0);
    expect(find.text('Minimal 8 karakter, berisi huruf dan angka.'), findsOneWidget);
    expect(find.text('Pengulangan kata sandi tidak sama.'), findsOneWidget);

    await enter(tester, 'field-password', 'Rahasia1');
    await enter(tester, 'field-password2', 'Rahasia1');
    await scrollAuth(tester, find.text('Tambah'));
    await tester.tap(find.text('Tambah'));
    await tester.pumpAndSettle();
    await enter(tester, 'anggota-0-nama', 'Anggota Uji');
    await scrollAuth(tester, find.text('Daftar'));
    await tester.tap(find.text('Daftar'));
    await tester.pumpAndSettle();
    expect(calls, 0);
    expect(find.text('Wajib diisi'), findsWidgets);

    await enter(tester, 'anggota-0-hubungan', 'Anak');
    await scrollAuth(tester, find.text('Daftar'));
    await tester.tap(find.text('Daftar'));
    await tester.pumpAndSettle();
    expect(calls, 1);
    final sent = Map<String, dynamic>.from(body! as Map);
    expect(sent['registration_token'], '012345');
    expect(sent['registration_token'], isA<String>());
    expect(jsonEncode(sent), contains('"registration_token":"012345"'));
    expect(sent['jalan'], 'Jalan Uji');
    expect(sent['nomor'], '1');
    expect(sent['nama_kepala'], 'Kepala Uji');
    expect(sent['password_confirmation'], 'Rahasia1');
    expect(sent['anggota'], [
      {'nama': 'Anggota Uji', 'hubungan_keluarga': 'Anak'},
    ]);
    expect(find.text('Nama anggota tidak valid.'), findsOneWidget);

    await scrollAuth(tester, find.text('Daftar'));
    await tester.tap(find.text('Daftar'));
    for (var i = 0; i < 8 && calls < 2; i++) {
      await tester.pump(const Duration(milliseconds: 50));
    }
    expect(calls, 2);
    await tester.tap(find.byType(FilledButton).last);
    await tester.pump();
    expect(calls, 2);
    expect(registerCanPop(tester), isFalse);
    expect(find.byType(BackButton), findsNothing);
    expect(tester.widget<IconButton>(find.byKey(const ValueKey('auth-back'))).onPressed, isNull);
    await vetoRegisterBack(tester);
    await tester.pump();
    expect(find.byType(RegisterPage), findsOneWidget);
    expect(storage.token, isNull);
    release.complete();
    await tester.pumpAndSettle();
    expect(tester.takeException(), isNull);
    expect(storage.token, 'tok-daftar');
    expect(find.byType(RegisterPage), findsNothing);
    expect(find.text('Iuran bulan ini'), findsOneWidget);
  });

  testWidgets('daftar tambahan mengirim hubungan dan menunggu di halaman terbatas', (tester) async {
    Object? body;
    final base = apiHandler(
      me: () => akunJson(status: 'menunggu', jenis: 'tambahan'),
      fixtures: {
        '/api/v1/auth/additional-account-status': {
          'data': {
            'status_akun': 'menunggu',
            'permohonan': {'status': 'menunggu'},
          },
        },
      },
    );
    await pumpApp(
      tester,
      storage: MemoryTokenStorage(),
      handler: (options) {
        if (options.uri.path == '/api/v1/auth/additional-register') {
          body = requestBody(options);
          return jsonBody(202, {
            'data': {'token': 'tok-tambahan', 'akun': akunJson(status: 'menunggu', jenis: 'tambahan')},
          });
        }
        return base(options);
      },
    );
    await scrollAuth(tester, find.text('Daftar akun tambahan keluarga'));
    await tester.tap(find.text('Daftar akun tambahan keluarga'));
    await tester.pumpAndSettle();
    await enter(tester, 'field-token', '012345');
    await enter(tester, 'field-email', 'anggota.uji@example.test');
    await enter(tester, 'field-password', 'Rahasia1');
    await enter(tester, 'field-password2', 'Rahasia1');
    await enter(tester, 'field-nama', 'Anggota Uji');
    await enter(tester, 'field-hubungan', 'Anak');
    await enter(tester, 'field-jalan', 'Jalan Uji');
    await enter(tester, 'field-nomor', '2');
    await scrollAuth(tester, find.text('Kirim permohonan'));
    await tester.tap(find.text('Kirim permohonan'));
    await tester.pumpAndSettle();
    final sent = Map<String, dynamic>.from(body! as Map);
    expect(sent['registration_token'], '012345');
    expect(sent['nama'], 'Anggota Uji');
    expect(sent['hubungan_keluarga'], 'Anak');
    expect(sent.containsKey('anggota'), isFalse);
    expect(find.byType(PendingPage), findsOneWidget);
    expect(find.text('Iuran bulan ini'), findsNothing);
  });

  for (final additional in [false, true]) {
    final kind = additional ? 'tambahan' : 'utama';
    final button = additional ? 'Kirim permohonan' : 'Daftar';
    final path = additional ? '/api/v1/auth/additional-register' : '/api/v1/auth/register';

    testWidgets('kembali tidak menggagalkan daftar $kind yang sukses saat respons tertunda', (tester) async {
      final storage = MemoryTokenStorage();
      final release = Completer<void>();
      var calls = 0;
      final token = additional ? 'tok-tambahan' : 'tok-daftar';
      Map<String, dynamic> akun() => additional ? akunJson(status: 'menunggu', jenis: 'tambahan') : akunJson();
      final base = apiHandler(
        me: akun,
        fixtures: additional
            ? {
                '/api/v1/auth/additional-account-status': {
                  'data': {
                    'permohonan': {'status': 'menunggu'},
                  },
                },
              }
            : null,
      );
      await pumpApp(
        tester,
        storage: storage,
        handler: (options) async {
          if (options.uri.path == path) {
            calls++;
            await release.future;
            return jsonBody(additional ? 202 : 201, {
              'data': {'token': token, 'akun': akun()},
            });
          }
          return base(options);
        },
      );
      await openRegisterKind(tester, additional: additional);
      await fillRegister(tester, additional: additional);
      await scrollAuth(tester, find.text(button));
      await tester.tap(find.text(button));
      for (var i = 0; i < 8 && calls == 0; i++) {
        await tester.pump(const Duration(milliseconds: 50));
      }
      expect(calls, 1);
      expect(registerCanPop(tester), isFalse);
      expect(find.byType(BackButton), findsNothing);
      expect(tester.widget<IconButton>(find.byKey(const ValueKey('auth-back'))).onPressed, isNull);
      await vetoRegisterBack(tester);
      await tester.pump();
      expect(storage.token, isNull);
      expect(find.byType(RegisterPage), findsOneWidget);

      release.complete();
      await tester.pumpAndSettle();
      expect(storage.token, token);
      expect(find.byType(RegisterPage), findsNothing);
      if (additional) {
        expect(find.byType(PendingPage), findsOneWidget);
        expect(find.text('Menunggu persetujuan'), findsOneWidget);
      } else {
        expect(find.text('Iuran bulan ini'), findsOneWidget);
      }
    });

    testWidgets('kembali daftar $kind normal setelah respons selesai', (tester) async {
      final storage = MemoryTokenStorage();
      final release = Completer<void>();
      var calls = 0;
      final base = apiHandler(me: akunJson);
      await pumpApp(
        tester,
        storage: storage,
        handler: (options) async {
          if (options.uri.path == path) {
            calls++;
            await release.future;
            return jsonBody(422, {
              'error': {'code': 'VALIDATION_FAILED', 'message': 'Data tidak valid.', 'request_id': '00000000-0000-4000-8000-000000000099'},
            });
          }
          return base(options);
        },
      );
      await openRegisterKind(tester, additional: additional);
      await fillRegister(tester, additional: additional);
      await scrollAuth(tester, find.text(button));
      await tester.tap(find.text(button));
      for (var i = 0; i < 8 && calls == 0; i++) {
        await tester.pump(const Duration(milliseconds: 50));
      }
      expect(calls, 1);
      expect(registerCanPop(tester), isFalse);
      await vetoRegisterBack(tester);
      expect(find.byType(RegisterPage), findsOneWidget);

      release.complete();
      await tester.pumpAndSettle();
      expect(storage.token, isNull);
      expect(find.byType(RegisterPage), findsOneWidget);
      expect(find.text('Data tidak valid.'), findsOneWidget);
      expect(registerCanPop(tester), isTrue);
      expect(find.byType(BackButton), findsOneWidget);
      await popPage(tester);
      expect(find.byType(LoginPage), findsOneWidget);
      expect(find.byType(RegisterPage), findsNothing);
    });
  }

  testWidgets('lupa sandi generik lalu reset dengan konfirmasi dan kode yang bisa disalin', (tester) async {
    final calls = <String>[];
    Object? resetBody;
    final base = apiHandler(me: akunJson);
    await pumpApp(
      tester,
      storage: MemoryTokenStorage(),
      handler: (options) {
        if (options.uri.path == '/api/v1/auth/forgot-password') {
          calls.add('forgot');
          return jsonBody(202, {
            'data': {'message': 'Jika email terdaftar, tautan reset kata sandi telah dikirim.'},
          });
        }
        if (options.uri.path == '/api/v1/auth/reset-password') {
          calls.add('reset');
          resetBody = requestBody(options);
          return jsonBody(200, {
            'data': {'message': 'Kata sandi berhasil direset. Silakan masuk kembali.'},
          });
        }
        return base(options);
      },
    );
    await tester.tap(find.text('Lupa kata sandi?'));
    await tester.pumpAndSettle();
    await enter(tester, 'field-forgot-email', 'bukan-email');
    await tester.tap(find.text('Kirim kode reset'));
    await tester.pumpAndSettle();
    expect(calls, isEmpty);

    await enter(tester, 'field-forgot-email', 'warga.uji@example.test');
    await tester.tap(find.text('Kirim kode reset'));
    await tester.pumpAndSettle();
    expect(calls, ['forgot']);
    expect(find.textContaining(forgotQueuedMessage), findsOneWidget);
    expect(find.textContaining('telah dikirim'), findsNothing);
    expect(find.textContaining('tidak menyatakan bahwa email tersebut ada'), findsOneWidget);

    await enter(tester, 'field-forgot-token', '012345');
    final copied = <String?>[];
    final messenger = tester.binding.defaultBinaryMessenger;
    messenger.setMockMethodCallHandler(SystemChannels.platform, (call) async {
      if (call.method == 'Clipboard.setData') copied.add((call.arguments as Map)['text'] as String?);
      if (call.method == 'Clipboard.getData') return {'text': copied.isEmpty ? null : copied.last};
      return null;
    });
    addTearDown(() => messenger.setMockMethodCallHandler(SystemChannels.platform, null));
    await scrollAuth(tester, find.widgetWithText(TextButton, 'Salin kode'));
    expect(tester.widget<TextFormField>(find.byKey(const ValueKey('field-forgot-token'))).controller?.text, '012345');
    expect(tester.widget<TextButton>(find.widgetWithText(TextButton, 'Salin kode')).onPressed, isNotNull);
    await tester.tap(find.widgetWithText(TextButton, 'Salin kode'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 400));
    expect(copied, ['012345']);
    expect(find.text('Kode disalin.'), findsOneWidget);
    await dismissSnack(tester);

    await enter(tester, 'field-forgot-password', 'Rahasia1');
    await enter(tester, 'field-forgot-password2', 'Rahasia2');
    await scrollAuth(tester, find.text('Simpan kata sandi baru'));
    await tester.tap(find.text('Simpan kata sandi baru'));
    await tester.pumpAndSettle();
    expect(calls, ['forgot']);
    expect(find.text('Pengulangan kata sandi tidak sama.'), findsOneWidget);

    await enter(tester, 'field-forgot-password2', 'Rahasia1');
    await tester.tap(find.text('Simpan kata sandi baru'));
    await tester.pumpAndSettle();
    expect(calls, ['forgot', 'reset']);
    expect(resetBody, {'email': 'warga.uji@example.test', 'token': '012345', 'password': 'Rahasia1', 'password_confirmation': 'Rahasia1'});
    expect((resetBody! as Map)['token'], isA<String>());
    expect(find.byType(LoginPage), findsOneWidget);
  });

  testWidgets('pending ditolak tetap di layar terbatas; 403 tidak menghapus token', (tester) async {
    final storage = MemoryTokenStorage('t');
    var meCount = 0;
    final base = apiHandler(
      me: () => akunJson(status: 'menunggu', jenis: 'tambahan'),
      fixtures: {
        '/api/v1/auth/additional-account-status': {
          'data': {
            'permohonan': {'status': 'ditolak', 'catatan': 'Alamat tidak dikenali.'},
          },
        },
      },
    );
    await pumpApp(
      tester,
      storage: storage,
      handler: (options) {
        if (options.uri.path == '/api/v1/auth/me') {
          meCount++;
          if (meCount > 1) return errorBody(403, 'FORBIDDEN', 'Akun tidak aktif.');
        }
        return base(options);
      },
    );
    expect(find.byType(PendingPage), findsOneWidget);
    expect(find.text('Permohonan ditolak'), findsOneWidget);
    expect(find.text('Menunggu persetujuan'), findsNothing);
    expect(find.textContaining('sedang ditinjau'), findsNothing);
    expect(find.text('Ditolak'), findsOneWidget);
    expect(find.textContaining('Alasan: Alamat tidak dikenali.'), findsNWidgets(2));
    expect(find.text('Iuran bulan ini'), findsNothing);

    await tester.tap(find.text('Periksa lagi'));
    await tester.pumpAndSettle();
    expect(find.byType(PendingPage), findsOneWidget);
    expect(find.text('Akun tidak aktif.'), findsOneWidget);
    expect(storage.token, 't');
  });

  testWidgets('pending keluar offline tidak berpura-pura server mencabut token', (tester) async {
    final storage = MemoryTokenStorage('t');
    final base = apiHandler(
      me: () => akunJson(status: 'menunggu', jenis: 'tambahan'),
      fixtures: {
        '/api/v1/auth/additional-account-status': {
          'data': {
            'permohonan': {'status': 'menunggu'},
          },
        },
      },
    );
    await pumpApp(tester, storage: storage, handler: (options) => options.uri.path == '/api/v1/auth/logout' ? offline(options) : base(options));
    await tester.tap(find.text('Keluar'));
    await tester.pumpAndSettle();
    expect(find.textContaining('tetap tersimpan'), findsOneWidget);
    expect(storage.token, 't');
    expect(find.byType(PendingPage), findsOneWidget);
  });

  testWidgets('akun utama belum diverifikasi tetap aktif di beranda', (tester) async {
    final fixtures = shellFixtures();
    fixtures['/api/v1/household'] = {
      'data': {
        'rumah': {'alamat': 'Jalan Uji Coba Nomor 1 Blok A'},
        'jenis_hunian': 'pemilik',
        'status': 'belum_verifikasi',
        'anggota': [
          {'id': 'w-1', 'nama': 'Kepala Keluarga Uji', 'hubungan_keluarga': 'Kepala keluarga', 'is_kepala': true, 'punya_akun': true},
        ],
      },
    };
    await pumpApp(
      tester,
      storage: MemoryTokenStorage('t'),
      handler: apiHandler(me: akunJson, fixtures: fixtures),
    );
    expect(find.text('Iuran bulan ini'), findsOneWidget);
    await tester.tap(find.widgetWithText(NavigationDestination, 'Akun'));
    await tester.pumpAndSettle();
    expect(find.text('Akun utama sudah aktif. Keluarga belum diverifikasi pengurus.'), findsOneWidget);
  });

  testWidgets('keluar 204 mencabut token lokal; offline dan 5xx tidak', (tester) async {
    final storage = MemoryTokenStorage('t');
    var mode = 'offline';
    final base = apiHandler(me: akunJson);
    await pumpApp(
      tester,
      storage: storage,
      handler: (options) {
        if (options.uri.path == '/api/v1/auth/logout') {
          return switch (mode) {
            'ok' => emptyNoContent(),
            'down' => errorBody(503, 'SERVICE_UNAVAILABLE', 'Layanan sementara tidak tersedia.'),
            _ => offline(options),
          };
        }
        return base(options);
      },
    );
    await openAccount(tester);
    await tester.tap(find.text('Keluar'));
    await tester.pumpAndSettle();
    expect(find.textContaining('tetap tersimpan'), findsOneWidget);
    expect(storage.token, 't');
    expect(find.byType(LoginPage), findsNothing);
    await dismissSnack(tester);

    mode = 'down';
    await tester.tap(find.text('Keluar'));
    await tester.pumpAndSettle();
    expect(storage.token, 't');
    await dismissSnack(tester);

    mode = 'ok';
    await tester.tap(find.text('Keluar'));
    await tester.pumpAndSettle();
    expect(storage.token, isNull);
    expect(find.byType(LoginPage), findsOneWidget);
  });

  testWidgets('keluar semua perangkat meminta sandi dan tidak menghapus sesi saat offline', (tester) async {
    final storage = MemoryTokenStorage('t');
    Object? body;
    var online = false;
    final base = apiHandler(me: akunJson);
    await pumpApp(
      tester,
      storage: storage,
      handler: (options) {
        if (options.uri.path == '/api/v1/auth/logout-all') {
          body = requestBody(options);
          if (!online) return offline(options);
          return emptyNoContent();
        }
        return base(options);
      },
    );
    await openAccount(tester);
    await tester.tap(find.text('Keluar dari semua perangkat'));
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const ValueKey('field-logout-all-password')), 'Rahasia1');
    await tester.tap(find.text('Keluarkan semua'));
    await tester.pumpAndSettle();
    expect(body, {'password': 'Rahasia1'});
    expect(storage.token, 't');
    expect(find.textContaining('tetap tersimpan'), findsOneWidget);
    await dismissSnack(tester);

    online = true;
    await tester.tap(find.text('Keluar dari semua perangkat'));
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const ValueKey('field-logout-all-password')), 'Rahasia1');
    await tester.tap(find.text('Keluarkan semua'));
    await tester.pumpAndSettle();
    expect(storage.token, isNull);
    expect(find.byType(LoginPage), findsOneWidget);
  });

  for (final (label, path, sameToken) in [
    ('keluar', '/api/v1/auth/logout', false),
    ('keluar semua', '/api/v1/auth/logout-all', false),
    ('keluar token sama', '/api/v1/auth/logout', true),
  ]) {
    testWidgets('$label yang selesai setelah masuk baru tidak menghapus sesi baru', (tester) async {
      final storage = MemoryTokenStorage('t-lama');
      final gate = Completer<void>();
      final base = apiHandler(me: () => akunJson(id: 'a-1'));
      await pumpApp(
        tester,
        storage: storage,
        handler: (options) async {
          if (options.uri.path == path) {
            await gate.future;
            return emptyNoContent();
          }
          return base(options);
        },
      );
      await openAccount(tester);
      final logoutAll = path.endsWith('logout-all');
      await tester.tap(find.text(logoutAll ? 'Keluar dari semua perangkat' : 'Keluar'));
      if (logoutAll) {
        await tester.pumpAndSettle();
        await tester.enterText(find.byKey(const ValueKey('field-logout-all-password')), 'Rahasia1');
        await tester.tap(find.text('Keluarkan semua'));
      }
      await tester.pump();
      for (var i = 0; i < 6; i++) {
        await tester.pump(const Duration(milliseconds: 50));
      }
      final container = ProviderScope.containerOf(tester.element(find.byType(AccountPage)));
      final nextToken = sameToken ? 't-lama' : 't-baru';
      await container.read(sessionProvider.notifier).signIn(nextToken, Akun(akunJson(id: 'a-2')));
      gate.complete();
      await tester.pumpAndSettle();
      expect(storage.token, nextToken);
      expect(container.read(sessionProvider).signedIn, isTrue);
      expect(container.read(sessionProvider).akun!.id, 'a-2');
      expect(container.read(sessionProvider).akun!.nama, contains('Warga Uji'));
      expect(find.byType(LoginPage), findsNothing);
      expect(find.byType(NavigationBar), findsOneWidget);
      expect(find.textContaining('Kepala Keluarga Uji'), findsOneWidget);
    });
  }

  testWidgets('ganti sandi yang selesai setelah masuk baru tidak menimpa akun baru', (tester) async {
    final storage = MemoryTokenStorage('t-lama');
    final gate = Completer<void>();
    final base = apiHandler(me: () => akunJson(id: 'a-1'));
    await pumpApp(
      tester,
      storage: storage,
      handler: (options) async {
        if (options.uri.path == '/api/v1/auth/password' && options.method == 'PATCH') {
          await gate.future;
          return jsonBody(200, {
            'data': {'message': 'Kata sandi diperbarui. Perangkat lain telah dikeluarkan.'},
          });
        }
        return base(options);
      },
    );
    await openAccount(tester);
    await tester.tap(find.text('Ganti kata sandi'));
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const ValueKey('field-current-password')), 'Lama1234');
    await tester.enterText(find.byKey(const ValueKey('field-new-password')), 'Baru1234');
    await tester.enterText(find.byKey(const ValueKey('field-new-password2')), 'Baru1234');
    await tester.tap(find.text('Simpan'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));
    final container = ProviderScope.containerOf(tester.element(find.byType(ChangePasswordPage)));
    await container.read(sessionProvider.notifier).signIn('t-baru', Akun(akunJson(id: 'a-9')));
    gate.complete();
    await tester.pumpAndSettle();
    expect(storage.token, 't-baru');
    expect(container.read(sessionProvider).akun!.id, 'a-9');
    expect(container.read(sessionProvider).signedIn, isTrue);
    expect(find.text('Kata sandi diperbarui. Perangkat lain telah dikeluarkan.'), findsNothing);
    expect(find.byType(LoginPage), findsNothing);
  });

  testWidgets('ganti kata sandi tetap di perangkat ini dan menolak konfirmasi yang salah', (tester) async {
    final storage = MemoryTokenStorage('t');
    Object? body;
    final base = apiHandler(me: akunJson);
    await pumpApp(
      tester,
      storage: storage,
      handler: (options) {
        if (options.uri.path == '/api/v1/auth/password' && options.method == 'PATCH') {
          body = requestBody(options);
          final password = (body! as Map)['current_password'];
          if (password == 'salah') {
            return jsonBody(422, {
              'error': {
                'code': 'VALIDATION_FAILED',
                'message': 'Data tidak valid.',
                'fields': {
                  'current_password': ['Kata sandi saat ini salah.'],
                },
                'request_id': '00000000-0000-4000-8000-000000000002',
              },
            });
          }
          return jsonBody(200, {
            'data': {'message': 'Kata sandi diperbarui. Perangkat lain telah dikeluarkan.'},
          });
        }
        return base(options);
      },
    );
    await openAccount(tester);
    await tester.tap(find.text('Ganti kata sandi'));
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const ValueKey('field-current-password')), 'Lama1234');
    await tester.enterText(find.byKey(const ValueKey('field-new-password')), 'pendek');
    await tester.enterText(find.byKey(const ValueKey('field-new-password2')), 'pendek');
    await tester.tap(find.text('Simpan'));
    await tester.pumpAndSettle();
    expect(body, isNull);
    expect(find.text('Minimal 8 karakter, berisi huruf dan angka.'), findsOneWidget);

    await tester.enterText(find.byKey(const ValueKey('field-new-password')), 'Baru1234');
    await tester.enterText(find.byKey(const ValueKey('field-new-password2')), 'Baru12345');
    await tester.tap(find.text('Simpan'));
    await tester.pumpAndSettle();
    expect(body, isNull);

    await tester.enterText(find.byKey(const ValueKey('field-new-password')), 'Lama1234');
    await tester.enterText(find.byKey(const ValueKey('field-new-password2')), 'Lama1234');
    await tester.tap(find.text('Simpan'));
    await tester.pumpAndSettle();
    expect(body, isNull);
    expect(find.text('Kata sandi baru harus berbeda.'), findsOneWidget);

    final tooLong = 'A1${'é' * 40}';
    await tester.enterText(find.byKey(const ValueKey('field-new-password')), tooLong);
    await tester.enterText(find.byKey(const ValueKey('field-new-password2')), tooLong);
    await tester.tap(find.text('Simpan'));
    await tester.pumpAndSettle();
    expect(body, isNull);
    expect(find.text(passwordTooLongMessage), findsOneWidget);

    await tester.enterText(find.byKey(const ValueKey('field-current-password')), 'salah');
    await tester.enterText(find.byKey(const ValueKey('field-new-password')), 'Baru1234');
    await tester.enterText(find.byKey(const ValueKey('field-new-password2')), 'Baru1234');
    await tester.tap(find.text('Simpan'));
    await tester.pumpAndSettle();
    expect(find.text('Kata sandi saat ini salah.'), findsOneWidget);
    expect(storage.token, 't');
    expect(find.byType(ChangePasswordPage), findsOneWidget);

    await tester.enterText(find.byKey(const ValueKey('field-current-password')), 'Lama1234');
    await tester.tap(find.text('Simpan'));
    await tester.pumpAndSettle();
    expect(body, {'current_password': 'Lama1234', 'password': 'Baru1234', 'password_confirmation': 'Baru1234'});
    expect(storage.token, 't');
    expect(find.text('Kata sandi diperbarui. Perangkat lain telah dikeluarkan.'), findsOneWidget);
    expect(find.byType(ChangePasswordPage), findsNothing);
  });

  for (final scale in [1.3, 2.0]) {
    for (final width in [360.0, 320.0]) {
      testWidgets('formulir masuk skala $scale lebar $width', (tester) async {
        useCompactScreen(tester, width: width, textScale: scale);
        final base = apiHandler(me: akunJson);
        await pumpApp(
          tester,
          storage: MemoryTokenStorage(),
          handler: (options) => options.uri.path == '/api/v1/auth/forgot-password'
              ? jsonBody(202, {
                  'data': {'message': forgotQueuedMessage},
                })
              : base(options),
        );
        await scrollAuth(tester, find.text('Lupa kata sandi?'));
        expect(tester.takeException(), isNull);
        await tester.tap(find.text('Lupa kata sandi?'));
        await tester.pumpAndSettle();
        await enter(tester, 'field-forgot-email', 'warga.uji@example.test');
        await scrollAuth(tester, find.text('Kirim kode reset'));
        await tester.tap(find.text('Kirim kode reset'));
        await tester.pumpAndSettle();
        await scrollAuth(tester, find.text('Simpan kata sandi baru'));
        expect(tester.takeException(), isNull);

        await popPage(tester);
        await scrollAuth(tester, find.text('Daftar akun utama rumah'));
        await tester.tap(find.text('Daftar akun utama rumah'));
        await tester.pumpAndSettle();
        await scrollAuth(tester, find.text('Daftar'));
        expect(tester.takeException(), isNull);
      });

      testWidgets('ganti sandi dan pending skala $scale lebar $width', (tester) async {
        useCompactScreen(tester, width: width, textScale: scale, height: 700);
        await pumpApp(
          tester,
          storage: MemoryTokenStorage('t'),
          handler: apiHandler(me: akunJson),
        );
        await openAccount(tester);
        await scrollAuth(tester, find.text('Ganti kata sandi'));
        await tester.tap(find.text('Ganti kata sandi'));
        await tester.pumpAndSettle();
        await scrollAuth(tester, find.text('Simpan'));
        expect(tester.takeException(), isNull);
      });
    }

    testWidgets('pending skala $scale', (tester) async {
      useCompactScreen(tester, textScale: scale, width: 320, height: 700);
      await pumpApp(
        tester,
        storage: MemoryTokenStorage('t'),
        handler: apiHandler(
          me: () => akunJson(status: 'menunggu', jenis: 'tambahan'),
          fixtures: {
            '/api/v1/auth/additional-account-status': {
              'data': {
                'permohonan': {'status': 'ditolak', 'catatan': 'Alamat tidak dikenali.'},
              },
            },
          },
        ),
      );
      await scrollAuth(tester, find.text('Keluar'));
      expect(find.byType(PendingPage), findsOneWidget);
      expect(tester.takeException(), isNull);
    });
  }
}

Object? requestBody(RequestOptions options) {
  final data = options.data;
  if (data is Map) return Map<String, dynamic>.from(data);
  if (data is String && data.isNotEmpty) return jsonDecode(data);
  return data;
}

ResponseBody emptyNoContent() => ResponseBody.fromString(
  '',
  204,
  headers: {
    Headers.contentTypeHeader: [Headers.jsonContentType],
  },
);

ResponseBody memberError() => jsonBody(422, {
  'error': {
    'code': 'VALIDATION_FAILED',
    'message': 'Data tidak valid.',
    'fields': {
      'anggota.0.nama': ['Nama anggota tidak valid.'],
    },
    'request_id': '00000000-0000-4000-8000-000000000001',
  },
});

Future<void> enter(WidgetTester tester, String key, String value) async {
  final field = find.byKey(ValueKey(key));
  await scrollAuth(tester, field);
  await tester.enterText(field, value);
}

String visibleTexts(WidgetTester tester) => find.byType(Text).evaluate().map((element) => (element.widget as Text).data).whereType<String>().join(' | ');

Future<void> scrollAuth(WidgetTester tester, Finder finder) async {
  final vertical = find.byWidgetPredicate((widget) => widget is Scrollable && axisDirectionToAxis(widget.axisDirection) == Axis.vertical);
  var guard = 0;
  while (finder.evaluate().isEmpty && vertical.evaluate().isNotEmpty && guard < 20) {
    final state = tester.state<ScrollableState>(vertical.last);
    final extent = state.position.maxScrollExtent;
    if (state.position.pixels >= extent) break;
    state.position.jumpTo((state.position.pixels + 280).clamp(0, extent));
    await tester.pump();
    guard++;
  }
  expect(finder, findsOneWidget);
  await tester.ensureVisible(finder);
  await tester.pump();
  if (finder.hitTestable().evaluate().isEmpty) {
    final host = Scrollable.maybeOf(tester.element(finder));
    if (host != null) {
      final center = tester.getRect(finder).center.dy;
      final height = tester.binding.renderViews.first.size.height;
      host.position.jumpTo((host.position.pixels + center - height / 2).clamp(0.0, host.position.maxScrollExtent));
      await tester.pump();
    }
  }
  final host = Scrollable.maybeOf(tester.element(finder));
  expect(
    finder.hitTestable(),
    findsOneWidget,
    reason: 'rect=${tester.getRect(finder)} extent=${host?.position.maxScrollExtent} pixels=${host?.position.pixels}',
  );
}

Future<void> popPage(WidgetTester tester) async {
  await tester.tap(find.byType(BackButton));
  await tester.pump();
  await tester.pump(const Duration(milliseconds: 400));
}

Future<void> openRegister(WidgetTester tester) async {
  await openRegisterKind(tester, additional: false);
}

Future<void> openRegisterKind(WidgetTester tester, {required bool additional}) async {
  final label = additional ? 'Daftar akun tambahan keluarga' : 'Daftar akun utama rumah';
  await scrollAuth(tester, find.text(label));
  await tester.tap(find.text(label));
  await tester.pumpAndSettle();
}

Future<void> fillRegister(WidgetTester tester, {required bool additional}) async {
  await enter(tester, 'field-token', '012345');
  await enter(tester, 'field-email', additional ? 'anggota.uji@example.test' : 'kepala.uji@example.test');
  await enter(tester, 'field-password', 'Rahasia1');
  await enter(tester, 'field-password2', 'Rahasia1');
  await enter(tester, 'field-nama', additional ? 'Anggota Uji' : 'Kepala Uji');
  if (additional) await enter(tester, 'field-hubungan', 'Anak');
  await enter(tester, 'field-jalan', 'Jalan Uji');
  await enter(tester, 'field-nomor', '1');
}

bool registerCanPop(WidgetTester tester) {
  final scope = tester.widget(
    find.descendant(of: find.byType(RegisterPage), matching: find.byWidgetPredicate((widget) => widget.runtimeType.toString().startsWith('PopScope'))),
  );
  return (scope as dynamic).canPop as bool;
}

/// Tombol sistem ditolak saat `canPop` false. `maybePop` tetap true karena permintaan itu ditangani.
Future<void> vetoRegisterBack(WidgetTester tester) async {
  expect(await Navigator.of(tester.element(find.byType(RegisterPage))).maybePop(), isTrue);
  await tester.pump();
  expect(find.byType(RegisterPage), findsOneWidget);
}

Future<void> dismissSnack(WidgetTester tester) async {
  ScaffoldMessenger.of(tester.element(find.byType(Scaffold).first)).hideCurrentSnackBar();
  await tester.pumpAndSettle();
}

Future<void> openAccount(WidgetTester tester) async {
  await tester.tap(find.widgetWithText(NavigationDestination, 'Akun'));
  await tester.pumpAndSettle();
  await scrollAuth(tester, find.text('Keluar'));
}
