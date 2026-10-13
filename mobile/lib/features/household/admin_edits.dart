import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/theme.dart';
import '../../core/api.dart';
import '../../core/models.dart';
import '../../core/widgets.dart';
import 'census_providers.dart';
import 'family_actions.dart';
import 'paged_list.dart';

Future<Json?> _read(WidgetRef ref, String path) async {
  try {
    final data = await ref.read(apiProvider).get(path);
    if (data is Map) return Json.from(data);
  } catch (_) {}
  return null;
}

class _Note extends StatelessWidget {
  const _Note({this.review, this.message});

  final String? review;
  final String? message;

  @override
  Widget build(BuildContext context) {
    if (review == null && message == null) return const SizedBox.shrink();
    return Padding(
      padding: const EdgeInsets.only(top: 8),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          if (review != null) Text(review!, style: const TextStyle(height: 1.35)),
          if (message != null) ...[if (review != null) const SizedBox(height: 6), Text(message!, style: const TextStyle(color: Brand.danger, height: 1.35))],
        ],
      ),
    );
  }
}

class _SaveBar extends StatelessWidget {
  const _SaveBar({required this.busy, required this.label, required this.onPressed});

  final bool busy;
  final String label;
  final VoidCallback? onPressed;

  @override
  Widget build(BuildContext context) => SizedBox(
    width: double.infinity,
    child: FilledButton(
      onPressed: busy ? null : onPressed,
      child: Text(busy ? 'Menyimpan…' : label, textAlign: TextAlign.center),
    ),
  );
}

/// Keluarga baru oleh pengurus. Hunian opsional dibuat bersama keluarga.
class FamilyCreatePage extends ConsumerStatefulWidget {
  const FamilyCreatePage({super.key});

  @override
  ConsumerState<FamilyCreatePage> createState() => _FamilyCreatePageState();
}

class _FamilyCreatePageState extends ConsumerState<FamilyCreatePage> {
  final _nama = TextEditingController();
  final _hubungan = TextEditingController();
  final _wa = TextEditingController();
  final _extraNama = TextEditingController();
  final _extraHubungan = TextEditingController();
  final _extraWa = TextEditingController();
  final _mulai = TextEditingController();
  var _extra = false;
  var _link = false;
  var _jenis = 'pemilik';
  String _q = '';
  String? _rumahId;
  String? _rumahLabel;
  var _busy = false;
  String? _namaError;
  String? _waError;
  String? _extraNamaError;
  String? _extraHubunganError;
  String? _extraWaError;
  String? _dayError;
  String? _formError;

  HouseQuery get _houses => (q: _q, arsip: false, page: 1);

