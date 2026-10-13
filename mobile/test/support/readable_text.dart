import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter_test/flutter_test.dart';

/// Pesan terlukis utuh: data sama dengan teks, baris tidak terpotong, dan lebar muat di layar.
void expectTextFullyVisible(WidgetTester tester, String message) {
  final finder = find.text(message);
  expect(finder, findsOneWidget);
  final paragraph = tester.renderObject<RenderParagraph>(finder);
  expect(paragraph.text.toPlainText(), message);
  expect(paragraph.didExceedMaxLines, isFalse, reason: message);
  final width = tester.view.physicalSize.width / tester.view.devicePixelRatio;
  final rect = tester.getRect(finder);
  expect(rect.width, greaterThan(0), reason: message);
  expect(rect.left, greaterThanOrEqualTo(-1), reason: message);
  expect(rect.right, lessThanOrEqualTo(width + 1), reason: message);
}

/// Gulir sampai [finder] bisa diketuk. Pola sama dengan scrollAuth di auth_access_test.
Future<void> scrollUntilReadable(WidgetTester tester, Finder finder) async {
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
