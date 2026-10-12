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
import 'account_access.dart';

Future<void> _finishAuth(WidgetRef ref, dynamic data) async {
  final map = data as Map;
  await ref.read(sessionProvider.notifier).signIn('${map['token']}', Akun(Map<String, dynamic>.from(map['akun'] as Map)));
}

class _AuthScaffold extends StatelessWidget {
  const _AuthScaffold({required this.title, required this.subtitle, required this.child, this.back = false, this.allowBack = true});
  final String title;
  final String subtitle;
  final Widget child;
  final bool back;
  final bool allowBack;

  @override
  Widget build(BuildContext context) => PopScope(
    canPop: !back || allowBack,
    child: Scaffold(
      backgroundColor: Brand.sky,
      appBar: back
          ? AppBar(
              backgroundColor: Brand.sky,
              foregroundColor: Brand.navy,
              automaticallyImplyLeading: false,
              leading: allowBack
                  ? const BackButton(key: ValueKey('auth-back'))
                  : const IconButton(key: ValueKey('auth-back'), tooltip: 'Kembali', onPressed: null, icon: BackButtonIcon()),
            )
          : null,
      body: SafeArea(
        child: ListView(
          keyboardDismissBehavior: ScrollViewKeyboardDismissBehavior.onDrag,
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
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _email.dispose();
    _password.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (_busy) return;
    FocusManager.instance.primaryFocus?.unfocus();
    setState(() {
      _busy = true;
      _error = null;
    });
    if (!_form.currentState!.validate()) {
      if (mounted) setState(() => _busy = false);
      return;
    }
    try {
      final data = await ref
          .read(apiProvider)
          .post('/auth/mobile-login', data: {'email': _email.text.trim(), 'password': _password.text, 'device_name': 'Android RT05'});
      if (!mounted) return;
      await _finishAuth(ref, data);
    } on ApiException catch (error) {
      if (!mounted) return;
      setState(() => _error = error.display);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final expired = ref.watch(sessionProvider).expired;
    return _AuthScaffold(
      title: 'Masuk',
      subtitle: 'Email dan kata sandi aplikasi RT05 TAKEDA. Tidak memakai akun Google.',
      child: Form(
        key: _form,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            if (expired) const _Banner('Sesi berakhir. Silakan masuk kembali.'),
            TextFormField(
              key: const ValueKey('field-login-email'),
              controller: _email,
              keyboardType: TextInputType.emailAddress,
              autofillHints: const [AutofillHints.email],
              decoration: const InputDecoration(labelText: 'Email', prefixIcon: Icon(Icons.mail_outline)),
              validator: validateEmailAddress,
            ),
            const SizedBox(height: 14),
            TextFormField(
              key: const ValueKey('field-login-password'),
              controller: _password,
              obscureText: _hide,
              autofillHints: const [AutofillHints.password],
              decoration: InputDecoration(
                labelText: 'Kata sandi aplikasi',
                prefixIcon: const Icon(Icons.lock_outline),
                suffixIcon: IconButton(
                  tooltip: _hide ? 'Tampilkan' : 'Sembunyikan',
                  icon: Icon(_hide ? Icons.visibility : Icons.visibility_off),
                  onPressed: () => setState(() => _hide = !_hide),
                ),
              ),
              validator: (value) => (value == null || value.isEmpty) ? 'Masukkan kata sandi' : null,
              textInputAction: TextInputAction.done,
              onFieldSubmitted: (_) => _submit(),
            ),
            if (_error != null)
              Padding(
                padding: const EdgeInsets.only(top: 12),
                child: Text(_error!, style: const TextStyle(color: Brand.danger)),
              ),
            const SizedBox(height: 20),
            BusyButton(label: 'Masuk', icon: Icons.login, onPressed: _busy ? null : _submit),
            TextButton(onPressed: _busy ? null : () => context.push('/forgot'), child: const Text('Lupa kata sandi?')),
            const Divider(height: 32),
            const Text(
              'Belum punya akun? Minta token RT 6 digit kepada pengurus.',
              textAlign: TextAlign.center,
              style: TextStyle(color: Brand.muted),
            ),
            const SizedBox(height: 12),
            _AuthLink(icon: Icons.home_work_outlined, label: 'Daftar akun utama rumah', onPressed: _busy ? null : () => context.push('/register')),
            const SizedBox(height: 10),
            _AuthLink(
              icon: Icons.group_add_outlined,
              label: 'Daftar akun tambahan keluarga',
              onPressed: _busy ? null : () => context.push('/register?jenis=tambahan'),
            ),
            const SizedBox(height: 24),
            const ServerInfo(),
          ],
        ),
      ),
    );
  }
}

/// Tombol sekunder auth: label panjang membungkus pada skala teks 1,3 dan 2,0.
class _AuthLink extends StatelessWidget {
  const _AuthLink({required this.icon, required this.label, required this.onPressed});

  final IconData icon;
  final String label;
  final VoidCallback? onPressed;

  @override
  Widget build(BuildContext context) => OutlinedButton(
    onPressed: onPressed,
    child: Row(
      mainAxisAlignment: MainAxisAlignment.center,
      children: [
        Icon(icon),
        const SizedBox(width: 8),
        Flexible(child: Text(label, textAlign: TextAlign.center)),
      ],
    ),
  );
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
    for (final key in ['email', 'password', 'password2', 'token', 'nama', 'hubungan', 'whatsapp', 'blok', 'jalan', 'nomor']) key: TextEditingController(),
  };
  final List<(TextEditingController, TextEditingController)> _anggota = [];
  String _hunian = 'pemilik';
  Map<String, List<String>> _fieldErrors = {};
  String? _error;
  bool _busy = false;

  @override
  void dispose() {
    for (final controller in c.values) {
      controller.dispose();
    }
    for (final (nama, hubungan) in _anggota) {
      nama.dispose();
      hubungan.dispose();
    }
    super.dispose();
  }

  String? _err(String key) => _fieldErrors[key]?.first;

  void _removeMember(int index) {
    final (nama, hubungan) = _anggota.removeAt(index);
    setState(() {});
    WidgetsBinding.instance.addPostFrameCallback((_) {
      nama.dispose();
      hubungan.dispose();
    });
  }

  Future<void> _submit() async {
    if (_busy) return;
    FocusManager.instance.primaryFocus?.unfocus();
    setState(() {
      _busy = true;
      _error = null;
      _fieldErrors = {};
    });
    if (!_form.currentState!.validate()) {
      if (mounted) setState(() => _busy = false);
      return;
    }
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
        for (final (nama, hubungan) in _anggota)
          if (nama.text.trim().isNotEmpty || hubungan.text.trim().isNotEmpty) {'nama': nama.text.trim(), 'hubungan_keluarga': hubungan.text.trim()},
      ];
    }
    try {
      final data = await ref.read(apiProvider).post(widget.additional ? '/auth/additional-register' : '/auth/register', data: body);
      if (!mounted) return;
      await _finishAuth(ref, data);
    } on ApiException catch (error) {
      if (!mounted) return;
      setState(() {
        _fieldErrors = error.fields;
        _error = error.fields.isEmpty ? error.display : 'Periksa kembali isian yang ditandai.';
      });
    } finally {
      if (mounted) setState(() => _busy = false);
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
    String? Function(String?)? validate,
  }) => Padding(
    padding: const EdgeInsets.only(bottom: 14),
    child: TextFormField(
      key: ValueKey('field-$key'),
      controller: c[key],
      keyboardType: type,
      obscureText: obscure,
      maxLength: maxLength,
      inputFormatters: fmt,
      decoration: InputDecoration(labelText: optional ? '$label (opsional)' : label, hintText: hint, errorText: _err(apiKey ?? key), counterText: ''),
      validator: (value) {
        final text = value?.trim() ?? '';
        if (optional && text.isEmpty) return null;
        if (!optional && text.isEmpty) return 'Wajib diisi';
        return validate?.call(value);
      },
    ),
  );

  @override
  Widget build(BuildContext context) => _AuthScaffold(
    back: true,
    allowBack: !_busy,
    title: widget.additional ? 'Daftar akun tambahan' : 'Daftar akun utama',
    subtitle: widget.additional
        ? 'Untuk anggota keluarga lain di rumah yang sudah terdaftar. Akun menunggu persetujuan pengurus dan belum mendapat akses layanan.'
        : 'Akun utama langsung aktif dengan kata sandi aplikasi. Keluarga berstatus belum diverifikasi sampai pengurus meninjau.',
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
            validate: validateRegistrationToken,
          ),
          _field('email', 'Email', type: TextInputType.emailAddress, validate: validateEmailAddress),
          _field('password', 'Kata sandi aplikasi (min. 8, huruf dan angka)', apiKey: 'password', obscure: true, validate: validateNewPassword),
          _field(
            'password2',
            'Ulangi kata sandi',
            apiKey: 'password_confirmation',
            obscure: true,
            validate: (value) => validatePasswordConfirmation(value, c['password']!.text),
          ),
          const SizedBox(height: 6),
          Text(widget.additional ? 'Data diri' : 'Kepala keluarga', style: Theme.of(context).textTheme.titleMedium),
          const SizedBox(height: 10),
          _field('nama', widget.additional ? 'Nama lengkap' : 'Nama kepala keluarga', apiKey: widget.additional ? 'nama' : 'nama_kepala', maxLength: 120),
          if (widget.additional) _field('hubungan', 'Hubungan dengan kepala keluarga', apiKey: 'hubungan_keluarga', hint: 'mis. Anak, Istri', maxLength: 40),
          if (!widget.additional) _field('whatsapp', 'Nomor WhatsApp', type: TextInputType.phone, optional: true, maxLength: 25, validate: validateWhatsapp),
          Text('Alamat rumah', style: Theme.of(context).textTheme.titleMedium),
          const SizedBox(height: 10),
          LayoutBuilder(
            builder: (context, constraints) {
              final stacked = constraints.maxWidth < 280;
              final blok = _field('blok', 'Blok', optional: true, maxLength: 30);
              final nomor = _field('nomor', 'Nomor', maxLength: 20);
              if (stacked) {
                return Column(children: [blok, nomor]);
              }
              return Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Expanded(child: blok),
                  const SizedBox(width: 12),
                  Expanded(child: nomor),
                ],
              );
            },
          ),
          _field('jalan', 'Jalan', maxLength: 150),
          if (!widget.additional) ...[
            DropdownButtonFormField<String>(
              key: const ValueKey('field-hunian'),
              initialValue: _hunian,
              isExpanded: true,
              decoration: const InputDecoration(labelText: 'Status hunian'),
              items: const [
                DropdownMenuItem(value: 'pemilik', child: Text('Pemilik / keluarga pemilik')),
                DropdownMenuItem(value: 'kontrak', child: Text('Kontrak / sewa')),
              ],
              onChanged: _busy ? null : (value) => setState(() => _hunian = value ?? 'pemilik'),
            ),
            const SizedBox(height: 18),
            Wrap(
              crossAxisAlignment: WrapCrossAlignment.center,
              spacing: 8,
              children: [
                Text('Anggota keluarga', style: Theme.of(context).textTheme.titleMedium),
                TextButton.icon(
                  onPressed: _busy || _anggota.length >= 15 ? null : () => setState(() => _anggota.add((TextEditingController(), TextEditingController()))),
                  icon: const Icon(Icons.add),
                  label: const Text('Tambah'),
                ),
              ],
            ),
            for (final (index, (nama, hubungan)) in _anggota.indexed)
              Padding(
                padding: const EdgeInsets.only(bottom: 10),
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Expanded(
                      flex: 3,
                      child: TextFormField(
                        key: ValueKey('anggota-$index-nama'),
                        controller: nama,
                        decoration: InputDecoration(labelText: 'Nama anggota ${index + 1}', errorText: _err('anggota.$index.nama')),
                        validator: (value) {
                          final other = hubungan.text.trim();
                          final mine = value?.trim() ?? '';
                          if (mine.isEmpty && other.isEmpty) return null;
                          if (mine.isEmpty) return 'Wajib diisi';
                          return null;
                        },
                      ),
                    ),
                    const SizedBox(width: 8),
                    Expanded(
                      flex: 2,
                      child: TextFormField(
                        key: ValueKey('anggota-$index-hubungan'),
                        controller: hubungan,
                        decoration: InputDecoration(labelText: 'Hubungan', errorText: _err('anggota.$index.hubungan_keluarga')),
                        validator: (value) {
                          final other = nama.text.trim();
                          final mine = value?.trim() ?? '';
                          if (mine.isEmpty && other.isEmpty) return null;
                          if (mine.isEmpty) return 'Wajib diisi';
                          return null;
                        },
                      ),
                    ),
                    IconButton(tooltip: 'Hapus', onPressed: _busy ? null : () => _removeMember(index), icon: const Icon(Icons.close)),
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
          BusyButton(label: widget.additional ? 'Kirim permohonan' : 'Daftar', icon: Icons.check, onPressed: _busy ? null : _submit),
        ],
      ),
    ),
  );
}