  @override
  void dispose() {
    _nama.dispose();
    _hubungan.dispose();
    _wa.dispose();
    _extraNama.dispose();
    _extraHubungan.dispose();
    _extraWa.dispose();
    _mulai.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    if (_busy) return;
    final namaError = personNameError(_nama.text, label: 'Nama kepala');
    final waError = whatsappError(_wa.text);
    final relation = relationshipError(_hubungan.text);
    final hubunganError = _hubungan.text.trim().isEmpty ? null : relation;
    final extraNamaError = _extra ? personNameError(_extraNama.text) : null;
    final extraHubunganError = _extra ? relationshipError(_extraHubungan.text) : null;
    final extraWaError = _extra ? whatsappError(_extraWa.text) : null;
    final day = _link ? dayError(_mulai.text) : null;
    if (_link && _rumahId == null) {
      setState(() => _formError = 'Pilih rumah bila keluarga langsung dihubungkan.');
      return;
    }
    if (namaError != null ||
        waError != null ||
        hubunganError != null ||
        extraNamaError != null ||
        extraHubunganError != null ||
        extraWaError != null ||
        day != null) {
      setState(() {
        _namaError = namaError;
        _waError = waError;
        _extraNamaError = extraNamaError;
        _extraHubunganError = extraHubunganError;
        _extraWaError = extraWaError;
        _dayError = day;
        _formError = hubunganError;
      });
      return;
    }
    setState(() => _busy = true);
    try {
      final anggota = _extra ? [memberBody(nama: _extraNama.text, hubungan: _extraHubungan.text, whatsapp: _extraWa.text)] : const <Map<String, dynamic>>[];
      await ref
          .read(apiProvider)
          .post(
            '/admin/families',
            data: familyCreateBody(
              namaKepala: _nama.text,
              hubunganKepala: _hubungan.text,
              whatsapp: _wa.text,
              rumahId: _link ? _rumahId : null,
              jenis: _link ? _jenis : null,
              mulai: _link ? _mulai.text : null,
              anggota: anggota,
            ),
          );
      if (!mounted) return;
      Navigator.pop(context, true);
    } on ApiException catch (error) {
      if (!mounted) return;
      setState(() => _formError = error.display);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const Text('Keluarga baru')),
    body: ListView(
      keyboardDismissBehavior: ScrollViewKeyboardDismissBehavior.onDrag,
      padding: const EdgeInsets.all(16),
      children: [
        const Text(
          'Kepala keluarga wajib diisi. Rumah dan anggota lain boleh ditambahkan sekarang atau nanti.',
          style: TextStyle(color: Brand.muted, height: 1.35),
        ),
        const SizedBox(height: 12),
        TextField(
          controller: _nama,
          maxLength: 120,
          decoration: InputDecoration(labelText: 'Nama kepala', errorText: _namaError, errorMaxLines: 3),
        ),
        const SizedBox(height: 8),
        TextField(
          controller: _hubungan,
          maxLength: 40,
          decoration: const InputDecoration(labelText: 'Hubungan kepala', helperText: 'Boleh dikosongkan.', helperMaxLines: 2),
        ),
        const SizedBox(height: 8),
        TextField(
          controller: _wa,
          keyboardType: TextInputType.phone,
          maxLength: 25,
          decoration: InputDecoration(labelText: 'WhatsApp kepala', helperText: 'Boleh dikosongkan.', helperMaxLines: 2, errorText: _waError, errorMaxLines: 3),
        ),
        const SizedBox(height: 8),
        FilterChip(label: const Text('Tambah satu anggota'), selected: _extra, onSelected: _busy ? null : (value) => setState(() => _extra = value)),
        if (_extra) ...[
          const SizedBox(height: 8),
          TextField(
            controller: _extraNama,
            maxLength: 120,
            decoration: InputDecoration(labelText: 'Nama anggota', errorText: _extraNamaError, errorMaxLines: 3),
          ),
          const SizedBox(height: 8),
          TextField(
            controller: _extraHubungan,
            maxLength: 40,
            decoration: InputDecoration(labelText: 'Hubungan anggota', errorText: _extraHubunganError, errorMaxLines: 3),
          ),
          const SizedBox(height: 8),
          TextField(
            controller: _extraWa,
            maxLength: 25,
            decoration: InputDecoration(labelText: 'WhatsApp anggota', errorText: _extraWaError, errorMaxLines: 3),
          ),
        ],
        const SizedBox(height: 8),
        FilterChip(label: const Text('Hubungkan ke rumah'), selected: _link, onSelected: _busy ? null : (value) => setState(() => _link = value)),
        if (_link) ...[
          const SizedBox(height: 8),
          TextField(
            decoration: const InputDecoration(prefixIcon: Icon(Icons.search), labelText: 'Cari rumah'),
            textInputAction: TextInputAction.search,
            onSubmitted: (value) => setState(() => _q = value.trim()),
          ),
          const SizedBox(height: 8),
          AsyncView(
            value: ref.watch(housesProvider(_houses)),
            onRetry: () => ref.invalidate(housesProvider(_houses)),
            builder: (page) => AppendedPage(
              key: ValueKey('rumah-baru|$_q'),
              first: page,
              load: (n) => ref.read(housesProvider((q: _q, arsip: false, page: n)).future),
              builder: (context, rows) {
                final choices = rows.where((house) => house['archived_at'] == null).toList();
                if (choices.isEmpty) {
                  return const Text('Tidak ada rumah pada pencarian ini.', style: TextStyle(color: Brand.muted, height: 1.35));
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
                                  _rumahLabel = '${house['kode_rumah'] ?? 'Rumah'}';
                                  _formError = null;
                                }),
                          child: Padding(
                            padding: const EdgeInsets.all(14),
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text('${house['kode_rumah'] ?? 'Rumah'}', style: const TextStyle(fontWeight: FontWeight.w700, height: 1.25)),
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
          if (_rumahLabel != null) Text('Rumah dipilih: $_rumahLabel', style: const TextStyle(fontWeight: FontWeight.w700)),
          const SizedBox(height: 8),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              ChoiceChip(label: const Text('Pemilik'), selected: _jenis == 'pemilik', onSelected: (_) => setState(() => _jenis = 'pemilik')),
              ChoiceChip(label: const Text('Kontrak'), selected: _jenis == 'kontrak', onSelected: (_) => setState(() => _jenis = 'kontrak')),
            ],
          ),
          const SizedBox(height: 8),
          TextField(
            controller: _mulai,
            maxLength: 10,
            decoration: InputDecoration(
              labelText: 'Tanggal mulai',
              helperText: 'Kosongkan untuk memakai hari ini.',
              helperMaxLines: 2,
              errorText: _dayError,
              errorMaxLines: 3,
            ),
          ),
        ],
        _Note(message: _formError),
        const SizedBox(height: 16),
        _SaveBar(busy: _busy, label: 'Simpan keluarga', onPressed: _save),
      ],
    ),
  );
}

class AdminAddMemberPage extends ConsumerStatefulWidget {
  const AdminAddMemberPage({super.key, required this.familyId, required this.versi});

