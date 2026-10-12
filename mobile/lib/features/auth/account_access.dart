import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api.dart';
import '../../core/session.dart';

/// Batas bcrypt di `PasswordPolicy` server: 72 byte UTF-8. Sama untuk daftar, reset, dan ganti sandi.
const passwordMaxUtf8Bytes = 72;
const passwordTooLongMessage = 'Kata sandi maksimal 72 byte. Huruf dan angka biasa dihitung 1 byte; huruf beraksen atau emoji dihitung lebih.';
const passwordNulMessage = 'Kata sandi berisi karakter yang tidak didukung.';

/// Respons lupa sandi yang tidak mengklaim email sudah terkirim.
const forgotQueuedMessage = 'Jika email terdaftar, permintaan reset kata sandi telah diterima dan diantrekan.';

final _deliveryClaim = RegExp(r'dikirim|terkirim', caseSensitive: false);

final _letter = RegExp(r'\p{L}', unicode: true);
final _number = RegExp(r'\p{N}', unicode: true);
final _email = RegExp(r'^[^@\s]+@[^@\s]+\.[^@\s]+$');
final _whatsapp = RegExp(r'^[0-9+ -]{8,25}$');
final _sixDigits = RegExp(r'^\d{6}$');

/// Kata sandi aplikasi: minimal 8, ada huruf dan angka, tidak lebih dari 72 byte UTF-8.
String? validateNewPassword(String? raw) {
  final value = raw ?? '';
  if (value.contains('\u0000')) return passwordNulMessage;
  if (utf8.encode(value).length > passwordMaxUtf8Bytes) return passwordTooLongMessage;
  if (value.runes.length < 8 || !_letter.hasMatch(value) || !_number.hasMatch(value)) {
    return 'Minimal 8 karakter, berisi huruf dan angka.';
  }
  return null;
}

/// Pesan sukses lupa sandi. Kalimat server yang mengklaim email terkirim diganti pesan antrean.
String forgotSuccessText(dynamic data) {
  final message = data is Map ? data['message']?.toString().trim() : null;
  if (message == null || message.isEmpty || _deliveryClaim.hasMatch(message)) return forgotQueuedMessage;
  return message;
}

String? validatePasswordConfirmation(String? raw, String password) {
  if (raw == null || raw.isEmpty) return 'Ulangi kata sandi.';
  if (raw != password) return 'Pengulangan kata sandi tidak sama.';
  return null;
}

String? validateEmailAddress(String? raw) {
  final value = raw?.trim() ?? '';
  if (value.length > 254 || !_email.hasMatch(value)) return 'Masukkan email yang valid.';
  return null;
}

String? validateRegistrationToken(String? raw) {
  if (!_sixDigits.hasMatch(raw?.trim() ?? '')) return 'Token harus 6 digit.';
  return null;
}

String? validateWhatsapp(String? raw) {
  final value = raw?.trim() ?? '';
  if (value.isEmpty) return null;
  if (!_whatsapp.hasMatch(value)) return 'Nomor WhatsApp tidak valid.';
  return null;
}

/// Pesan bila server tidak mengonfirmasi pencabutan. Token lokal tidak dihapus.
String keptSessionMessage(ApiException error) {
  final offline = error.code == 'NETWORK_ERROR' || error.code == 'NETWORK_TIMEOUT' || error.status == 0 || error.status >= 500;
  if (offline) {
    return 'Server belum mengonfirmasi pencabutan token. Sesi di perangkat ini tetap tersimpan.';
  }
  return error.display;
}

/// Cabut token di server, lalu hapus token lokal hanya bila sesi belum berganti.
/// Token dan epoch dicatat sebelum request. 401 ikut menghapus bila sesi itu masih yang aktif.
/// Jaringan, timeout, 5xx, dan 403 melempar kembali tanpa menghapus token lokal.
Future<void> revokeServerSession(WidgetRef ref, {required String path, Object? data}) async {
  final session = ref.read(sessionProvider.notifier);
  final token = ref.read(sessionProvider).token;
  final epoch = session.epoch;
  final api = ref.read(apiProvider);
  try {
    await api.post(path, data: data);
  } on ApiException catch (error) {
    if (error.status != 401) rethrow;
  }
  if (token == null) return;
  await session.signOutIfCurrent(token: token, epoch: epoch);
}

/// Kata sandi untuk `POST /auth/logout-all`. Null bila dibatalkan.
Future<String?> askCurrentPassword(BuildContext context) => showDialog<String>(context: context, builder: (context) => const _PasswordDialog());

class _PasswordDialog extends StatefulWidget {
  const _PasswordDialog();

  @override
  State<_PasswordDialog> createState() => _PasswordDialogState();
}

class _PasswordDialogState extends State<_PasswordDialog> {
  final _password = TextEditingController();

  @override
  void dispose() {
    _password.dispose();
    super.dispose();
  }

  void _submit() => Navigator.pop(context, _password.text);

  @override
  Widget build(BuildContext context) => AlertDialog(
    title: const Text('Keluar dari semua perangkat'),
    content: TextField(
      key: const ValueKey('field-logout-all-password'),
      controller: _password,
      obscureText: true,
      autofocus: true,
      decoration: const InputDecoration(labelText: 'Kata sandi saat ini'),
      onSubmitted: (_) => _submit(),
    ),
    actions: [
      TextButton(onPressed: () => Navigator.pop(context), child: const Text('Batal')),
      FilledButton(onPressed: _submit, child: const Text('Keluarkan semua')),
    ],
  );
}
