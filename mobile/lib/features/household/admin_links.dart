import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/theme.dart';
import '../../core/api.dart';
import '../../core/models.dart';
import '../../core/widgets.dart';
import 'admin_edits.dart';
import 'census_providers.dart';
import 'family_actions.dart';
import 'family_page.dart';
import 'paged_list.dart';

/// Daftar keluarga untuk pengurus. Hunian diubah dari halaman rumah.
class FamiliesAdminPage extends ConsumerStatefulWidget {
  const FamiliesAdminPage({super.key});

  @override
  ConsumerState<FamiliesAdminPage> createState() => _FamiliesAdminPageState();
}

class _FamiliesAdminPageState extends ConsumerState<FamiliesAdminPage> {
  String _q = '';
  String _status = '';
  bool _arsip = false;

  FamilyQuery get _query => (q: _q, arsip: _arsip, status: _status, page: 1);

  @override
  Widget build(BuildContext context) {
    final list = ref.watch(familiesProvider(_query));
    return Scaffold(
      appBar: AppBar(title: const Text('Keluarga')),
      body: ListView(
        keyboardDismissBehavior: ScrollViewKeyboardDismissBehavior.onDrag,
        padding: const EdgeInsets.all(16),
        children: [
          TextField(
            decoration: const InputDecoration(prefixIcon: Icon(Icons.search), labelText: 'Cari nama kepala atau anggota'),
            textInputAction: TextInputAction.search,
            onSubmitted: (value) => setState(() => _q = value.trim()),
          ),
          const SizedBox(height: 8),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              for (final choice in const [('Semua', ''), ('Perlu verifikasi', 'belum_verifikasi'), ('Terverifikasi', 'terverifikasi')])
                ChoiceChip(label: Text(choice.$1), selected: _status == choice.$2, onSelected: (_) => setState(() => _status = choice.$2)),
              FilterChip(label: const Text('Arsip'), selected: _arsip, onSelected: (value) => setState(() => _arsip = value)),
              TextButton(
                onPressed: () async {
                  final created = await Navigator.push<bool>(context, MaterialPageRoute(builder: (_) => const FamilyCreatePage()));
                  if (created == true) ref.invalidate(familiesProvider(_query));
                },
                child: const Text('Keluarga baru'),
              ),
            ],
          ),
          const SizedBox(height: 12),
          AsyncView(
            value: list,
            onRetry: () => ref.invalidate(familiesProvider(_query)),
            builder: (page) {
              if (page.rows.isEmpty) {
                return const Padding(
                  padding: EdgeInsets.symmetric(vertical: 24),
                  child: Text(
                    'Tidak ada keluarga pada saringan ini.',
                    textAlign: TextAlign.center,
                    style: TextStyle(color: Brand.muted, height: 1.35),
                  ),
                );
              }
              return AppendedPage(
                key: ValueKey('keluarga|$_q|$_status|$_arsip'),
                first: page,
                load: (n) => ref.read(familiesProvider((q: _q, arsip: _arsip, status: _status, page: n)).future),
                builder: (context, rows) => Column(
                  children: [
                    for (final family in rows)
                      Card(
                        margin: const EdgeInsets.only(bottom: 8),
                        child: InkWell(
                          onTap: () => Navigator.push<void>(context, MaterialPageRoute(builder: (_) => FamilyAdminPage(id: '${family['id']}'))),
                          child: Padding(
                            padding: const EdgeInsets.all(14),
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text('${family['kepala'] ?? 'Belum ada kepala'}', style: const TextStyle(fontWeight: FontWeight.w700, height: 1.25)),
                                const SizedBox(height: 4),
                                Text('${family['jumlah_anggota'] ?? 0} anggota${_houseLine(family)}', style: const TextStyle(color: Brand.muted, height: 1.3)),
                                const SizedBox(height: 6),
                                StatusChip(
                                  family['status'] == 'terverifikasi' ? 'aktif' : 'menunggu',
                                  label: family['status'] == 'terverifikasi' ? 'Terverifikasi' : 'Perlu verifikasi',
                                ),
                              ],
                            ),
                          ),
                        ),
                      ),
                  ],
                ),
              );
            },
          ),
        ],
      ),
    );
  }

  String _houseLine(Json family) {
    final rumah = family['rumah'];
    if (rumah is! Map || rumah['kode_rumah'] == null) return '';
    return ' • ${rumah['kode_rumah']}';
  }
}

class FamilyAdminPage extends ConsumerWidget {
  const FamilyAdminPage({super.key, required this.id});

  final String id;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final detail = ref.watch(familyDetailProvider(id));
    return Scaffold(
      appBar: AppBar(title: const Text('Data keluarga')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          AsyncView(
            value: detail,
            onRetry: () => ref.invalidate(familyDetailProvider(id)),
            builder: (family) => _FamilyBody(family: family, onChanged: () => ref.invalidate(familyDetailProvider(id))),
          ),
        ],
      ),
    );
  }
}