  final String familyId;
  final int? versi;

  @override
  ConsumerState<AdminAddMemberPage> createState() => _AdminAddMemberPageState();
}

class _AdminAddMemberPageState extends ConsumerState<AdminAddMemberPage> {
  final _nama = TextEditingController();
  final _hubungan = TextEditingController();
  final _wa = TextEditingController();
  late int? _versi;
  var _busy = false;
  String? _namaError;
  String? _hubunganError;
  String? _waError;
  String? _formError;
  String? _review;

  @override
  void initState() {
    super.initState();
    _versi = widget.versi;
  }

  @override
  void dispose() {
    _nama.dispose();
    _hubungan.dispose();
    _wa.dispose();
    super.dispose();
  }

  Future<void> _keep(ApiException error) async {
    setState(() => _formError = conflictKeptMessage(error.message));
    final fresh = await _read(ref, '/admin/families/${widget.familyId}');
    if (!mounted || fresh == null) return;
    final versi = readVersi(fresh);
    final kepala = ((fresh['anggota'] as List?) ?? const [])
        .whereType<Map>()
        .where((member) => member['is_kepala'] == true)
        .map((member) => member['nama'])
        .firstOrNull;
    setState(() {
      if (versi != null) _versi = versi;
      _review = reviewLine(kepala);
    });
  }

  Future<void> _save() async {
    if (_busy) return;
    final namaError = personNameError(_nama.text);
    final hubunganError = relationshipError(_hubungan.text);
    final waError = whatsappError(_wa.text);
    if (namaError != null || hubunganError != null || waError != null || _versi == null) {
      setState(() {
        _namaError = namaError;
        _hubunganError = hubunganError;
        _waError = waError;
        _formError = _versi == null && namaError == null && hubunganError == null && waError == null ? versiMissingMessage : null;
      });
      return;
    }
    setState(() => _busy = true);
    try {
      await ref
          .read(apiProvider)
          .post(
            '/admin/families/${widget.familyId}/members',
            data: memberBody(nama: _nama.text, hubungan: _hubungan.text, whatsapp: _wa.text, versi: _versi),
          );
      if (!mounted) return;
      Navigator.pop(context, true);
    } on ApiException catch (error) {
      if (!mounted) return;
      if (staleConflict(error)) {
        await _keep(error);
        return;
      }
      setState(() => _formError = error.display);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const Text('Anggota baru')),
    body: ListView(
      padding: const EdgeInsets.all(16),
      children: [
        TextField(
          controller: _nama,
          maxLength: 120,
          decoration: InputDecoration(labelText: 'Nama lengkap', errorText: _namaError, errorMaxLines: 3),
        ),
        const SizedBox(height: 8),
        TextField(
          controller: _hubungan,
          maxLength: 40,
          decoration: InputDecoration(labelText: 'Hubungan', errorText: _hubunganError, errorMaxLines: 3),
        ),
        const SizedBox(height: 8),
        TextField(
          controller: _wa,
          maxLength: 25,
          keyboardType: TextInputType.phone,
          decoration: InputDecoration(labelText: 'WhatsApp', helperText: 'Boleh dikosongkan.', helperMaxLines: 2, errorText: _waError, errorMaxLines: 3),
        ),
        _Note(review: _review, message: _formError),
        const SizedBox(height: 16),
        _SaveBar(busy: _busy, label: 'Tambah anggota', onPressed: _save),
      ],
    ),
  );
}

class TransferPrimaryPage extends ConsumerStatefulWidget {
  const TransferPrimaryPage({super.key, required this.familyId, required this.versi});

  final String familyId;
  final int? versi;

  @override
  ConsumerState<TransferPrimaryPage> createState() => _TransferPrimaryPageState();
}

class _TransferPrimaryPageState extends ConsumerState<TransferPrimaryPage> {
  final _alasan = TextEditingController();
  late int? _versi;
  String? _akunId;
  String? _akunLabel;
  var _busy = false;
  String? _formError;
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

