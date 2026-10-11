import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../app/config.dart';
import '../../app/startup.dart';
import '../../app/theme.dart';
import '../../core/api.dart';
import '../../core/models.dart';
import '../../core/session.dart';
import '../../core/widgets.dart';

Future<void> _finishAuth(WidgetRef ref, dynamic data) async {
  final map = data as Map;
  await ref.read(sessionProvider.notifier).signIn('${map['token']}', Akun(Map<String, dynamic>.from(map['akun'] as Map)));
}

class _AuthScaffold extends StatelessWidget {
  const _AuthScaffold({required this.title, required this.subtitle, required this.child, this.back = false});
  final String title;
  final String subtitle;
  final Widget child;
  final bool back;

  @override
  Widget build(BuildContext context) => Scaffold(
    backgroundColor: Brand.sky,
    appBar: back ? AppBar(backgroundColor: Brand.sky, foregroundColor: Brand.navy) : null,
    body: SafeArea(
      child: ListView(
        padding: const EdgeInsets.fromLTRB(24, 24, 24, 32),
        children: [
          Row(
            children: [
              const GapuraMark(size: 48),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(AppConfig.appName, style: Theme.of(context).textTheme.titleLarge?.copyWith(color: Brand.navy)),
                    const Text('RT 05 Taman Kedaung', style: TextStyle(color: Brand.muted)),
                  ],
                ),
              ),
            ],
          ),
          const SizedBox(height: 28),
          Text(title, style: Theme.of(context).textTheme.headlineSmall),
          const SizedBox(height: 6),
          Text(subtitle, style: const TextStyle(color: Brand.muted, height: 1.4)),
          const SizedBox(height: 24),
          child,
        ],
      ),
    ),
  );
}

class LoginPage extends ConsumerStatefulWidget {
  const LoginPage({super.key});
  @override
  ConsumerState<LoginPage> createState() => _LoginPageState();
}

class _LoginPageState extends ConsumerState<LoginPage> {
  final _email = TextEditingController();
  final _password = TextEditingController();
  final _form = GlobalKey<FormState>();
  bool _hide = true;
  String? _error;

  Future<void> _submit() async {
    if (!_form.currentState!.validate()) return;
    setState(() => _error = null);
    try {
      final data = await ref
          .read(apiProvider)
          .post('/auth/mobile-login', data: {'email': _email.text.trim(), 'password': _password.text, 'device_name': 'Android RT05'});
      await _finishAuth(ref, data);
    } on ApiException catch (e) {
      setState(() => _error = e.display);
    }
  }

  @override
  Widget build(BuildContext context) {
    final expired = ref.watch(sessionProvider).expired;
    return _AuthScaffold(
      title: 'Masuk',
      subtitle: 'Layanan warga dan pengurus RT 05: pengaduan, aspirasi, agenda, iuran, dan kas.',
      child: Form(
        key: _form,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            if (expired) const _Banner('Sesi berakhir. Silakan masuk kembali.'),
            TextFormField(
              controller: _email,
              keyboardType: TextInputType.emailAddress,
              autofillHints: const [AutofillHints.email],
              decoration: const InputDecoration(labelText: 'Email', prefixIcon: Icon(Icons.mail_outline)),
              validator: (v) => (v == null || !v.contains('@')) ? 'Masukkan email yang valid' : null,
            ),
            const SizedBox(height: 14),
            TextFormField(
              controller: _password,
              obscureText: _hide,
              autofillHints: const [AutofillHints.password],
              decoration: InputDecoration(
                labelText: 'Kata sandi',
                prefixIcon: const Icon(Icons.lock_outline),
                suffixIcon: IconButton(
                  tooltip: _hide ? 'Tampilkan' : 'Sembunyikan',
                  icon: Icon(_hide ? Icons.visibility : Icons.visibility_off),
                  onPressed: () => setState(() => _hide = !_hide),
                ),
              ),
              validator: (v) => (v == null || v.isEmpty) ? 'Masukkan kata sandi' : null,
              onFieldSubmitted: (_) => _submit(),
            ),
            if (_error != null)
              Padding(
                padding: const EdgeInsets.only(top: 12),
                child: Text(_error!, style: const TextStyle(color: Brand.danger)),
              ),
            const SizedBox(height: 20),
            BusyButton(label: 'Masuk', icon: Icons.login, onPressed: _submit),
            TextButton(onPressed: () => context.push('/forgot'), child: const Text('Lupa kata sandi?')),
            const Divider(height: 32),
            const Text(
              'Belum punya akun? Minta token RT 6 digit kepada pengurus.',
              textAlign: TextAlign.center,
              style: TextStyle(color: Brand.muted),
            ),
            const SizedBox(height: 12),
            OutlinedButton.icon(
              icon: const Icon(Icons.home_work_outlined),
              onPressed: () => context.push('/register'),
              label: const Text('Daftar akun utama rumah'),
            ),
            const SizedBox(height: 10),
            OutlinedButton.icon(
              icon: const Icon(Icons.group_add_outlined),
              onPressed: () => context.push('/register?jenis=tambahan'),
              label: const Text('Daftar akun tambahan keluarga'),
            ),
            const SizedBox(height: 24),
            const ServerInfo(),
          ],
        ),
      ),
    );
  }
}

