import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_svg/flutter_svg.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:rt05_takeda/app/startup.dart';
import 'package:rt05_takeda/app/theme.dart';
import 'package:rt05_takeda/core/widgets.dart';
import 'package:rt05_takeda/features/auth/auth_pages.dart';

import 'support/app_harness.dart';
import 'support/fakes.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  group('logo resmi', () {
    for (final name in ['rt05-mark.svg', 'rt05-mark-terang.svg']) {
      test('$name byte-identik dengan website dan terbundel', () async {
        final website = File('../website/public/images/logo/$name').readAsBytesSync();
        expect(File('assets/brand/$name').readAsBytesSync(), website);
        final bundled = await rootBundle.load('assets/brand/$name');
        expect(bundled.buffer.asUint8List(), website);
      });
    }

    testWidgets('GapuraMark memakai SVG resmi (gelap/terang), bukan gambar tangan', (tester) async {
      await tester.pumpWidget(const MaterialApp(home: Row(children: [GapuraMark(), GapuraMark(light: true)])));
      final pictures = tester.widgetList<SvgPicture>(find.byType(SvgPicture)).toList();
      expect(pictures.map((p) => (p.bytesLoader as SvgAssetLoader).assetName), [GapuraMark.asset, GapuraMark.assetLight]);
    });

    test('ikon launcher Android dihasilkan dari logo resmi (warna sama, adaptive + legacy)', () {
      const res = 'android/app/src/main/res';
      final svg = File('assets/brand/rt05-mark.svg').readAsStringSync();
      final vector = File('$res/drawable/ic_launcher_foreground.xml').readAsStringSync();
      final colors = RegExp(r'#([0-9a-fA-F]{6})').allMatches(svg).map((m) => m.group(1)!.toUpperCase()).toSet();
      expect(colors, containsAll(['123E65', 'C8102E', 'F2CD5A', 'FFFFFF']));
      for (final c in colors) {
        expect(vector, contains('#FF$c'), reason: c);
      }
      expect(vector, contains('tool/launcher_icon.py'));
      expect(File('$res/mipmap-anydpi-v26/ic_launcher.xml').readAsStringSync(), contains('@drawable/ic_launcher_foreground'));
      for (final d in ['mdpi', 'hdpi', 'xhdpi', 'xxhdpi', 'xxxhdpi']) {
        expect(File('$res/mipmap-$d/ic_launcher.png').existsSync(), isTrue, reason: d);
      }
    });
  });

  group('font terbundel', () {
    test('family tema terdaftar di FontManifest dan setiap TTF termuat dari aset', () async {
      final manifest = (jsonDecode(await rootBundle.loadString('FontManifest.json')) as List).cast<Map>();
      final families = {for (final f in manifest) f['family']: (f['fonts'] as List).map((x) => (x as Map)['asset'] as String).toList()};
      expect(families.keys, containsAll([Brand.fontBody, Brand.fontDisplay]));
      for (final asset in [...families[Brand.fontBody]!, ...families[Brand.fontDisplay]!]) {
        final bytes = (await rootBundle.load(asset)).buffer.asUint8List();
        expect(bytes.sublist(0, 4), [0, 1, 0, 0], reason: '$asset harus TrueType');
      }
      expect(File('assets/fonts/OFL-PublicSans.txt').readAsStringSync(), contains('SIL Open Font License'));
      expect(File('assets/fonts/OFL-BricolageGrotesque.txt').readAsStringSync(), contains('SIL Open Font License'));
    });

    test('tema memakai font aset dan token warna website', () {
      final t = buildTheme();
      expect(t.textTheme.bodyMedium!.fontFamily, Brand.fontBody);
      expect(t.textTheme.titleLarge!.fontFamily, Brand.fontDisplay);
      expect(t.appBarTheme.titleTextStyle!.fontFamily, Brand.fontDisplay);
      final css = File('../website/resources/css/app.css').readAsStringSync();
      String hex(Color c) => '#${(c.toARGB32() & 0xFFFFFF).toRadixString(16).padLeft(6, '0')}';
      for (final (name, color) in [
        ('navy', Brand.navy),
        ('ink', Brand.ink),
        ('muted', Brand.muted),
        ('sky', Brand.sky),
        ('line', Brand.line),
        ('yellow', Brand.yellow),
      ]) {
        expect(css, contains('--$name:${hex(color)}'), reason: name);
      }
    });

    test('tanpa google_fonts / unduhan font runtime', () {
      expect(File('pubspec.yaml').readAsStringSync(), isNot(contains('google_fonts')));
      final sources = Directory('lib').listSync(recursive: true).whereType<File>().map((f) => f.readAsStringSync()).join();
      expect(sources, isNot(contains('fonts.googleapis')));
      expect(sources, isNot(contains('fonts.gstatic')));
      expect(sources, isNot(contains('GoogleFonts')));
    });
  });

  group('flavor, lokalisasi, konfigurasi', () {
    testWidgets('staging: badge STAGING dan lokalisasi Material Indonesia', (tester) async {
      await pumpApp(
        tester,
        storage: MemoryTokenStorage(),
        handler: apiHandler(me: akunJson),
      );
      final banner = tester.widget<Banner>(find.byType(Banner));
      expect(banner.message, 'STAGING');
      final ctx = tester.element(find.byType(LoginPage));
      expect(Localizations.localeOf(ctx).languageCode, 'id');
      expect(MaterialLocalizations.of(ctx).cancelButtonLabel, 'Batal');
      expect(MaterialLocalizations.of(ctx).datePickerHelpText, 'Pilih tanggal');
    });

    testWidgets('dev: badge DEV', (tester) async {
      await pumpApp(
        tester,
        storage: MemoryTokenStorage(),
        handler: apiHandler(me: akunJson),
        flavor: 'dev',
      );
      expect(tester.widget<Banner>(find.byType(Banner)).message, 'DEV');
    });

    testWidgets('prod: tanpa badge dan tanpa diagnostik server', (tester) async {
      await pumpApp(
        tester,
        storage: MemoryTokenStorage(),
        handler: apiHandler(me: akunJson),
        flavor: 'prod',
      );
      expect(find.byType(Banner), findsNothing);
      expect(find.byKey(const Key('server-info')), findsNothing);
    });

    testWidgets('konfigurasi tidak valid: layar aman berisi masalah', (tester) async {
      await tester.pumpWidget(const ConfigErrorApp(problems: ['Flavor prod wajib HTTPS.']));
      expect(find.text('Konfigurasi aplikasi tidak valid'), findsOneWidget);
      expect(find.text('Flavor prod wajib HTTPS.'), findsOneWidget);
    });
  });
}
