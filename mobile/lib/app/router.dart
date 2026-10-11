import 'package:flutter/foundation.dart';
import 'package:flutter/widgets.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../core/health.dart';
import '../core/session.dart';
import '../features/auth/auth_pages.dart';
import '../features/warga/warga_pages.dart';
import 'shell.dart';
import 'startup.dart';

/// Rute tingkat atas M05. Halaman detail fitur M06+ tetap memakai Navigator.push.
abstract final class Routes {
  static const splash = '/splash';
  static const login = '/login';
  static const register = '/register';
  static const forgot = '/forgot';
  static const pending = '/pending';
  static const home = '/home';
  static const notifications = '/notifications';

  static const guest = {login, register, forgot};
  static const all = {splash, login, register, forgot, pending, home, notifications};
}

/// Guard sesi murni (diuji langsung):
/// - server belum terverifikasi / sesi sedang dipulihkan / sesi tidak dapat divalidasi → splash;
/// - tamu → hanya login/daftar/lupa sandi;
/// - akun menunggu persetujuan → hanya pending;
/// - akun aktif → beranda dan notifikasi, bukan halaman tamu.
String? sessionRedirect({required HealthState health, required SessionState session, required String location}) {
  final booting = health.ok == null || session.loading || session.unavailable != null;
  if (booting) return location == Routes.splash ? null : Routes.splash;
  if (!session.signedIn) return Routes.guest.contains(location) ? null : Routes.login;
  if (session.pending) return location == Routes.pending ? null : Routes.pending;
  if (!Routes.all.contains(location) || Routes.guest.contains(location) || location == Routes.splash || location == Routes.pending) {
    return Routes.home;
  }
  return null;
}

class _Refresh extends ChangeNotifier {
  void ping() => notifyListeners();
}

final routerProvider = Provider<GoRouter>((ref) {
  final refresh = _Refresh();
  ref.listen(sessionProvider, (_, _) => refresh.ping());
  ref.listen(healthProvider, (_, _) => refresh.ping());
  final router = GoRouter(
    initialLocation: Routes.splash,
    refreshListenable: refresh,
    redirect: (context, state) => sessionRedirect(health: ref.read(healthProvider), session: ref.read(sessionProvider), location: state.uri.path),
    routes: [
      GoRoute(path: Routes.splash, builder: (_, _) => const SplashPage()),
      GoRoute(path: Routes.login, builder: (_, _) => const LoginPage()),
      GoRoute(
        path: Routes.register,
        builder: (_, s) => RegisterPage(additional: s.uri.queryParameters['jenis'] == 'tambahan'),
      ),
      GoRoute(path: Routes.forgot, builder: (_, _) => const ForgotPasswordPage()),
      GoRoute(path: Routes.pending, builder: (_, _) => const PendingPage()),
      GoRoute(
        path: Routes.home,
        builder: (_, _) => HomeShell(key: ValueKey(ref.read(sessionProvider).akun?.id)),
      ),
      GoRoute(path: Routes.notifications, builder: (_, _) => const NotificationsPage()),
    ],
  );
  ref.onDispose(() {
    router.dispose();
    refresh.dispose();
  });
  return router;
});