/// Akun tambahan yang menunggu atau ditolak: hanya status permohonan dan keluar.
class PendingPage extends ConsumerStatefulWidget {
  const PendingPage({super.key});

  @override
  ConsumerState<PendingPage> createState() => _PendingPageState();
}

class _PendingPageState extends ConsumerState<PendingPage> {
  bool _busy = false;

  Future<void> _refresh() async {
    if (_busy) return;
    setState(() => _busy = true);
    final epoch = ref.read(sessionProvider.notifier).epoch;
    try {
      final me = await ref.read(apiProvider).get('/auth/me');
      if (!mounted) return;
      final akun = Akun(Map<String, dynamic>.from(me as Map));
      if (akun.status == 'nonaktif' || akun.status == 'diarsipkan') {
        showMessage(context, 'Akun tidak aktif.', error: true);
        return;
      }
      ref.read(sessionProvider.notifier).updateAkun(akun, epoch: epoch);
      ref.invalidate(_pendingStatus);
    } on ApiException catch (error) {
      if (!mounted) return;
      showMessage(context, error.display, error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _logout() async {
    if (_busy) return;
    setState(() => _busy = true);
    try {
      await revokeServerSession(ref, path: '/auth/logout');
    } on ApiException catch (error) {
      if (!mounted) return;
      showMessage(context, keptSessionMessage(error), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final status = ref.watch(_pendingStatus);
    final payload = status.asData?.value;
    final permohonan = payload is Map ? payload['permohonan'] as Map? : null;
    final rejected = permohonan?['status'] == 'ditolak';
    final alasan = '${permohonan?['catatan'] ?? '-'}';
    return _AuthScaffold(
      title: rejected ? 'Permohonan ditolak' : 'Menunggu persetujuan',
      subtitle: rejected
          ? 'Permohonan akun tambahan ditolak. Alasan: $alasan'
          : 'Permohonan akun tambahan sedang ditinjau pengurus RT. Akses layanan terbuka setelah disetujui.',
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          AsyncView(
            value: status,
            onRetry: () => ref.invalidate(_pendingStatus),
            builder: (_) => SectionCard(
              title: 'Status permohonan',
              trailing: StatusChip(rejected ? 'ditolak' : 'menunggu', label: rejected ? 'Ditolak' : 'Ditinjau'),
              child: Text(
                rejected
                    ? 'Alasan: $alasan\nAkun yang ditolak tidak mendapat akses layanan. Hubungi pengurus bila perlu mengajukan kembali.'
                    : 'Hubungi pengurus bila permohonan belum diproses.',
              ),
            ),
          ),
          const SizedBox(height: 16),
          BusyButton(label: 'Periksa lagi', icon: Icons.refresh, outlined: true, onPressed: _busy ? null : _refresh),
          const SizedBox(height: 10),
          BusyButton(label: 'Keluar', outlined: true, onPressed: _busy ? null : _logout),
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
  final _form = GlobalKey<FormState>();
  final _email = TextEditingController();
  final _token = TextEditingController();
  final _password = TextEditingController();
  final _password2 = TextEditingController();
  bool _sent = false;
  bool _busy = false;
  String? _notice;
  Map<String, List<String>> _fieldErrors = {};
  String? _error;

  @override
  void dispose() {
    _email.dispose();
    _token.dispose();
    _password.dispose();
    _password2.dispose();
    super.dispose();
  }

  String? _err(String key) => _fieldErrors[key]?.first;

  Future<void> _copyCode() async {
    final code = _token.text.trim();
    if (code.isEmpty) return;
    await Clipboard.setData(ClipboardData(text: code));
    if (!mounted) return;
    showMessage(context, 'Kode disalin.');
  }

  Future<void> _submit() async {
    if (_busy) return;
    FocusManager.instance.primaryFocus?.unfocus();
    setState(() {
      _busy = true;
      _error = null;
      _fieldErrors = {};
    });
    if (!_form.currentState!.validate()) {
      if (mounted) setState(() => _busy = false);
      return;
    }
    try {
      if (!_sent) {
        final data = await ref.read(apiProvider).post('/auth/forgot-password', data: {'email': _email.text.trim()});
        if (!mounted) return;
        setState(() {
          _sent = true;
          _notice = forgotSuccessText(data);
        });
      } else {
        await ref
            .read(apiProvider)
            .post(
              '/auth/reset-password',
              data: {'email': _email.text.trim(), 'token': _token.text.trim(), 'password': _password.text, 'password_confirmation': _password2.text},
            );
        if (!mounted) return;
        showMessage(context, 'Kata sandi berhasil direset. Silakan masuk kembali.');
        context.pop();
      }
    } on ApiException catch (error) {
      if (!mounted) return;
      setState(() {
        _fieldErrors = error.fields;
        _error = error.fields.isEmpty ? error.display : 'Periksa kembali isian yang ditandai.';
      });
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => _AuthScaffold(
    back: true,
    title: 'Lupa kata sandi',
    subtitle: _sent
        ? '${_notice ?? forgotQueuedMessage} Salin kode dari email ke kolom di bawah. Pesan ini tidak menyatakan bahwa email tersebut ada.'
        : 'Server mengantrekan email reset bila alamat terdaftar. Aplikasi tidak diberi tahu apakah email itu ada.',
    child: Form(
      key: _form,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          TextFormField(
            key: const ValueKey('field-forgot-email'),
            controller: _email,
            keyboardType: TextInputType.emailAddress,
            readOnly: _sent,
            decoration: InputDecoration(labelText: 'Email', errorText: _err('email')),
            validator: validateEmailAddress,
          ),
          if (_sent) ...[
            const SizedBox(height: 14),
            TextFormField(
              key: const ValueKey('field-forgot-token'),
              controller: _token,
              onChanged: (_) => setState(() {}),
              decoration: InputDecoration(labelText: 'Kode reset dari email', errorText: _err('token')),
              validator: (value) => (value == null || value.trim().isEmpty) ? 'Masukkan kode reset.' : null,
            ),
            Align(
              alignment: Alignment.centerLeft,
              child: TextButton.icon(
                onPressed: _token.text.trim().isEmpty || _busy ? null : _copyCode,
                icon: const Icon(Icons.copy),
                label: const Text('Salin kode'),
              ),
            ),
            TextFormField(
              key: const ValueKey('field-forgot-password'),
              controller: _password,
              obscureText: true,
              decoration: InputDecoration(labelText: 'Kata sandi baru (min. 8, huruf dan angka)', errorText: _err('password')),
              validator: validateNewPassword,
            ),
            const SizedBox(height: 14),
            TextFormField(
              key: const ValueKey('field-forgot-password2'),
              controller: _password2,
              obscureText: true,
              decoration: InputDecoration(labelText: 'Ulangi kata sandi baru', errorText: _err('password_confirmation')),
              validator: (value) => validatePasswordConfirmation(value, _password.text),
            ),
          ],
          if (_error != null)
            Padding(
              padding: const EdgeInsets.only(top: 12),
              child: Text(_error!, style: const TextStyle(color: Brand.danger)),
            ),
          const SizedBox(height: 20),
          BusyButton(label: _sent ? 'Simpan kata sandi baru' : 'Kirim kode reset', onPressed: _busy ? null : _submit),
        ],
      ),
    ),
  );
}
