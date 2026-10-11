import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'app/config.dart';
import 'app/router.dart';
import 'app/startup.dart';
import 'app/theme.dart';
import 'core/api.dart';

void main() {
  final AppConfig config;
  try {
    config = AppConfig.fromEnvironment();
  } on InvalidConfig catch (e) {
    runApp(ConfigErrorApp(problems: e.problems));
    return;
  }
  runApp(ProviderScope(overrides: [appConfigProvider.overrideWithValue(config)], child: const Rt05App()));
}

const appLocale = Locale('id', 'ID');

class Rt05App extends ConsumerStatefulWidget {
  const Rt05App({super.key});
  @override
  ConsumerState<Rt05App> createState() => _Rt05AppState();
}

class _Rt05AppState extends ConsumerState<Rt05App> {
  @override
  void initState() {
    super.initState();
    // Health /health/live + pemulihan sesi (token divalidasi ulang ke /auth/me; status & peran dari server).
    Future.microtask(() => bootstrap(ref));
  }

  @override
  Widget build(BuildContext context) {
    final flavor = ref.watch(appConfigProvider).flavor;
    return MaterialApp.router(
      title: AppConfig.appName,
      debugShowCheckedModeBanner: false,
      theme: buildTheme(),
      locale: appLocale,
      supportedLocales: const [appLocale],
      localizationsDelegates: GlobalMaterialLocalizations.delegates,
      routerConfig: ref.watch(routerProvider),
      builder: (context, child) => FlavorBanner(flavor: flavor, child: child ?? const SizedBox.shrink()),
    );
  }
}

/// Dipakai widget test untuk memastikan error API tampil sebagai pesan, bukan crash.
String describeError(Object e) => e is ApiException ? e.display : 'Terjadi kesalahan. Coba lagi.';