class _FamilyBody extends ConsumerWidget {
  const _FamilyBody({required this.family, required this.onChanged});

  final Json family;
  final VoidCallback onChanged;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final archived = family['archived_at'] != null;
    final members = ((family['anggota'] as List?) ?? const []).whereType<Map>().toList();
    final rumah = family['rumah'] as Map?;
    final stays = ((family['penghunian'] as List?) ?? const []).whereType<Map>().toList();
    final versi = readVersi(family);
    final kepala = members.where((member) => member['is_kepala'] == true).map((member) => '${member['nama']}').firstOrNull;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        HousePlate(
          kode: '${rumah?['kode_rumah'] ?? 'Belum terhubung ke rumah'}',
          alamat: '${rumah?['alamat'] ?? (kepala ?? 'Keluarga')}',
          hunian: hunianLabel(family['jenis_hunian']).isEmpty ? null : hunianLabel(family['jenis_hunian']),
          footer: archived ? 'Keluarga ini diarsipkan.' : null,
        ),
        const SizedBox(height: 16),
        SectionCard(
          title: 'Anggota',
          child: members.isEmpty
              ? const Text('Belum ada anggota tercatat.', style: TextStyle(color: Brand.muted, height: 1.35))
              : Column(
                  children: [
                    for (final member in members)
                      InkWell(
                        onTap: archived
                            ? null
                            : () async {
                                final changed = await Navigator.push<bool>(
                                  context,
                                  MaterialPageRoute(
                                    builder: (_) => ResidentAdminPage(id: '${member['id']}', familyId: '${family['id']}', versi: versi),
                                  ),
                                );
                                if (changed == true) onChanged();
                              },
                        child: Padding(
                          padding: const EdgeInsets.symmetric(vertical: 8),
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text('${member['nama']}', style: const TextStyle(fontWeight: FontWeight.w700, height: 1.25)),
                              Text(
                                [
                                  '${member['hubungan_keluarga'] ?? 'Anggota'}',
                                  if (member['is_kepala'] == true) 'kepala keluarga',
                                  if (member['punya_akun'] == true) 'punya akun',
                                ].join(' • '),
                                style: const TextStyle(color: Brand.muted, height: 1.3),
                              ),
                            ],
                          ),
                        ),
                      ),
                  ],
                ),
        ),
        if (!archived && family['status'] != 'terverifikasi') ...[
          const SizedBox(height: 16),
          BusyButton(
            label: 'Verifikasi data keluarga',
            outlined: true,
            onPressed: () async {
              try {
                if (versi == null) {
                  onChanged();
                  if (context.mounted) showMessage(context, versiMissingMessage);
                  return;
                }
                await ref.read(apiProvider).post('/admin/families/${family['id']}/verify', data: {'versi': versi});
                onChanged();
                if (context.mounted) showMessage(context, 'Keluarga terverifikasi.');
              } on ApiException catch (error) {
                if (staleConflict(error)) onChanged();
                if (context.mounted) showMessage(context, staleConflict(error) ? error.message : error.display, error: !staleConflict(error));
              }
            },
          ),
        ],
        if (!archived) ...[
          const SizedBox(height: 8),
          OutlinedButton(
            onPressed: () async {
              final changed = await Navigator.push<bool>(
                context,
                MaterialPageRoute(
                  builder: (_) => AdminAddMemberPage(familyId: '${family['id']}', versi: versi),
                ),
              );
              if (changed == true) onChanged();
            },
            child: const Text('Tambah anggota', textAlign: TextAlign.center),
          ),
          const SizedBox(height: 8),
          OutlinedButton(
            onPressed: () async {
              final changed = await Navigator.push<bool>(
                context,
                MaterialPageRoute(
                  builder: (_) => TransferPrimaryPage(familyId: '${family['id']}', versi: versi),
                ),
              );
              if (changed == true) onChanged();
            },
            child: const Text('Pindah akun utama', textAlign: TextAlign.center),
          ),
          const SizedBox(height: 8),
          OutlinedButton(
            onPressed: () async {
              final changed = await Navigator.push<bool>(
                context,
                MaterialPageRoute(
                  builder: (_) => ArchiveFamilyPage(id: '${family['id']}', nama: kepala ?? 'keluarga ini'),
                ),
              );
              if (changed == true && context.mounted) Navigator.pop(context);
            },
            child: const Text('Arsipkan keluarga', textAlign: TextAlign.center),
          ),
        ],
        const SizedBox(height: 16),
        SectionCard(
          title: 'Riwayat hunian',
          child: stays.isEmpty
              ? const Text('Belum ada riwayat hunian.', style: TextStyle(color: Brand.muted, height: 1.35))
              : Column(
                  children: [
                    for (final stay in stays)
                      Padding(
                        padding: const EdgeInsets.only(bottom: 10),
                        child: _StayLine(stay: stay),
                      ),
                  ],
                ),
        ),
      ],
    );
  }
}

