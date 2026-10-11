import 'package:flutter_riverpod/flutter_riverpod.dart';

/// Flavor build. Nilai `--dart-define=FLAVOR` harus persis salah satu nama ini.
enum Flavor {
  dev('DEV'),
  staging('STAGING'),
  prod('');

  const Flavor(this.badge);

  /// Label badge di aplikasi; kosong untuk production.
  final String badge;
}

/// Konfigurasi compile-time nonsensitif (docs/MOBILE.md). Tidak ada secret server di APK.
///
/// flutter run --dart-define=FLAVOR=staging --dart-define=API_BASE_URL=https://takeda-staging.morriz.tech
/// Default (tanpa define): dev → API lokal di host emulator Android (10.0.2.2 = localhost PC).
/// Versi aplikasi hanya dari pubspec.yaml (`version:` → versionName/versionCode Android).
class AppConfig {
  const AppConfig._(this.flavor, this.apiBaseUrl);

  static const appName = 'RT05 TAKEDA';

  static const rawFlavor = String.fromEnvironment('FLAVOR', defaultValue: 'dev');
  static const rawApiBaseUrl = String.fromEnvironment('API_BASE_URL', defaultValue: 'http://10.0.2.2:8077');

  /// Host yang boleh HTTP pada flavor dev saja; sama dengan network security config debug Android.
  static const devHttpHosts = {'10.0.2.2', '127.0.0.1'};

  final Flavor flavor;

  /// Origin API tanpa garis miring akhir, mis. `https://takeda-staging.morriz.tech`.
  final String apiBaseUrl;

  bool get isProduction => flavor == Flavor.prod;

  /// Memvalidasi FLAVOR dan API_BASE_URL. Pesan error tidak menyalin nilai URL
  /// agar kredensial yang keliru dimasukkan tidak ikut tampil.
  static AppConfig parse({required String flavor, required String apiBaseUrl}) {
    final problems = <String>[];
    final f = Flavor.values.where((v) => v.name == flavor).firstOrNull;
    if (f == null) problems.add('FLAVOR harus dev, staging, atau prod.');

    final uri = Uri.tryParse(apiBaseUrl);
    final rawOrigin = RegExp(r'^[a-zA-Z][a-zA-Z0-9+.-]*://([^/?#\\]+)\/?$').firstMatch(apiBaseUrl);
    final whitespace = RegExp(r'[\s\x00-\x1f\x7f]').hasMatch(apiBaseUrl);
    if (whitespace || apiBaseUrl.isEmpty || uri == null || !uri.hasScheme || uri.host.isEmpty) {
      problems.add('API_BASE_URL harus URL absolut dengan host.');
    } else {
      if (uri.scheme != 'https' && uri.scheme != 'http') problems.add('API_BASE_URL harus memakai https.');
      if (apiBaseUrl.contains('@') || uri.userInfo.isNotEmpty) problems.add('API_BASE_URL tidak boleh memuat kredensial.');
      if (uri.hasQuery || uri.hasFragment) problems.add('API_BASE_URL tidak boleh memuat query atau fragment.');
      // Periksa bentuk mentah sebelum Uri menormalisasi /../ atau backslash.
      if (rawOrigin == null || (uri.path.isNotEmpty && uri.path != '/')) problems.add('API_BASE_URL harus origin root tanpa path.');
      if (rawOrigin != null && !apiBaseUrl.contains('@')) {
        final authority = rawOrigin.group(1)!;
        final parts = authority.startsWith('[')
            ? RegExp(r'^\[[^\]]+\](?::([0-9]+))?$').firstMatch(authority)
            : RegExp(r'^[^:]+(?::([0-9]+))?$').firstMatch(authority);
        final port = parts?.group(1);
        final number = port == null ? null : int.tryParse(port);
        if (parts == null || (port != null && (number == null || number < 1 || number > 65535))) {
          problems.add('Port API_BASE_URL harus 1 sampai 65535.');
        }
      }
      if (uri.scheme == 'http' && f != null) {
        if (f != Flavor.dev) {
          problems.add('Flavor ${f.name} wajib HTTPS.');
        } else if (!devHttpHosts.contains(uri.host)) {
          problems.add('HTTP pada dev hanya untuk host lokal yang disetujui.');
        }
      }
    }
    if (problems.isNotEmpty) throw InvalidConfig(problems);
    return AppConfig._(f!, '${uri!.scheme}://${uri.authority}');
  }

  static AppConfig fromEnvironment() => parse(flavor: rawFlavor, apiBaseUrl: rawApiBaseUrl);
}

class InvalidConfig implements Exception {
  const InvalidConfig(this.problems);
  final List<String> problems;

  @override
  String toString() => 'InvalidConfig: ${problems.join(' ')}';
}

/// Di-override di `main()` dengan konfigurasi yang sudah divalidasi; tes memberi nilai sendiri.
final appConfigProvider = Provider<AppConfig>((ref) => AppConfig.fromEnvironment());