  Future<void> _keep(ApiException error) async {
    setState(() => _formError = conflictKeptMessage(error.message));
    final fresh = await _read(ref, '/admin/families/${widget.familyId}');
    if (!mounted || fresh == null) return;
    final versi = readVersi(fresh);
    setState(() {
      if (versi != null) _versi = versi;
      _review = reviewLine(_kepala(fresh));
    });
  }

  String? _kepala(Json family) {
    for (final member in ((family['anggota'] as List?) ?? const []).whereType<Map>()) {
      if (member['is_kepala'] == true) return '${member['nama'] ?? ''}';
    }
    return null;
  }

  Future<void> _save() async {
    if (_busy) return;
    final reason = reasonError(_alasan.text);
    if (_akunId == null || reason != null || _versi == null) {
      setState(() => _formError = _akunId == null ? 'Pilih akun yang akan dijadikan utama.' : (reason ?? versiMissingMessage));
      return;
    }
    final ok = await confirm(
      context,
      title: 'Pindah akun utama?',
      message: '${_akunLabel ?? 'Akun ini'} menjadi akun utama. Akun utama sebelumnya menjadi akun tambahan.',
      yes: 'Pindah akun utama',
    );
    if (!ok || !mounted) return;
    setState(() => _busy = true);
    try {
      await ref
          .read(apiProvider)
          .post(
            '/admin/families/${widget.familyId}/primary-account',
            data: transferPrimaryBody(akunId: _akunId!, alasan: _alasan.text, versi: _versi),
          );
      if (!mounted) return;
      Navigator.pop(context, true);
    } on ApiException catch (error) {
      if (!mounted) return;
      if (staleConflict(error)) {
        await _keep(error);
        return;
      }
      setState(() => _formError = error.display);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final detail = ref.watch(familyDetailProvider(widget.familyId));
    return Scaffold(
      appBar: AppBar(title: const Text('Akun utama')),
      body: AsyncView(
        value: detail,
        onRetry: () => ref.invalidate(familyDetailProvider(widget.familyId)),
        builder: (family) {
          final links = ((family['akun'] as List?) ?? const []).whereType<Map>().where((link) {
            final akun = link['akun'];
            final status = akun is Map ? '${akun['status']}' : '';
            return link['selesai_at'] == null && link['is_utama'] != true && status != 'menunggu' && status != 'diarsipkan';
          }).toList();
          return ListView(
            padding: const EdgeInsets.all(16),
            children: [
              const Text('Pilih akun tambahan yang sudah aktif. Peran pengurus tidak diubah dari sini.', style: TextStyle(color: Brand.muted, height: 1.35)),
              const SizedBox(height: 12),
              if (links.isEmpty)
                const Text('Belum ada akun lain yang dapat dijadikan utama.', style: TextStyle(height: 1.35))
              else
                for (final link in links)
                  Card(
                    margin: const EdgeInsets.only(bottom: 8),
                    color: _akunId == (link['akun'] is Map ? (link['akun'] as Map)['id'] : null) ? Brand.yellow.withValues(alpha: 0.35) : null,
                    child: InkWell(
                      onTap: _busy
                          ? null
                          : () => setState(() {
                              final akun = link['akun'];
                              _akunId = akun is Map ? '${akun['id']}' : null;
                              _akunLabel = akun is Map ? '${akun['nama'] ?? 'Akun'}' : 'Akun';
                              _formError = null;
                            }),
                      child: Padding(
                        padding: const EdgeInsets.all(14),
                        child: Text(
                          link['akun'] is Map ? '${(link['akun'] as Map)['nama'] ?? 'Akun'}' : 'Akun',
                          style: const TextStyle(fontWeight: FontWeight.w700, height: 1.25),
                        ),
                      ),
                    ),
                  ),
              const SizedBox(height: 8),
              TextField(
                controller: _alasan,
                maxLength: 300,
                minLines: 2,
                maxLines: 4,
                decoration: const InputDecoration(labelText: 'Alasan'),
              ),
              _Note(review: _review, message: _formError),
              const SizedBox(height: 16),
              _SaveBar(busy: _busy, label: 'Pindah akun utama', onPressed: _save),
            ],
          );
        },
      ),
    );
  }
}

class MoveResidentPage extends ConsumerStatefulWidget {
  const MoveResidentPage({super.key, required this.residentId, required this.familyId, required this.versi, required this.nama});

  final String residentId;
  final String familyId;
  final int? versi;
  final String nama;

