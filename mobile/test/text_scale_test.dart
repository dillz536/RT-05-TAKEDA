import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:rt05_takeda/features/auth/auth_pages.dart';

import 'support/app_harness.dart';
import 'support/fakes.dart';

/// Skala teks dasar (ROADMAP M05): login dan shell warga/pengurus pada layar compact
/// dengan skala 1,3 dan 2,0 tidak boleh overflow (RenderFlex overflow = exception tes).
void main() {
  const scales = [1.3, 2.0];
  const widths = [360.0, 320.0];

  for (final scale in scales) {
    for (final width in widths) {
      testWidgets('login skala $scale lebar $width', (tester) async {
        useCompactScreen(tester, width: width, textScale: scale);
        await pumpApp(
          tester,
          storage: MemoryTokenStorage(),
          handler: apiHandler(me: akunJson),
        );
        expect(find.byType(LoginPage), findsOneWidget);
        await tester.scrollUntilVisible(find.text('Daftar akun tambahan keluarga'), 200, scrollable: find.byType(Scrollable).first);
        await tester.pumpAndSettle();
        expect(tester.takeException(), isNull);
      });
    }

    for (final (role, tabs) in [
      ('warga', ['Beranda', 'Layanan', 'Agenda', 'Iuran', 'Akun']),
      ('pengurus', ['Ringkasan', 'Warga', 'Layanan', 'Keuangan', 'Lainnya']),
    ]) {
      testWidgets('shell $role skala $scale lebar 360: semua tab', (tester) async {
        useCompactScreen(tester, textScale: scale);
        await pumpApp(
          tester,
          storage: MemoryTokenStorage('t'),
          handler: apiHandler(me: () => akunJson(peran: role)),
        );
        for (final tab in tabs) {
          await tester.tap(find.widgetWithText(NavigationDestination, tab));
          await tester.pumpAndSettle();
          expect(tester.takeException(), isNull, reason: tab);
        }
      });
    }
  }
}
