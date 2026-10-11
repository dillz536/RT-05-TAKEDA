import 'package:flutter_test/flutter_test.dart';
import 'package:rt05_takeda/app/config.dart';

void main() {
  List<String> problems(String flavor, String url) {
    try {
      AppConfig.parse(flavor: flavor, apiBaseUrl: url);
      return const [];
    } on InvalidConfig catch (e) {
      return e.problems;
    }
  }

  test('staging/prod HTTPS root valid; garis miring akhir dinormalisasi', () {
    final s = AppConfig.parse(flavor: 'staging', apiBaseUrl: 'https://takeda-staging.morriz.tech/');
    expect(s.flavor, Flavor.staging);
    expect(s.apiBaseUrl, 'https://takeda-staging.morriz.tech');
    expect(s.isProduction, isFalse);
    final p = AppConfig.parse(flavor: 'prod', apiBaseUrl: 'https://takeda.morriz.tech');
    expect(p.isProduction, isTrue);
    expect(p.flavor.badge, isEmpty);
  });

  test('flavor non-dev menolak HTTP, termasuk host lokal', () {
    expect(problems('staging', 'http://takeda-staging.morriz.tech'), contains('Flavor staging wajib HTTPS.'));
    expect(problems('prod', 'http://10.0.2.2:8077'), contains('Flavor prod wajib HTTPS.'));
  });

  test('dev: HTTP hanya host lokal yang disetujui; HTTPS boleh', () {
    expect(AppConfig.parse(flavor: 'dev', apiBaseUrl: 'http://10.0.2.2:8077').apiBaseUrl, 'http://10.0.2.2:8077');
    expect(AppConfig.parse(flavor: 'dev', apiBaseUrl: 'http://127.0.0.1:8077').flavor, Flavor.dev);
    expect(AppConfig.parse(flavor: 'dev', apiBaseUrl: 'https://takeda-staging.morriz.tech').flavor.badge, 'DEV');
    expect(problems('dev', 'http://192.168.1.10:8077'), contains('HTTP pada dev hanya untuk host lokal yang disetujui.'));
    expect(problems('dev', 'http://takeda-staging.morriz.tech'), isNotEmpty);
  });

  test('flavor tidak dikenal atau beda huruf ditolak', () {
    for (final f in ['', 'production', 'Staging', 'qa']) {
      expect(problems(f, 'https://takeda.morriz.tech'), contains('FLAVOR harus dev, staging, atau prod.'), reason: f);
    }
  });

  test('URL dengan kredensial/query/fragment/path/skema lain ditolak tanpa menyalin nilai', () {
    final cases = {
      'https://user:rahasia@takeda.morriz.tech': 'API_BASE_URL tidak boleh memuat kredensial.',
      'https://takeda.morriz.tech?token=abc': 'API_BASE_URL tidak boleh memuat query atau fragment.',
      'https://takeda.morriz.tech#x': 'API_BASE_URL tidak boleh memuat query atau fragment.',
      'https://takeda.morriz.tech/api/v1': 'API_BASE_URL harus origin root tanpa path.',
      'ftp://takeda.morriz.tech': 'API_BASE_URL harus memakai https.',
    };
    cases.forEach((url, expected) {
      final p = problems('prod', url);
      expect(p, contains(expected), reason: url);
      expect(p.join(' '), isNot(contains('rahasia')));
      expect(p.join(' '), isNot(contains('token=abc')));
    });
  });

  test('URL kosong, relatif, atau berspasi ditolak', () {
    for (final url in ['', 'takeda.morriz.tech', '/api', ' https://takeda.morriz.tech', 'https://']) {
      expect(problems('prod', url), contains('API_BASE_URL harus URL absolut dengan host.'), reason: url);
    }
  });

  test('default build tanpa dart-define = dev ke API lokal emulator dan valid', () {
    final c = AppConfig.fromEnvironment();
    expect(c.flavor, Flavor.dev);
    expect(c.apiBaseUrl, 'http://10.0.2.2:8077');
  });

  test('port sah diterima dan port default dinormalisasi', () {
    expect(AppConfig.parse(flavor: 'staging', apiBaseUrl: 'https://takeda-staging.morriz.tech:443/').apiBaseUrl, 'https://takeda-staging.morriz.tech');
    expect(AppConfig.parse(flavor: 'dev', apiBaseUrl: 'http://127.0.0.1:65535/').apiBaseUrl, 'http://127.0.0.1:65535');
  });

  test('konfigurasi malformed ditolak sebelum request: port, kontrol, path normalisasi dan authority', () {
    for (final url in [
      'https://takeda.morriz.tech:0',
      'https://takeda.morriz.tech:99999',
      'https://takeda.morriz.tech:',
      'https://takeda.morriz.tech:abc',
      'https:takeda.morriz.tech',
      'https:/takeda.morriz.tech',
      'https://takeda.morriz.tech/../',
      'https://takeda.morriz.tech\\\\',
      'https://takeda.morriz.tech?',
      'https://takeda.morriz.tech#',
      'https://@takeda.morriz.tech',
      'https://takeda.morriz.tech\n',
      'https://takeda.morriz.tech\u0000',
      'https://takeda. morriz.tech',
    ]) {
      expect(problems('prod', url), isNotEmpty, reason: url);
    }
  });
}