class _StayLine extends StatelessWidget {
  const _StayLine({required this.stay});

  final Map stay;

  @override
  Widget build(BuildContext context) {
    final rumah = stay['rumah'];
    final kode = rumah is Map ? '${rumah['kode_rumah'] ?? 'Rumah'}' : 'Rumah';
    final start = tanggalHari(stay['mulai_tanggal']);
    final finish = tanggalHari(stay['selesai_tanggal']);
    final current = stay['aktif'] == true || stay['selesai_tanggal'] == null;
    final kind = hunianLabel(stay['jenis_hunian']);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(kode, style: const TextStyle(fontWeight: FontWeight.w700)),
        Text(
          [
            if (kind.isNotEmpty) kind,
            if (start.isNotEmpty) 'sejak $start',
            if (current) 'masih menghuni' else if (finish.isNotEmpty) 'sampai $finish',
          ].join(' • '),
          style: const TextStyle(color: Brand.muted, height: 1.3),
        ),
      ],
    );
  }
}

class ArchiveFamilyPage extends ConsumerStatefulWidget {
  const ArchiveFamilyPage({super.key, required this.id, required this.nama});

  final String id;
  final String nama;

  @override
  ConsumerState<ArchiveFamilyPage> createState() => _ArchiveFamilyPageState();
}

class _ArchiveFamilyPageState extends ConsumerState<ArchiveFamilyPage> {
  final _alasan = TextEditingController();
  var _busy = false;
  String? _error;

  @override
  void dispose() {
    _alasan.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    if (_busy) return;
    final reason = reasonError(_alasan.text);
    if (reason != null) {
      setState(() => _error = reason);
      return;
    }
    final ok = await confirm(
      context,
      title: 'Arsipkan keluarga?',
      message: '${widget.nama} diarsipkan. Histori hunian dan iuran tetap tersimpan.',
      yes: 'Arsipkan',
    );
    if (!ok || !mounted) return;
    setState(() => _busy = true);
    try {
      await ref.read(apiProvider).post('/admin/families/${widget.id}/archive', data: {'alasan': _alasan.text.trim()});
      if (!mounted) return;
      Navigator.pop(context, true);
    } on ApiException catch (error) {
      if (!mounted) return;
      setState(() => _error = error.display);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const Text('Arsip keluarga')),
    body: ListView(
      padding: const EdgeInsets.all(16),
      children: [
        const Text('Keluarga arsip tidak dihapus dari histori.', style: TextStyle(height: 1.35)),
        const SizedBox(height: 12),
        TextField(
          controller: _alasan,
          maxLength: 300,
          minLines: 2,
          maxLines: 4,
          decoration: InputDecoration(labelText: 'Alasan', errorText: _error, errorMaxLines: 4),
        ),
        const SizedBox(height: 16),
        SizedBox(
          width: double.infinity,
          child: FilledButton(
            onPressed: _busy ? null : _save,
            child: Text(_busy ? 'Menyimpan…' : 'Arsipkan keluarga', textAlign: TextAlign.center),
          ),
        ),
      ],
    ),
  );
}

class ResidentAdminPage extends ConsumerWidget {
  const ResidentAdminPage({super.key, required this.id, required this.familyId, required this.versi});

  final String id;
  final String familyId;
  final int? versi;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final resident = ref.watch(residentProvider(id));
    return Scaffold(
      appBar: AppBar(title: const Text('Anggota')),
      body: AsyncView(
        value: resident,
        onRetry: () => ref.invalidate(residentProvider(id)),
        builder: (data) => _ResidentForm(resident: data, familyId: familyId, versi: versi),
      ),
    );
  }
}

class _ResidentForm extends ConsumerStatefulWidget {
  const _ResidentForm({required this.resident, required this.familyId, required this.versi});

  final Json resident;
  final String familyId;
  final int? versi;

  @override
  ConsumerState<_ResidentForm> createState() => _ResidentFormState();
}

class _ResidentFormState extends ConsumerState<_ResidentForm> {
  late final TextEditingController _nama;
  late final TextEditingController _hubungan;
  late final TextEditingController _wa;
  late int? _versi;
  var _busy = false;
  String? _namaError;
  String? _hubunganError;
  String? _waError;
  String? _formError;
  String? _review;

  bool get _kepala => widget.resident['is_kepala'] == true;
  bool get _punyaAkun => widget.resident['punya_akun'] == true;
  bool get _archived => widget.resident['archived_at'] != null;

  @override
  void initState() {
    super.initState();
    _nama = TextEditingController(text: '${widget.resident['nama'] ?? ''}');
    _hubungan = TextEditingController(text: '${widget.resident['hubungan_keluarga'] ?? ''}');
    _wa = TextEditingController(text: '${widget.resident['whatsapp'] ?? ''}');
    _versi = widget.versi ?? familyVersi(widget.resident);
  }

  @override
  void dispose() {
    _nama.dispose();
    _hubungan.dispose();
    _wa.dispose();
    super.dispose();
  }