  @override
  ConsumerState<MoveResidentPage> createState() => _MoveResidentPageState();
}

class _MoveResidentPageState extends ConsumerState<MoveResidentPage> {
  final _hubungan = TextEditingController();
  final _alasan = TextEditingController();
  late int? _versi;
  String _q = '';
  String? _keluargaId;
  String? _keluargaLabel;
  var _busy = false;
  String? _formError;
  String? _review;

  FamilyQuery get _query => (q: _q, arsip: false, status: '', page: 1);

  @override
  void initState() {
    super.initState();
    _versi = widget.versi;
  }

  @override
  void dispose() {
    _hubungan.dispose();
    _alasan.dispose();
    super.dispose();
  }

  Future<void> _keep(ApiException error) async {
    setState(() => _formError = conflictKeptMessage(error.message));
    final fresh = await _read(ref, '/admin/residents/${widget.residentId}');
    if (!mounted || fresh == null) return;
    final versi = familyVersi(fresh);
    setState(() {
      if (versi != null) _versi = versi;
      _review = reviewLine(fresh['nama']);
    });
  }

  Future<void> _save() async {
    if (_busy) return;
    final hubunganError = relationshipError(_hubungan.text);
    final reason = reasonError(_alasan.text);
    if (_keluargaId == null || hubunganError != null || reason != null || _versi == null) {
      setState(() => _formError = _keluargaId == null ? 'Pilih keluarga tujuan.' : (hubunganError ?? reason ?? versiMissingMessage));
      return;
    }
    final ok = await confirm(
      context,
      title: 'Pindah ke keluarga lain?',
      message: '${widget.nama} keluar dari keluarga sekarang dan masuk ke ${_keluargaLabel ?? 'keluarga tujuan'}.',
      yes: 'Pindah ke keluarga lain',
    );
    if (!ok || !mounted) return;
    setState(() => _busy = true);
    try {
      await ref
          .read(apiProvider)
          .post(
            '/admin/residents/${widget.residentId}/move',
            data: residentMoveBody(keluargaId: _keluargaId!, hubungan: _hubungan.text, alasan: _alasan.text, versi: _versi),
          );
      if (!mounted) return;
      Navigator.pop(context, true);
    } on ApiException catch (error) {
      if (!mounted) return;
      if (staleConflict(error)) {
        await _keep(error);
        return;
      }
      setState(() => _formError = error.display);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final families = ref.watch(familiesProvider(_query));
    return Scaffold(
      appBar: AppBar(title: const Text('Pindah keluarga')),
      body: ListView(
        keyboardDismissBehavior: ScrollViewKeyboardDismissBehavior.onDrag,
        padding: const EdgeInsets.all(16),
        children: [
          Text(widget.nama, style: Theme.of(context).textTheme.titleMedium),
          const SizedBox(height: 8),
          const Text(
            'Versi yang dikirim adalah versi keluarga asal. Kepala keluarga tidak dipindahkan dari sini.',
            style: TextStyle(color: Brand.muted, height: 1.35),
          ),
          const SizedBox(height: 12),
          TextField(
            decoration: const InputDecoration(prefixIcon: Icon(Icons.search), labelText: 'Cari keluarga tujuan'),
            textInputAction: TextInputAction.search,
            onSubmitted: (value) => setState(() => _q = value.trim()),
          ),
          const SizedBox(height: 8),
          AsyncView(
            value: families,
            onRetry: () => ref.invalidate(familiesProvider(_query)),
            builder: (page) => AppendedPage(
              key: ValueKey('pindah-keluarga|$_q'),
              first: page,
              load: (n) => ref.read(familiesProvider((q: _q, arsip: false, status: '', page: n)).future),
              builder: (context, rows) {
                final choices = rows.where((family) => '${family['id']}' != widget.familyId).toList();
                if (choices.isEmpty) {
                  return const Text('Tidak ada keluarga lain pada pencarian ini.', style: TextStyle(color: Brand.muted, height: 1.35));
                }
                return Column(
                  children: [
                    for (final family in choices)
                      Card(
                        margin: const EdgeInsets.only(bottom: 8),
                        color: _keluargaId == family['id'] ? Brand.yellow.withValues(alpha: 0.35) : null,
                        child: InkWell(
                          onTap: _busy
                              ? null
                              : () => setState(() {
                                  _keluargaId = '${family['id']}';
                                  _keluargaLabel = '${family['kepala'] ?? 'Keluarga'}';
                                  _formError = null;
                                }),
                          child: Padding(
                            padding: const EdgeInsets.all(14),
                            child: Text('${family['kepala'] ?? 'Belum ada kepala'}', style: const TextStyle(fontWeight: FontWeight.w700, height: 1.25)),
                          ),
                        ),
                      ),
                  ],
                );
              },
            ),
          ),
          const SizedBox(height: 8),
          TextField(
            controller: _hubungan,
            maxLength: 40,
            decoration: const InputDecoration(labelText: 'Hubungan di keluarga tujuan'),
          ),
          const SizedBox(height: 8),
          TextField(
            controller: _alasan,
            maxLength: 300,
            minLines: 2,
            maxLines: 4,
            decoration: const InputDecoration(labelText: 'Alasan'),
          ),
          _Note(review: _review, message: _formError),
          const SizedBox(height: 16),
          _SaveBar(busy: _busy, label: 'Pindah ke keluarga lain', onPressed: _save),
        ],
      ),
    );
  }
}

class HouseAddressPage extends ConsumerStatefulWidget {
  const HouseAddressPage({super.key, required this.house});