class _Banner extends StatelessWidget {
  const _Banner(this.text);
  final String text;
  @override
  Widget build(BuildContext context) => Container(
    margin: const EdgeInsets.only(bottom: 16),
    padding: const EdgeInsets.all(12),
    decoration: BoxDecoration(color: Brand.warningBg, borderRadius: BorderRadius.circular(12)),
    child: Row(
      children: [
        const Icon(Icons.info_outline, color: Brand.warning),
        const SizedBox(width: 8),
        Expanded(child: Text(text)),
      ],
    ),
  );
}

/// Registrasi akun utama (rumah + keluarga sekaligus) atau akun tambahan (menunggu persetujuan).
class RegisterPage extends ConsumerStatefulWidget {
  const RegisterPage({super.key, required this.additional});
  final bool additional;
  @override
  ConsumerState<RegisterPage> createState() => _RegisterPageState();
}

class _RegisterPageState extends ConsumerState<RegisterPage> {
  final _form = GlobalKey<FormState>();
  final c = {
    for (final k in ['email', 'password', 'password2', 'token', 'nama', 'hubungan', 'whatsapp', 'blok', 'jalan', 'nomor']) k: TextEditingController(),
  };
  final List<(TextEditingController, TextEditingController)> _anggota = [];
  String _hunian = 'pemilik';
  Map<String, List<String>> _fieldErrors = {};
  String? _error;

  String? _err(String key) => _fieldErrors[key]?.first;

  Future<void> _submit() async {
    setState(() {
      _error = null;
      _fieldErrors = {};
    });
    if (!_form.currentState!.validate()) return;
    final body = <String, dynamic>{
      'email': c['email']!.text.trim(),
      'password': c['password']!.text,
      'password_confirmation': c['password2']!.text,
      'registration_token': c['token']!.text.trim(),
      'device_name': 'Android RT05',
      'blok': c['blok']!.text.trim().isEmpty ? null : c['blok']!.text.trim(),
      'jalan': c['jalan']!.text.trim(),
      'nomor': c['nomor']!.text.trim(),
    };
    if (widget.additional) {
      body['nama'] = c['nama']!.text.trim();
      body['hubungan_keluarga'] = c['hubungan']!.text.trim();
    } else {
      body['nama_kepala'] = c['nama']!.text.trim();
      body['whatsapp'] = c['whatsapp']!.text.trim().isEmpty ? null : c['whatsapp']!.text.trim();
      body['jenis_hunian'] = _hunian;
      body['anggota'] = [
        for (final (n, h) in _anggota)
          if (n.text.trim().isNotEmpty) {'nama': n.text.trim(), 'hubungan_keluarga': h.text.trim().isEmpty ? 'Anggota keluarga' : h.text.trim()},
      ];
    }
    try {
      final data = await ref.read(apiProvider).post(widget.additional ? '/auth/additional-register' : '/auth/register', data: body);
      // Router (redirect sesi) membawa akun baru ke beranda atau halaman menunggu persetujuan.
      await _finishAuth(ref, data);
    } on ApiException catch (e) {
      setState(() {
        _fieldErrors = e.fields;
        _error = e.fields.isEmpty ? e.display : 'Periksa kembali isian yang ditandai.';
      });
    }
  }