  Future<void> _keepStale(ApiException error) async {
    setState(() => _formError = conflictKeptMessage(error.message));
    try {
      final data = await ref.read(apiProvider).get('/admin/residents/${widget.resident['id']}');
      if (!mounted || data is! Map) return;
      final fresh = Json.from(data);
      final versi = familyVersi(fresh);
      setState(() {
        if (versi != null) _versi = versi;
        _review = reviewLine(fresh['nama']);
      });
    } catch (_) {}
  }

  Future<void> _save() async {
    if (_busy) return;
    if (_versi == null) {
      setState(() => _formError = versiMissingMessage);
      return;
    }
    final namaError = personNameError(_nama.text);
    final hubunganError = relationshipError(_hubungan.text);
    final waError = whatsappError(_wa.text);
    if (namaError != null || hubunganError != null || waError != null) {
      setState(() {
        _namaError = namaError;
        _hubunganError = hubunganError;
        _waError = waError;
        _formError = null;
      });
      return;
    }
    setState(() => _busy = true);
    try {
      final phone = _wa.text.trim();
      await ref
          .read(apiProvider)
          .patch(
            '/admin/residents/${widget.resident['id']}',
            data: {
              ...memberBody(nama: _nama.text, hubungan: _hubungan.text, versi: _versi),
              'whatsapp': phone.isEmpty ? null : phone,
            },
          );
      if (!mounted) return;
      Navigator.pop(context, true);
    } on ApiException catch (error) {
      if (!mounted) return;
      if (staleConflict(error)) {
        await _keepStale(error);
        return;
      }
      setState(() {
        _namaError = error.fields['nama']?.join(' ');
        _hubunganError = error.fields['hubungan_keluarga']?.join(' ');
        _waError = error.fields['whatsapp']?.join(' ');
        _formError = (_namaError == null && _hubunganError == null && _waError == null) ? error.display : null;
      });
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _makeHead() async {
    final versi = _versi;
    if (_busy || versi == null) return;
    final ok = await confirm(
      context,
      title: 'Jadikan kepala keluarga?',
      message: '${widget.resident['nama']} menjadi kepala keluarga. Hubungan dapat diubah sesudahnya.',
      yes: 'Jadikan kepala',
    );
    if (!ok || !mounted) return;
    setState(() => _busy = true);
    try {
      await ref
          .read(apiProvider)
          .patch(
            '/admin/families/${widget.familyId}',
            data: changeHeadBody(wargaId: '${widget.resident['id']}', versi: versi),
          );
      if (!mounted) return;
      Navigator.pop(context, true);
    } on ApiException catch (error) {
      if (!mounted) return;
      if (staleConflict(error)) {
        await _keepStale(error);
        return;
      }
      setState(() => _formError = error.display);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final history = ((widget.resident['riwayat_keanggotaan'] as List?) ?? const []).whereType<Map>().toList();
    return ListView(
      keyboardDismissBehavior: ScrollViewKeyboardDismissBehavior.onDrag,
      padding: const EdgeInsets.all(16),
      children: [
        if (_archived)
          const Padding(
            padding: EdgeInsets.only(bottom: 12),
            child: Text('Anggota ini sudah diarsipkan.', style: TextStyle(color: Brand.muted, height: 1.35)),
          ),
        TextField(
          controller: _nama,
          readOnly: _archived,
          maxLength: 120,
          decoration: InputDecoration(labelText: 'Nama lengkap', errorText: _namaError, errorMaxLines: 4),
        ),
        const SizedBox(height: 12),
        TextField(
          controller: _hubungan,
          readOnly: _archived,
          maxLength: 40,
          decoration: InputDecoration(labelText: 'Hubungan', errorText: _hubunganError, errorMaxLines: 4),
        ),
        const SizedBox(height: 12),
        TextField(
          controller: _wa,
          readOnly: _archived,
          keyboardType: TextInputType.phone,
          maxLength: 25,
          decoration: InputDecoration(labelText: 'WhatsApp', helperText: 'Boleh dikosongkan.', helperMaxLines: 2, errorText: _waError, errorMaxLines: 4),
        ),
        if (_review != null) ...[const SizedBox(height: 8), Text(_review!, style: const TextStyle(height: 1.35))],
        if (_formError != null) ...[const SizedBox(height: 8), Text(_formError!, style: const TextStyle(color: Brand.danger, height: 1.35))],
        if (!_archived) ...[
          const SizedBox(height: 16),
          SizedBox(
            width: double.infinity,
            child: FilledButton(
              onPressed: _busy ? null : _save,
              child: Text(_busy ? 'Menyimpan…' : 'Simpan perubahan', textAlign: TextAlign.center),
            ),
          ),
          if (!_kepala && _versi != null) ...[
            const SizedBox(height: 8),
            OutlinedButton(
              onPressed: _busy ? null : _makeHead,
              child: const Text('Jadikan kepala keluarga', textAlign: TextAlign.center),
            ),
          ],
          if (_kepala)
            const Padding(
              padding: EdgeInsets.only(top: 8),
              child: Text('Tetapkan kepala lain sebelum mengakhiri atau memindahkan anggota ini.', style: TextStyle(color: Brand.muted, height: 1.35)),
            )
          else ...[
            if (_punyaAkun)
              const Padding(
                padding: EdgeInsets.only(top: 8),
                child: Text(
                  'Akun ikut pindah bersama anggota. Keanggotaan diakhiri setelah akun dipindahkan.',
                  style: TextStyle(color: Brand.muted, height: 1.35),
                ),
              )
            else ...[
              const SizedBox(height: 8),
              OutlinedButton(
                onPressed: _busy
                    ? null
                    : () async {
                        final changed = await Navigator.push<bool>(
                          context,
                          MaterialPageRoute(
                            builder: (_) => EndResidentPage(id: '${widget.resident['id']}', nama: '${widget.resident['nama']}', versi: _versi),
                          ),
                        );
                        if (changed == true && context.mounted) Navigator.pop(context, true);
                      },
                child: const Text('Akhiri keanggotaan', textAlign: TextAlign.center),
              ),
            ],
            const SizedBox(height: 8),
            OutlinedButton(
              onPressed: _busy || _versi == null
                  ? null
                  : () async {
                      final changed = await Navigator.push<bool>(
                        context,
                        MaterialPageRoute(
                          builder: (_) => MoveResidentPage(
                            residentId: '${widget.resident['id']}',
                            familyId: widget.familyId,
                            versi: _versi,
                            nama: '${widget.resident['nama']}',
                          ),
                        ),
                      );
                      if (changed == true && context.mounted) Navigator.pop(context, true);
                    },
              child: const Text('Pindah ke keluarga lain', textAlign: TextAlign.center),
            ),
          ],
        ],
        if (history.isNotEmpty) ...[
          const SizedBox(height: 16),
          Text('Riwayat keanggotaan', style: Theme.of(context).textTheme.titleMedium),
          const SizedBox(height: 8),
          for (final row in history)
            Padding(
              padding: const EdgeInsets.only(bottom: 8),
              child: Text(
                [
                  '${row['hubungan_keluarga'] ?? 'Anggota'}',
                  if (tanggalHari(row['mulai_at']).isNotEmpty) 'sejak ${tanggalHari(row['mulai_at'])}',
                  if (row['selesai_at'] == null) 'masih anggota' else if (tanggalHari(row['selesai_at']).isNotEmpty) 'sampai ${tanggalHari(row['selesai_at'])}',
                ].join(' • '),
                style: const TextStyle(height: 1.35),
              ),
            ),
        ],
      ],
    );
  }
}

class EndResidentPage extends ConsumerStatefulWidget {
  const EndResidentPage({super.key, required this.id, required this.nama, required this.versi});

  final String id;
  final String nama;
  final int? versi;

  @override
  ConsumerState<EndResidentPage> createState() => _EndResidentPageState();
}

class _EndResidentPageState extends ConsumerState<EndResidentPage> {
  final _alasan = TextEditingController();
  String _status = 'pindah';
  late int? _versi;
  var _busy = false;
  String? _error;
  String? _notice;
  String? _review;

  @override
  void initState() {
    super.initState();
    _versi = widget.versi;
  }

  @override
  void dispose() {
    _alasan.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    if (_busy) return;
    final reason = reasonError(_alasan.text);
    if (reason != null || _versi == null) {
      setState(() => _error = reason ?? versiMissingMessage);
      return;
    }
    final label = _status == 'meninggal' ? 'meninggal' : 'pindah';
    final ok = await confirm(context, title: 'Tandai $label?', message: '${widget.nama} ditandai $label. Riwayat tetap tersimpan.', yes: 'Tandai $label');
    if (!ok || !mounted) return;
    setState(() => _busy = true);
    try {
      await ref
          .read(apiProvider)
          .post(
            '/admin/residents/${widget.id}/end',
            data: residentEndBody(status: _status, alasan: _alasan.text, versi: _versi),
          );
      if (!mounted) return;
      Navigator.pop(context, true);
    } on ApiException catch (error) {
      if (!mounted) return;
      if (staleConflict(error)) {
        setState(() {
          _error = null;
          _notice = conflictKeptMessage(error.message);
        });
        try {
          final data = await ref.read(apiProvider).get('/admin/residents/${widget.id}');
          if (mounted && data is Map) {
            final fresh = Json.from(data);
            final versi = familyVersi(fresh);
            setState(() {
              if (versi != null) _versi = versi;
              _review = reviewLine(fresh['nama']);
            });
          }
        } catch (_) {}
        return;
      }
      setState(() => _error = error.display);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const Text('Akhiri keanggotaan')),
    body: ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Text(widget.nama, style: Theme.of(context).textTheme.titleMedium),
        const SizedBox(height: 12),
        Wrap(
          spacing: 8,
          runSpacing: 8,
          children: [
            ChoiceChip(label: const Text('Pindah'), selected: _status == 'pindah', onSelected: (_) => setState(() => _status = 'pindah')),
            ChoiceChip(label: const Text('Meninggal'), selected: _status == 'meninggal', onSelected: (_) => setState(() => _status = 'meninggal')),
          ],
        ),
        const SizedBox(height: 12),
        TextField(
          controller: _alasan,
          maxLength: 300,
          minLines: 2,
          maxLines: 4,
          decoration: InputDecoration(labelText: 'Alasan', errorText: _error, errorMaxLines: 4),
        ),
        if (_review != null) ...[const SizedBox(height: 8), Text(_review!, style: const TextStyle(height: 1.35))],
        if (_notice != null) ...[const SizedBox(height: 8), Text(_notice!, style: const TextStyle(color: Brand.danger, height: 1.35))],
        const SizedBox(height: 16),
        SizedBox(
          width: double.infinity,
          child: FilledButton(
            onPressed: _busy ? null : _save,
            child: Text(_busy ? 'Menyimpan…' : 'Simpan', textAlign: TextAlign.center),
          ),
        ),
      ],
    ),
  );
}

class OccupancyBeginPage extends ConsumerStatefulWidget {
  const OccupancyBeginPage({super.key, required this.rumahId, required this.rumahLabel});