  final Json house;

  @override
  ConsumerState<HouseAddressPage> createState() => _HouseAddressPageState();
}

class _HouseAddressPageState extends ConsumerState<HouseAddressPage> {
  late final TextEditingController _blok;
  late final TextEditingController _jalan;
  late final TextEditingController _nomor;
  final _alasan = TextEditingController();
  late int? _versi;
  var _busy = false;
  String? _jalanError;
  String? _nomorError;
  String? _blokError;
  String? _formError;
  String? _review;

  @override
  void initState() {
    super.initState();
    _blok = TextEditingController(text: '${widget.house['blok'] ?? ''}');
    _jalan = TextEditingController(text: '${widget.house['jalan'] ?? ''}');
    _nomor = TextEditingController(text: '${widget.house['nomor'] ?? ''}');
    _versi = readVersi(widget.house);
  }

  @override
  void dispose() {
    _blok.dispose();
    _jalan.dispose();
    _nomor.dispose();
    _alasan.dispose();
    super.dispose();
  }

  Future<void> _keep(ApiException error) async {
    setState(() => _formError = conflictKeptMessage(error.message));
    final fresh = await _read(ref, '/admin/houses/${widget.house['id']}');
    if (!mounted || fresh == null) return;
    final versi = readVersi(fresh);
    setState(() {
      if (versi != null) _versi = versi;
      _review = reviewLine(fresh['alamat']);
    });
  }

  Future<void> _save() async {
    if (_busy) return;
    final jalan = _jalan.text.trim();
    final nomor = _nomor.text.trim();
    final blok = _blok.text.trim();
    final reason = reasonError(_alasan.text);
    String? jalanError;
    String? nomorError;
    String? blokError;
    if (jalan.isEmpty) jalanError = 'Jalan wajib diisi.';
    if (jalan.length > 150) jalanError = 'Jalan paling panjang 150 karakter.';
    if (nomor.isEmpty) nomorError = 'Nomor wajib diisi.';
    if (nomor.length > 20) nomorError = 'Nomor paling panjang 20 karakter.';
    if (blok.length > 30) blokError = 'Blok paling panjang 30 karakter.';
    if (jalanError != null || nomorError != null || blokError != null || reason != null || _versi == null) {
      setState(() {
        _jalanError = jalanError;
        _nomorError = nomorError;
        _blokError = blokError;
        _formError = reason ?? (_versi == null && jalanError == null && nomorError == null && blokError == null ? versiMissingMessage : null);
      });
      return;
    }
    setState(() => _busy = true);
    try {
      await ref
          .read(apiProvider)
          .patch(
            '/admin/houses/${widget.house['id']}',
            data: houseAddressBody(blok: _blok.text, jalan: _jalan.text, nomor: _nomor.text, alasan: _alasan.text, versi: _versi),
          );
      if (!mounted) return;
      Navigator.pop(context, true);
    } on ApiException catch (error) {
      if (!mounted) return;
      if (staleConflict(error)) {
        await _keep(error);
        return;
      }
      setState(() => _formError = error.display);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const Text('Alamat rumah')),
    body: ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Text('Kode ${widget.house['kode_rumah'] ?? 'rumah'} tetap. Yang diubah hanya blok, jalan, dan nomor.', style: const TextStyle(height: 1.35)),
        const SizedBox(height: 12),
        TextField(
          controller: _blok,
          maxLength: 30,
          decoration: InputDecoration(labelText: 'Blok', errorText: _blokError, errorMaxLines: 3),
        ),
        const SizedBox(height: 8),
        TextField(
          controller: _jalan,
          maxLength: 150,
          decoration: InputDecoration(labelText: 'Jalan', errorText: _jalanError, errorMaxLines: 3),
        ),
        const SizedBox(height: 8),
        TextField(
          controller: _nomor,
          maxLength: 20,
          decoration: InputDecoration(labelText: 'Nomor', errorText: _nomorError, errorMaxLines: 3),
        ),
        const SizedBox(height: 8),
        TextField(
          controller: _alasan,
          maxLength: 300,
          minLines: 2,
          maxLines: 4,
          decoration: const InputDecoration(labelText: 'Alasan'),
        ),
        _Note(review: _review, message: _formError),
        const SizedBox(height: 16),
        _SaveBar(busy: _busy, label: 'Simpan alamat', onPressed: _save),
      ],
    ),
  );
}

class HouseOwnerPage extends ConsumerStatefulWidget {
  const HouseOwnerPage({super.key, required this.house});

