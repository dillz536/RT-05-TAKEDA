import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../core/health.dart';
import '../core/session.dart';
import '../core/widgets.dart';
import 'config.dart';
import 'theme.dart';

/// Bootstrap: health liveness nyata dan pemulihan sesi berjalan paralel.
Future<void> bootstrap(WidgetRef ref) => Future.wait([ref.read(healthProvider.notifier).check(), ref.read(sessionProvider.notifier).restore()]);

/// Splash: memeriksa server dan sesi. Bila server tidak tersedia atau sesi belum dapat
/// divalidasi, tampil pesan + "Coba lagi" tanpa membuka data akun dari cache.
class SplashPage extends ConsumerWidget {
  const SplashPage({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final health = ref.watch(healthProvider);
    final session = ref.watch(sessionProvider);
    final problem = health.error?.message ?? (session.loading ? null : session.unavailable?.display);
    final busy = problem == null;

    return Scaffold(
      backgroundColor: Brand.navy,
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                const GapuraMark(size: 88, light: true),
                const SizedBox(height: 16),
                const Text(
                  AppConfig.appName,
                  textAlign: TextAlign.center,
                  style: TextStyle(fontFamily: Brand.fontDisplay, color: Colors.white, fontSize: 24, fontWeight: FontWeight.w800, letterSpacing: 1),
                ),
                const SizedBox(height: 4),
                const Text(
                  'RT 05 Taman Kedaung',
                  textAlign: TextAlign.center,
                  style: TextStyle(color: Brand.yellow),
                ),
                const SizedBox(height: 32),
                if (busy)
                  Semantics(
                    label: 'Memeriksa server',
                    child: const CircularProgressIndicator(color: Colors.white),
                  )
                else ...[
                  const Icon(Icons.cloud_off_rounded, color: Colors.white70, size: 40),
                  const SizedBox(height: 12),
                  Text(
                    problem,
                    textAlign: TextAlign.center,
                    style: const TextStyle(color: Colors.white),
                  ),
                  const SizedBox(height: 20),
                  FilledButton.icon(
                    style: FilledButton.styleFrom(backgroundColor: Brand.yellow, foregroundColor: Brand.navy),
                    onPressed: () => bootstrap(ref),
                    icon: const Icon(Icons.refresh),
                    label: const Text('Coba lagi'),
                  ),
                  if (session.token != null) ...[
                    const SizedBox(height: 8),
                    TextButton(
                      style: TextButton.styleFrom(foregroundColor: Colors.white),
                      onPressed: () => ref.read(sessionProvider.notifier).signOut(),
                      child: const Text('Keluar dari perangkat ini'),
                    ),
                  ],
                ],
                const SizedBox(height: 24),
                const ServerInfo(light: true),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

/// Diagnostik QA nonproduction: flavor, host API, dan request_id health terakhir
/// (untuk dicocokkan dengan log API). Tidak tampil di production.
class ServerInfo extends ConsumerWidget {
  const ServerInfo({super.key, this.light = false});
  final bool light;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final config = ref.watch(appConfigProvider);
    if (config.isProduction) return const SizedBox.shrink();
    final health = ref.watch(healthProvider);
    final status = health.checking
        ? 'memeriksa'
        : (health.ok != null ? 'health ok • ${health.ok!.requestId ?? 'tanpa request_id'}' : 'health gagal (${health.error!.kind.name})');
    return Text(
      '${config.flavor.name} • ${Uri.parse(config.apiBaseUrl).host} • $status',
      key: const Key('server-info'),
      textAlign: TextAlign.center,
      style: TextStyle(fontSize: 11, color: light ? Colors.white70 : Brand.muted),
    );
  }
}

/// Badge flavor nonproduction di sudut layar (DEV/STAGING). Production tanpa badge.
class FlavorBanner extends StatelessWidget {
  const FlavorBanner({super.key, required this.flavor, required this.child});
  final Flavor flavor;
  final Widget child;

  @override
  Widget build(BuildContext context) => flavor.badge.isEmpty
      ? child
      : Banner(
          message: flavor.badge,
          location: BannerLocation.topStart,
          color: flavor == Flavor.staging ? Brand.yellow : Brand.danger,
          textStyle: TextStyle(color: flavor == Flavor.staging ? Brand.navy : Colors.white, fontSize: 10, fontWeight: FontWeight.w800, height: 1),
          child: child,
        );
}

/// Layar aman bila FLAVOR/API_BASE_URL build tidak valid: tidak ada request jaringan sama sekali.
class ConfigErrorApp extends StatelessWidget {
  const ConfigErrorApp({super.key, required this.problems});
  final List<String> problems;

  @override
  Widget build(BuildContext context) => MaterialApp(
    title: AppConfig.appName,
    debugShowCheckedModeBanner: false,
    theme: buildTheme(),
    home: Scaffold(
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.all(24),
          children: [
            const Row(
              children: [
                GapuraMark(size: 48),
                SizedBox(width: 12),
                Expanded(
                  child: Text(
                    AppConfig.appName,
                    style: TextStyle(fontFamily: Brand.fontDisplay, fontSize: 22, fontWeight: FontWeight.w700, color: Brand.navy),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 24),
            const Icon(Icons.build_circle_outlined, size: 48, color: Brand.danger),
            const SizedBox(height: 12),
            Text('Konfigurasi aplikasi tidak valid', style: Theme.of(context).textTheme.headlineSmall),
            const SizedBox(height: 8),
            const Text('Build ini tidak dapat dipakai dan tidak menghubungi server. Pasang APK resmi terbaru dari pengurus.'),
            const SizedBox(height: 16),
            for (final p in problems)
              Padding(
                padding: const EdgeInsets.only(bottom: 6),
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Icon(Icons.error_outline, size: 18, color: Brand.danger),
                    const SizedBox(width: 8),
                    Expanded(child: Text(p)),
                  ],
                ),
              ),
          ],
        ),
      ),
    ),
  );
}