  final String rumahId;
  final String rumahLabel;

  @override
  ConsumerState<OccupancyBeginPage> createState() => _OccupancyBeginPageState();
}

class _OccupancyBeginPageState extends ConsumerState<OccupancyBeginPage> {
  String _q = '';
  String? _keluargaId;
  String? _keluargaLabel;
  String _jenis = 'pemilik';
  final _mulai = TextEditingController();
  var _busy = false;
  String? _error;

  FamilyQuery get _query => (q: _q, arsip: false, status: '', page: 1);

  @override
  void dispose() {
    _mulai.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    if (_busy) return;
    final day = dayError(_mulai.text);
    if (_keluargaId == null) {
      setState(() => _error = 'Pilih keluarga terlebih dahulu.');
      return;
    }
    if (day != null) {
      setState(() => _error = day);
      return;
    }
    setState(() => _busy = true);
    try {
      await ref
          .read(apiProvider)
          .post(
            '/admin/occupancies',
            data: occupancyBeginBody(rumahId: widget.rumahId, keluargaId: _keluargaId!, jenis: _jenis, mulai: _mulai.text),
          );
      if (!mounted) return;
      Navigator.pop(context, true);
    } on ApiException catch (error) {
      if (!mounted) return;
      setState(() => _error = error.display);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final families = ref.watch(familiesProvider(_query));
    return Scaffold(
      appBar: AppBar(title: Text('Hunian ${widget.rumahLabel}')),
      body: ListView(
        keyboardDismissBehavior: ScrollViewKeyboardDismissBehavior.onDrag,
        padding: const EdgeInsets.all(16),
        children: [
          Text('Rumah ${widget.rumahLabel}. Tagihan rumah tidak berubah karena hunian dimulai.', style: const TextStyle(height: 1.35)),
          const SizedBox(height: 12),
          TextField(
            decoration: const InputDecoration(prefixIcon: Icon(Icons.search), labelText: 'Cari keluarga'),
            textInputAction: TextInputAction.search,
            onSubmitted: (value) => setState(() => _q = value.trim()),
          ),
          const SizedBox(height: 8),
          AsyncView(
            value: families,
            onRetry: () => ref.invalidate(familiesProvider(_query)),
            builder: (page) => AppendedPage(
              key: ValueKey('hunian-keluarga|$_q'),
              first: page,
              load: (n) => ref.read(familiesProvider((q: _q, arsip: false, status: '', page: n)).future),
              builder: (context, rows) {
                if (rows.isEmpty) {
                  return const Padding(
                    padding: EdgeInsets.symmetric(vertical: 12),
                    child: Text('Tidak ada keluarga pada pencarian ini.', style: TextStyle(color: Brand.muted, height: 1.35)),
                  );
                }
                return Column(
                  children: [
                    for (final family in rows)
                      Card(
                        margin: const EdgeInsets.only(bottom: 8),
                        color: _keluargaId == family['id'] ? Brand.yellow.withValues(alpha: 0.35) : null,
                        child: InkWell(
                          onTap: _busy
                              ? null
                              : () => setState(() {
                                  _keluargaId = '${family['id']}';
                                  _keluargaLabel = '${family['kepala'] ?? 'Keluarga'}';
                                  _error = null;
                                }),
                          child: Padding(
                            padding: const EdgeInsets.all(14),
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text('${family['kepala'] ?? 'Belum ada kepala'}', style: const TextStyle(fontWeight: FontWeight.w700, height: 1.25)),
                                Text('${family['jumlah_anggota'] ?? 0} anggota', style: const TextStyle(color: Brand.muted, height: 1.3)),
                              ],
                            ),
                          ),
                        ),
                      ),
                  ],
                );
              },
            ),
          ),
          if (_keluargaLabel != null) ...[
            const SizedBox(height: 8),
            Text('Keluarga dipilih: $_keluargaLabel', style: const TextStyle(fontWeight: FontWeight.w700)),
          ],
          const SizedBox(height: 12),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              ChoiceChip(label: const Text('Pemilik'), selected: _jenis == 'pemilik', onSelected: (_) => setState(() => _jenis = 'pemilik')),
              ChoiceChip(label: const Text('Kontrak'), selected: _jenis == 'kontrak', onSelected: (_) => setState(() => _jenis = 'kontrak')),
            ],
          ),
          const SizedBox(height: 12),
          TextField(
            controller: _mulai,
            maxLength: 10,
            decoration: const InputDecoration(
              labelText: 'Tanggal mulai',
              helperText: 'Kosongkan untuk memakai hari ini. Contoh 2026-10-13.',
              helperMaxLines: 3,
            ),
          ),
          if (_error != null) ...[const SizedBox(height: 8), Text(_error!, style: const TextStyle(color: Brand.danger, height: 1.35))],
          const SizedBox(height: 16),
          SizedBox(
            width: double.infinity,
            child: FilledButton(
              onPressed: _busy ? null : _save,
              child: Text(_busy ? 'Menyimpan…' : 'Mulai hunian', textAlign: TextAlign.center),
            ),
          ),
        ],
      ),
    );
  }
}

