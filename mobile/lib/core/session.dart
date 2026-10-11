import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import 'api.dart';
import 'models.dart';

/// Penyimpanan token sesi. Aplikasi memakai secure storage OS (Keystore Android), bukan preferences biasa.
abstract interface class TokenStorage {
  Future<String?> read();
  Future<void> write(String token);
  Future<void> delete();
}

class SecureTokenStorage implements TokenStorage {
  const SecureTokenStorage();
  static const _storage = FlutterSecureStorage();
  static const _key = 'rt05_token';

  @override
  Future<String?> read() => _storage.read(key: _key);
  @override
  Future<void> write(String token) => _storage.write(key: _key, value: token);
  @override
  Future<void> delete() => _storage.delete(key: _key);
}

final tokenStorageProvider = Provider<TokenStorage>((ref) => const SecureTokenStorage());

/// Validasi token ke server (`GET /auth/me`): status dan peran selalu dari API, bukan cache.
typedef FetchMe = Future<Akun> Function(String token);

final fetchMeProvider = Provider<FetchMe>(
  (ref) => (token) async {
    final me = await ref.read(apiProvider).get('/auth/me');
    return Akun(Map<String, dynamic>.from(me as Map));
  },
);

/// Status sesi aplikasi.
///
/// - `loading`: memulihkan sesi.
/// - `signedIn`: token + akun tervalidasi server.
/// - `unavailable`: token tetap tersimpan tetapi server belum dapat memvalidasinya
///   (offline, timeout, 5xx, 403). Data akun tidak ditampilkan sampai validasi berhasil.
class SessionState {
  const SessionState({this.loading = true, this.token, this.akun, this.expired = false, this.unavailable});

  final bool loading;
  final String? token;
  final Akun? akun;
  final bool expired;
  final ApiException? unavailable;

  bool get signedIn => token != null && akun != null;
  bool get pending => akun?.status == 'menunggu';
  bool get pengurus => akun?.peran == 'pengurus';
}

class SessionController extends Notifier<SessionState> {
  /// Naik pada setiap perubahan sesi; hasil async lama (restore/401) yang selesai
  /// setelah keluar/masuk baru diabaikan sehingga tidak menimpa sesi terbaru.
  int _epoch = 0;

  /// Operasi storage dijalankan berurutan: hapus milik logout tidak dapat
  /// menyusul dan menghapus token login baru.
  Future<void> _io = Future.value();

  TokenStorage get _storage => ref.read(tokenStorageProvider);

  Future<T> _serial<T>(Future<T> Function() op) {
    final next = _io.then((_) => op());
    _io = next.then((_) {}, onError: (_) {});
    return next;
  }

  @override
  SessionState build() => const SessionState();

  /// Memulihkan sesi saat start atau "Coba lagi". Token hanya dihapus bila server
  /// menjawab 401 (token tidak berlaku); error jaringan/5xx/403 mempertahankannya.
  Future<void> restore() async {
    final epoch = ++_epoch;
    state = const SessionState();
    final String? token;
    try {
      token = await _serial(_storage.read);
    } catch (_) {
      if (epoch == _epoch) state = const SessionState(loading: false);
      return;
    }
    if (epoch != _epoch) return;
    if (token == null) {
      state = const SessionState(loading: false);
      return;
    }
    state = SessionState(token: token);
    try {
      final akun = await ref.read(fetchMeProvider)(token);
      if (epoch != _epoch) return;
      state = SessionState(loading: false, token: token, akun: akun);
    } on ApiException catch (e) {
      if (epoch != _epoch) return;
      if (e.status == 401) {
        _epoch++;
        state = const SessionState(loading: false, expired: true);
        await _serial(_storage.delete);
      } else {
        state = SessionState(loading: false, token: token, unavailable: e);
      }
    } catch (_) {
      if (epoch != _epoch) return;
      state = SessionState(loading: false, token: token, unavailable: ApiException(0, 'UNKNOWN', 'Terjadi kesalahan. Coba lagi.'));
    }
  }

  Future<void> signIn(String token, Akun akun) async {
    final epoch = ++_epoch;
    await _serial(() => _storage.write(token));
    if (epoch != _epoch) return;
    state = SessionState(loading: false, token: token, akun: akun);
  }

  void updateAkun(Akun akun) {
    if (state.token == null) return;
    state = SessionState(loading: false, token: state.token, akun: akun);
  }

  /// Logout: hapus token dan cache pribadi.
  Future<void> signOut() async {
    _epoch++;
    state = const SessionState(loading: false);
    await _serial(_storage.delete);
  }

  /// 401 dari API. [token] = token yang dipakai request tersebut; respons milik sesi
  /// lama (sudah keluar/berganti akun) tidak mengakhiri sesi sekarang.
  Future<void> expire({String? token}) async {
    if (state.token == null || (token != null && token != state.token)) return;
    _epoch++;
    state = const SessionState(loading: false, expired: true);
    await _serial(_storage.delete);
  }
}

final sessionProvider = NotifierProvider<SessionController, SessionState>(SessionController.new);