  final Json house;

  @override
  ConsumerState<HouseOwnerPage> createState() => _HouseOwnerPageState();
}

class _HouseOwnerPageState extends ConsumerState<HouseOwnerPage> {
  late final TextEditingController _nama;
  late final TextEditingController _wa;
  late final TextEditingController _catatan;
  final _alasan = TextEditingController();
  late int? _versi;
  var _busy = false;
  String? _namaError;
  String? _waError;
  String? _formError;
  String? _review;

  @override
  void initState() {
    super.initState();
    final pemilik = widget.house['pemilik'];
    _nama = TextEditingController(text: pemilik is Map ? '${pemilik['nama'] ?? ''}' : '');
    _wa = TextEditingController(text: pemilik is Map ? '${pemilik['whatsapp'] ?? ''}' : '');
    _catatan = TextEditingController(text: pemilik is Map ? '${pemilik['catatan'] ?? ''}' : '');
    _versi = readVersi(widget.house);
  }

  @override
  void dispose() {
    _nama.dispose();
    _wa.dispose();
    _catatan.dispose();
    _alasan.dispose();
    super.dispose();
  }

  Future<void> _keep(ApiException error) async {
    setState(() => _formError = conflictKeptMessage(error.message));
    final fresh = await _read(ref, '/admin/houses/${widget.house['id']}');
    if (!mounted || fresh == null) return;
    final versi = readVersi(fresh);
    final pemilik = fresh['pemilik'];
    setState(() {
      if (versi != null) _versi = versi;
      _review = reviewLine(pemilik is Map ? pemilik['nama'] : 'belum ada pemilik');
    });
  }

  Future<void> _send({required bool clear}) async {
    if (_busy) return;
    final namaError = clear ? null : personNameError(_nama.text, label: 'Nama pemilik');
    final waError = clear ? null : whatsappError(_wa.text);
    final reason = reasonError(_alasan.text);
    if (namaError != null || waError != null || reason != null || _versi == null) {
      setState(() {
        _namaError = namaError;
        _waError = waError;
        _formError = reason ?? (_versi == null && namaError == null && waError == null ? versiMissingMessage : null);
      });
      return;
    }
    if (clear) {
      final ok = await confirm(context, title: 'Lepas pemilik?', message: 'Pemilik tercatat dilepas. Penghuni rumah tidak berubah.', yes: 'Lepas pemilik');
      if (!ok || !mounted) return;
    }
    setState(() => _busy = true);
    try {
      await ref
          .read(apiProvider)
          .patch(
            '/admin/houses/${widget.house['id']}',
            data: houseOwnerBody(nama: _nama.text, whatsapp: _wa.text, catatan: _catatan.text, clear: clear, alasan: _alasan.text, versi: _versi),
          );
      if (!mounted) return;
      Navigator.pop(context, true);
    } on ApiException catch (error) {
      if (!mounted) return;
      if (staleConflict(error)) {
        await _keep(error);
        return;
      }
      setState(() => _formError = error.display);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final hasOwner = widget.house['pemilik'] is Map;
    return Scaffold(
      appBar: AppBar(title: const Text('Pemilik rumah')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          const Text('Pemilik tercatat terpisah dari keluarga yang menghuni.', style: TextStyle(height: 1.35)),
          const SizedBox(height: 12),
          TextField(
            controller: _nama,
            maxLength: 120,
            decoration: InputDecoration(labelText: 'Nama pemilik', errorText: _namaError, errorMaxLines: 3),
          ),
          const SizedBox(height: 8),
          TextField(
            controller: _wa,
            maxLength: 25,
            keyboardType: TextInputType.phone,
            decoration: InputDecoration(
              labelText: 'WhatsApp pemilik',
              helperText: 'Boleh dikosongkan.',
              helperMaxLines: 2,
              errorText: _waError,
              errorMaxLines: 3,
            ),
          ),
          const SizedBox(height: 8),
          TextField(
            controller: _catatan,
            maxLength: 300,
            minLines: 2,
            maxLines: 4,
            decoration: const InputDecoration(labelText: 'Catatan'),
          ),
          const SizedBox(height: 8),
          TextField(
            controller: _alasan,
            maxLength: 300,
            minLines: 2,
            maxLines: 4,
            decoration: const InputDecoration(labelText: 'Alasan'),
          ),
          _Note(review: _review, message: _formError),
          const SizedBox(height: 16),
          _SaveBar(busy: _busy, label: 'Simpan pemilik', onPressed: () => _send(clear: false)),
          if (hasOwner) ...[
            const SizedBox(height: 8),
            SizedBox(
              width: double.infinity,
              child: OutlinedButton(
                onPressed: _busy ? null : () => _send(clear: true),
                child: const Text('Lepas pemilik', textAlign: TextAlign.center),
              ),
            ),
          ],
        ],
      ),
    );
  }
}

class AccountDetailPage extends ConsumerWidget {
  const AccountDetailPage({super.key, required this.id});