class OccupancyEndPage extends ConsumerStatefulWidget {
  const OccupancyEndPage({super.key, required this.occupancyId});

  final String occupancyId;

  @override
  ConsumerState<OccupancyEndPage> createState() => _OccupancyEndPageState();
}

class _OccupancyEndPageState extends ConsumerState<OccupancyEndPage> {
  final _alasan = TextEditingController();
  final _selesai = TextEditingController();
  var _busy = false;
  String? _error;

  @override
  void dispose() {
    _alasan.dispose();
    _selesai.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    if (_busy) return;
    final reason = reasonError(_alasan.text);
    final day = dayError(_selesai.text);
    if (reason != null || day != null) {
      setState(() => _error = reason ?? day);
      return;
    }
    final ok = await confirm(
      context,
      title: 'Akhiri hunian?',
      message: 'Keluarga tidak lagi tercatat di rumah ini. Tagihan yang sudah terbit tetap di rumah.',
      yes: 'Akhiri hunian',
    );
    if (!ok || !mounted) return;
    setState(() => _busy = true);
    try {
      await ref
          .read(apiProvider)
          .post(
            '/admin/occupancies/${widget.occupancyId}/end',
            data: occupancyEndBody(alasan: _alasan.text, selesai: _selesai.text),
          );
      if (!mounted) return;
      Navigator.pop(context, true);
    } on ApiException catch (error) {
      if (!mounted) return;
      setState(() => _error = error.display);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const Text('Akhiri hunian')),
    body: ListView(
      padding: const EdgeInsets.all(16),
      children: [
        TextField(
          controller: _selesai,
          maxLength: 10,
          decoration: const InputDecoration(labelText: 'Tanggal selesai', helperText: 'Kosongkan untuk memakai hari ini.', helperMaxLines: 2),
        ),
        const SizedBox(height: 8),
        TextField(
          controller: _alasan,
          maxLength: 300,
          minLines: 2,
          maxLines: 4,
          decoration: const InputDecoration(labelText: 'Alasan'),
        ),
        if (_error != null) ...[const SizedBox(height: 8), Text(_error!, style: const TextStyle(color: Brand.danger, height: 1.35))],
        const SizedBox(height: 16),
        SizedBox(
          width: double.infinity,
          child: FilledButton(
            onPressed: _busy ? null : _save,
            child: Text(_busy ? 'Menyimpan…' : 'Akhiri hunian', textAlign: TextAlign.center),
          ),
        ),
      ],
    ),
  );
}

