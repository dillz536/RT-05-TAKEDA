import 'package:flutter/widgets.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:rt05_takeda/app/config.dart';
import 'package:rt05_takeda/core/health.dart';
import 'package:rt05_takeda/core/session.dart';
import 'package:rt05_takeda/main.dart';

import 'fakes.dart';

/// Memasang aplikasi utuh (router, bootstrap, Api, sesi) dengan storage memori dan transport HTTP palsu.
Future<FakeAdapter> pumpApp(
  WidgetTester tester, {
  required MemoryTokenStorage storage,
  required FakeHandler handler,
  String flavor = 'staging',
  bool settle = true,
}) async {
  final adapter = FakeAdapter(handler);
  await tester.pumpWidget(
    ProviderScope(
      overrides: [
        appConfigProvider.overrideWithValue(AppConfig.parse(flavor: flavor, apiBaseUrl: 'https://api.example.test')),
        tokenStorageProvider.overrideWithValue(storage),
        httpAdapterProvider.overrideWithValue(adapter),
      ],
      child: const Rt05App(),
    ),
  );
  if (settle) await tester.pumpAndSettle();
  return adapter;
}

/// Layar ponsel compact (lebar logis [width]) dengan skala teks sistem [textScale].
void useCompactScreen(WidgetTester tester, {double width = 360, double height = 640, double textScale = 1.0}) {
  tester.view.devicePixelRatio = 3;
  tester.view.physicalSize = Size(width * 3, height * 3);
  tester.platformDispatcher.textScaleFactorTestValue = textScale;
  addTearDown(tester.view.reset);
  addTearDown(tester.platformDispatcher.clearTextScaleFactorTestValue);
}