  Widget _field(
    String key,
    String label, {
    String? apiKey,
    TextInputType? type,
    bool obscure = false,
    bool optional = false,
    String? hint,
    int? maxLength,
    List<TextInputFormatter>? fmt,
  }) => Padding(
    padding: const EdgeInsets.only(bottom: 14),
    child: TextFormField(
      controller: c[key],
      keyboardType: type,
      obscureText: obscure,
      maxLength: maxLength,
      inputFormatters: fmt,
      decoration: InputDecoration(labelText: optional ? '$label (opsional)' : label, hintText: hint, errorText: _err(apiKey ?? key), counterText: ''),
      validator: optional ? null : (v) => (v == null || v.trim().isEmpty) ? 'Wajib diisi' : null,
    ),
  );

  @override
  Widget build(BuildContext context) => _AuthScaffold(
    back: true,
    title: widget.additional ? 'Daftar akun tambahan' : 'Daftar akun utama',
    subtitle: widget.additional
        ? 'Untuk anggota keluarga lain di rumah yang sudah terdaftar. Akun aktif setelah disetujui pengurus.'
        : 'Untuk kepala keluarga atau penanggung jawab rumah. Data keluarga akan diverifikasi pengurus.',
    child: Form(
      key: _form,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          _field(
            'token',
            'Token RT (6 digit)',
            apiKey: 'registration_token',
            type: TextInputType.number,
            maxLength: 6,
            fmt: [FilteringTextInputFormatter.digitsOnly],
            hint: 'Diberikan oleh pengurus',
          ),
          _field('email', 'Email', type: TextInputType.emailAddress),
          _field('password', 'Kata sandi (min. 8, huruf & angka)', apiKey: 'password', obscure: true),
          _field('password2', 'Ulangi kata sandi', apiKey: 'password_confirmation', obscure: true),
          const SizedBox(height: 6),
          Text(widget.additional ? 'Data diri' : 'Kepala keluarga', style: Theme.of(context).textTheme.titleMedium),
          const SizedBox(height: 10),
          _field('nama', widget.additional ? 'Nama lengkap' : 'Nama kepala keluarga', apiKey: widget.additional ? 'nama' : 'nama_kepala'),
          if (widget.additional) _field('hubungan', 'Hubungan dengan kepala keluarga', apiKey: 'hubungan_keluarga', hint: 'mis. Anak, Istri'),
          if (!widget.additional) _field('whatsapp', 'Nomor WhatsApp', type: TextInputType.phone, optional: true),
          Text('Alamat rumah', style: Theme.of(context).textTheme.titleMedium),
          const SizedBox(height: 10),
          Row(
            children: [
              Expanded(child: _field('blok', 'Blok', optional: true)),
              const SizedBox(width: 12),
              Expanded(child: _field('nomor', 'Nomor')),
            ],
          ),
          _field('jalan', 'Jalan'),
          if (!widget.additional) ...[
            DropdownButtonFormField<String>(
              initialValue: _hunian,
              decoration: const InputDecoration(labelText: 'Status hunian'),
              items: const [
                DropdownMenuItem(value: 'pemilik', child: Text('Pemilik / keluarga pemilik')),
                DropdownMenuItem(value: 'kontrak', child: Text('Kontrak / sewa')),
              ],
              onChanged: (v) => setState(() => _hunian = v ?? 'pemilik'),
            ),
            const SizedBox(height: 18),
            Row(
              children: [
                Expanded(child: Text('Anggota keluarga', style: Theme.of(context).textTheme.titleMedium)),
                TextButton.icon(
                  onPressed: () => setState(() => _anggota.add((TextEditingController(), TextEditingController()))),
                  icon: const Icon(Icons.add),
                  label: const Text('Tambah'),
                ),
              ],
            ),
            for (final (i, (n, h)) in _anggota.indexed)
              Padding(
                padding: const EdgeInsets.only(bottom: 10),
                child: Row(
                  children: [
                    Expanded(
                      flex: 3,
                      child: TextField(
                        controller: n,
                        decoration: InputDecoration(labelText: 'Nama anggota ${i + 1}'),
                      ),
                    ),
                    const SizedBox(width: 8),
                    Expanded(
                      flex: 2,
                      child: TextField(
                        controller: h,
                        decoration: const InputDecoration(labelText: 'Hubungan'),
                      ),
                    ),
                    IconButton(tooltip: 'Hapus', onPressed: () => setState(() => _anggota.removeAt(i)), icon: const Icon(Icons.close)),
                  ],
                ),
              ),
            const Text('NIK/KTP/KK tidak diminta. Data hanya untuk administrasi RT.', style: TextStyle(color: Brand.muted, fontSize: 13)),
          ],
          if (_error != null)
            Padding(
              padding: const EdgeInsets.only(top: 12),
              child: Text(_error!, style: const TextStyle(color: Brand.danger)),
            ),
          const SizedBox(height: 20),
          BusyButton(label: widget.additional ? 'Kirim permohonan' : 'Daftar', icon: Icons.check, onPressed: _submit),
        ],
      ),
    ),
  );
}