class OccupancyMovePage extends ConsumerStatefulWidget {
  const OccupancyMovePage({super.key, required this.occupancyId, required this.currentHouseId});

  final String occupancyId;
  final String currentHouseId;

  @override
  ConsumerState<OccupancyMovePage> createState() => _OccupancyMovePageState();
}

class _OccupancyMovePageState extends ConsumerState<OccupancyMovePage> {
  String _q = '';
  String? _rumahId;
  String? _rumahLabel;
  String? _jenis;
  final _tanggal = TextEditingController();
  final _alasan = TextEditingController();
  var _busy = false;
  String? _error;

  HouseQuery get _query => (q: _q, arsip: false, page: 1);

  @override
  void dispose() {
    _tanggal.dispose();
    _alasan.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    if (_busy) return;
    final reason = reasonError(_alasan.text);
    final day = dayError(_tanggal.text);
    if (_rumahId == null) {
      setState(() => _error = 'Pilih rumah tujuan.');
      return;
    }
    if (reason != null || day != null) {
      setState(() => _error = reason ?? day);
      return;
    }
    final ok = await confirm(
      context,
      title: 'Pindah rumah?',
      message: 'Hunian lama ditutup dan hunian baru dimulai pada tanggal yang sama. Tagihan rumah lama tetap di rumah lama.',
      yes: 'Pindah rumah',
    );
    if (!ok || !mounted) return;
    setState(() => _busy = true);
    try {
      await ref
          .read(apiProvider)
          .post(
            '/admin/occupancies/${widget.occupancyId}/move',
            data: occupancyMoveBody(rumahId: _rumahId!, alasan: _alasan.text, jenis: _jenis, tanggal: _tanggal.text),
          );
      if (!mounted) return;
      Navigator.pop(context, true);
    } on ApiException catch (error) {
      if (!mounted) return;
      setState(() => _error = error.display);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final houses = ref.watch(housesProvider(_query));
    return Scaffold(
      appBar: AppBar(title: const Text('Pindah rumah')),
      body: ListView(
        keyboardDismissBehavior: ScrollViewKeyboardDismissBehavior.onDrag,
        padding: const EdgeInsets.all(16),
        children: [
          const Text('Tagihan dan tunggakan tidak ikut pindah.', style: TextStyle(height: 1.35)),
          const SizedBox(height: 12),
          TextField(
            decoration: const InputDecoration(prefixIcon: Icon(Icons.search), labelText: 'Cari rumah tujuan'),
            textInputAction: TextInputAction.search,
            onSubmitted: (value) => setState(() => _q = value.trim()),
          ),
          const SizedBox(height: 8),
          AsyncView(
            value: houses,
            onRetry: () => ref.invalidate(housesProvider(_query)),
            builder: (page) => AppendedPage(
              key: ValueKey('pindah-rumah|$_q'),
              first: page,
              load: (n) => ref.read(housesProvider((q: _q, arsip: false, page: n)).future),
              builder: (context, rows) {
                final choices = rows.where((house) => '${house['id']}' != widget.currentHouseId && house['archived_at'] == null).toList();
                if (choices.isEmpty) {
                  return const Padding(
                    padding: EdgeInsets.symmetric(vertical: 12),
                    child: Text('Tidak ada rumah lain pada pencarian ini.', style: TextStyle(color: Brand.muted, height: 1.35)),
                  );
                }
                return Column(
                  children: [
                    for (final house in choices)
                      Card(
                        margin: const EdgeInsets.only(bottom: 8),
                        color: _rumahId == house['id'] ? Brand.yellow.withValues(alpha: 0.35) : null,
                        child: InkWell(
                          onTap: _busy
                              ? null
                              : () => setState(() {
                                  _rumahId = '${house['id']}';
                                  _rumahLabel = '${house['kode_rumah']}';
                                  _error = null;
                                }),
                          child: Padding(
                            padding: const EdgeInsets.all(14),
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text('${house['kode_rumah']}', style: const TextStyle(fontWeight: FontWeight.w700, height: 1.25)),
                                Text('${house['alamat'] ?? ''}', style: const TextStyle(color: Brand.muted, height: 1.3)),
                              ],
                            ),
                          ),
                        ),
                      ),
                  ],
                );
              },
            ),
          ),
          if (_rumahLabel != null) ...[const SizedBox(height: 8), Text('Tujuan: $_rumahLabel', style: const TextStyle(fontWeight: FontWeight.w700))],
          const SizedBox(height: 12),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              ChoiceChip(label: const Text('Jenis tetap'), selected: _jenis == null, onSelected: (_) => setState(() => _jenis = null)),
              ChoiceChip(label: const Text('Pemilik'), selected: _jenis == 'pemilik', onSelected: (_) => setState(() => _jenis = 'pemilik')),
              ChoiceChip(label: const Text('Kontrak'), selected: _jenis == 'kontrak', onSelected: (_) => setState(() => _jenis = 'kontrak')),
            ],
          ),
          const SizedBox(height: 12),
          TextField(
            controller: _tanggal,
            maxLength: 10,
            decoration: const InputDecoration(labelText: 'Tanggal pindah', helperText: 'Kosongkan untuk memakai hari ini.', helperMaxLines: 2),
          ),
          TextField(
            controller: _alasan,
            maxLength: 300,
            minLines: 2,
            maxLines: 4,
            decoration: const InputDecoration(labelText: 'Alasan'),
          ),
          if (_error != null) ...[const SizedBox(height: 8), Text(_error!, style: const TextStyle(color: Brand.danger, height: 1.35))],
          const SizedBox(height: 16),
          SizedBox(
            width: double.infinity,
            child: FilledButton(
              onPressed: _busy ? null : _save,
              child: Text(_busy ? 'Menyimpan…' : 'Pindah rumah', textAlign: TextAlign.center),
            ),
          ),
        ],
      ),
    );
  }
}