  final String id;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final detail = ref.watch(accountDetailProvider(id));
    return Scaffold(
      appBar: AppBar(title: const Text('Riwayat akun')),
      body: AsyncView(
        value: detail,
        onRetry: () => ref.invalidate(accountDetailProvider(id)),
        builder: (akun) {
          final links = ((akun['tautan'] as List?) ?? const []).whereType<Map>().toList();
          final request = akun['permohonan'];
          final status = '${akun['status']}';
          return ListView(
            padding: const EdgeInsets.all(16),
            children: [
              Text('${akun['nama'] ?? ''}', style: Theme.of(context).textTheme.titleMedium),
              Text('${akun['email'] ?? ''}', style: const TextStyle(color: Brand.muted, height: 1.35)),
              const SizedBox(height: 8),
              Wrap(
                spacing: 6,
                runSpacing: 6,
                children: [
                  StatusChip(status == 'aktif' ? 'aktif' : 'menunggu', label: _statusLabel(status)),
                  StatusChip('x', label: akun['jenis'] == 'utama' ? 'Akun utama' : 'Akun tambahan'),
                ],
              ),
              const SizedBox(height: 8),
              const Text('Peran tidak diubah dari aplikasi. Setiap pengurus memiliki hak yang sama.', style: TextStyle(color: Brand.muted, height: 1.35)),
              const SizedBox(height: 16),
              Text('Riwayat tautan', style: Theme.of(context).textTheme.titleMedium),
              const SizedBox(height: 8),
              if (links.isEmpty)
                const Text('Belum ada tautan keluarga.', style: TextStyle(color: Brand.muted, height: 1.35))
              else
                for (final link in links)
                  Padding(
                    padding: const EdgeInsets.only(bottom: 10),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(_person(link), style: const TextStyle(fontWeight: FontWeight.w700, height: 1.25)),
                        Text(
                          [
                            link['is_utama'] == true ? 'Akun utama' : 'Akun tambahan',
                            if (tanggalHari(link['mulai_at']).isNotEmpty) 'sejak ${tanggalHari(link['mulai_at'])}',
                            if (link['selesai_at'] == null) 'masih terhubung' else 'sudah selesai',
                          ].join(' • '),
                          style: const TextStyle(color: Brand.muted, height: 1.3),
                        ),
                      ],
                    ),
                  ),
              if (request is Map) ...[
                const SizedBox(height: 8),
                Text('Permohonan', style: Theme.of(context).textTheme.titleMedium),
                const SizedBox(height: 8),
                Text('${request['nama_pemohon'] ?? akun['nama'] ?? ''}', style: const TextStyle(fontWeight: FontWeight.w700)),
                Text(
                  [
                    '${request['status'] ?? ''}',
                    if (request['hubungan_keluarga'] != null) '${request['hubungan_keluarga']}',
                    if ('${request['alamat_diajukan'] ?? ''}'.isNotEmpty) '${request['alamat_diajukan']}',
                  ].join(' • '),
                  style: const TextStyle(height: 1.35),
                ),
              ],
            ],
          );
        },
      ),
    );
  }

  String _person(Map link) {
    final warga = link['warga'];
    if (warga is Map && '${warga['nama'] ?? ''}'.isNotEmpty) return '${warga['nama']}';
    return 'Warga';
  }

  String _statusLabel(String status) => switch (status) {
    'aktif' => 'Aktif',
    'nonaktif' => 'Nonaktif',
    'menunggu' => 'Menunggu',
    'diarsipkan' => 'Arsip',
    _ => status,
  };
}