/// Akun tambahan yang menunggu persetujuan pengurus: hanya status permohonan + keluar.
class PendingPage extends ConsumerWidget {
  const PendingPage({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final status = ref.watch(_pendingStatus);
    return _AuthScaffold(
      title: 'Menunggu persetujuan',
      subtitle: 'Permohonan akun tambahan Anda sedang ditinjau pengurus RT. Anda akan mendapat akses setelah disetujui.',
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          AsyncView(
            value: status,
            onRetry: () => ref.invalidate(_pendingStatus),
            builder: (d) {
              final p = (d as Map)['permohonan'] as Map?;
              return SectionCard(
                title: 'Status permohonan',
                trailing: StatusChip(p?['status'] == 'ditolak' ? 'ditolak' : 'menunggu', label: p?['status'] == 'ditolak' ? 'Ditolak' : 'Ditinjau'),
                child: Text(p?['status'] == 'ditolak' ? 'Alasan: ${p?['catatan'] ?? '-'}' : 'Hubungi pengurus bila permohonan belum diproses.'),
              );
            },
          ),
          const SizedBox(height: 16),
          BusyButton(
            label: 'Periksa lagi',
            icon: Icons.refresh,
            outlined: true,
            onPressed: () async {
              final me = await ref.read(apiProvider).get('/auth/me');
              ref.read(sessionProvider.notifier).updateAkun(Akun(Map<String, dynamic>.from(me as Map)));
              ref.invalidate(_pendingStatus);
            },
          ),
          const SizedBox(height: 10),
          TextButton(onPressed: () => ref.read(sessionProvider.notifier).signOut(), child: const Text('Keluar')),
        ],
      ),
    );
  }
}

final _pendingStatus = FutureProvider.autoDispose((ref) => ref.read(apiProvider).get('/auth/additional-account-status'));

class ForgotPasswordPage extends ConsumerStatefulWidget {
  const ForgotPasswordPage({super.key});
  @override
  ConsumerState<ForgotPasswordPage> createState() => _ForgotPasswordPageState();
}

class _ForgotPasswordPageState extends ConsumerState<ForgotPasswordPage> {
  final _email = TextEditingController();
  final _token = TextEditingController();
  final _pw = TextEditingController();
  bool _sent = false;

  @override
  Widget build(BuildContext context) => _AuthScaffold(
    back: true,
    title: 'Lupa kata sandi',
    subtitle: _sent ? 'Masukkan kode reset dari email beserta kata sandi baru.' : 'Kami akan mengirim kode reset ke email jika terdaftar.',
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        TextField(
          controller: _email,
          keyboardType: TextInputType.emailAddress,
          decoration: const InputDecoration(labelText: 'Email'),
        ),
        if (_sent) ...[
          const SizedBox(height: 14),
          TextField(
            controller: _token,
            decoration: const InputDecoration(labelText: 'Kode reset dari email'),
          ),
          const SizedBox(height: 14),
          TextField(
            controller: _pw,
            obscureText: true,
            decoration: const InputDecoration(labelText: 'Kata sandi baru'),
          ),
        ],
        const SizedBox(height: 20),
        BusyButton(
          label: _sent ? 'Simpan kata sandi baru' : 'Kirim kode reset',
          onPressed: () async {
            try {
              if (!_sent) {
                await ref.read(apiProvider).post('/auth/forgot-password', data: {'email': _email.text.trim()});
                setState(() => _sent = true);
              } else {
                await ref
                    .read(apiProvider)
                    .post(
                      '/auth/reset-password',
                      data: {'email': _email.text.trim(), 'token': _token.text.trim(), 'password': _pw.text, 'password_confirmation': _pw.text},
                    );
                if (context.mounted) {
                  showMessage(context, 'Kata sandi diperbarui. Silakan masuk.');
                  Navigator.pop(context);
                }
              }
            } on ApiException catch (e) {
              if (context.mounted) showMessage(context, e.display, error: true);
            }
          },
        ),
      ],
    ),
  );
}
