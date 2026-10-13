import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/theme.dart';
import '../../core/api.dart';
import '../../core/models.dart';
import '../../core/session.dart';
import '../../core/widgets.dart';
import '../warga/providers.dart';
import 'family_actions.dart';

/// Papan rumah: satu elemen tegas, isi halaman tetap tenang.
class HousePlate extends StatelessWidget {
  const HousePlate({super.key, required this.kode, required this.alamat, this.hunian, this.footer});

  final String kode;
  final String alamat;
  final String? hunian;
  final String? footer;

  @override
  Widget build(BuildContext context) => Container(
    width: double.infinity,
    padding: const EdgeInsets.fromLTRB(16, 16, 16, 14),
    decoration: BoxDecoration(color: Brand.navy, borderRadius: BorderRadius.circular(18)),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(kode, style: Theme.of(context).textTheme.headlineSmall?.copyWith(color: Colors.white, height: 1.15)),
        const SizedBox(height: 6),
        Text(alamat, style: const TextStyle(color: Colors.white70, height: 1.35)),
        if (hunian != null && hunian!.isNotEmpty) ...[
          const SizedBox(height: 8),
          Text(
            hunian!,
            style: const TextStyle(color: Brand.yellow, fontWeight: FontWeight.w700),
          ),
        ],
        if (footer != null && footer!.isNotEmpty) ...[const SizedBox(height: 8), Text(footer!, style: const TextStyle(color: Colors.white70, height: 1.35))],
      ],
    ),
  );
}

/// Setelah 403, segarkan keluarga dan akun. Token tidak dihapus. Pemanggil mempertahankan isian.
Future<void> refreshHouseholdAccess(WidgetRef ref) async {
  ref.invalidate(householdProvider);
  final session = ref.read(sessionProvider.notifier);
  final api = ref.read(apiProvider);
  final epoch = session.epoch;
  try {
    final me = await api.get('/auth/me');
    if (me is! Map) return;
    session.updateAkun(Akun(Json.from(me)), epoch: epoch);
  } on ApiException {
    // 403 pada /auth/me tidak mencabut token dan tidak membuang draf.
  }
}

class FamilyPanel extends ConsumerWidget {
  const FamilyPanel({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final akun = ref.watch(sessionProvider).akun!;
    final household = ref.watch(householdProvider);
    return SectionCard(
      title: 'Keluarga',
      child: AsyncView(
        value: household,
        onRetry: () => ref.invalidate(householdProvider),
        builder: (data) {
          final editable = canEditHousehold(status: akun.status, dapatUbah: data['dapat_ubah']);
          final rumah = data['rumah'] as Map?;
          final anggota = ((data['anggota'] as List?) ?? const []).whereType<Map>().toList();
          final draft = HouseholdDraft.fromHousehold(data);
          final hunian = hunianLabel(data['jenis_hunian']);
          return Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              HousePlate(
                kode: '${rumah?['kode_rumah'] ?? 'Rumah'}',
                alamat: '${rumah?['alamat'] ?? 'Alamat menyusul'}',
                hunian: hunian.isEmpty ? null : hunian,
              ),
              const SizedBox(height: 12),
              Wrap(
                spacing: 8,
                runSpacing: 8,
                crossAxisAlignment: WrapCrossAlignment.center,
                children: [
                  StatusChip(
                    data['status'] == 'terverifikasi' ? 'aktif' : 'menunggu',
                    label: data['status'] == 'terverifikasi' ? 'Terverifikasi' : 'Belum diverifikasi',
                  ),
                ],
              ),
              if (akun.jenis == 'utama' && data['status'] != 'terverifikasi') ...[
                const SizedBox(height: 8),
                const Text('Akun utama sudah aktif. Keluarga belum diverifikasi pengurus.', style: TextStyle(color: Brand.muted, height: 1.35)),
              ],
              const SizedBox(height: 8),
              if (anggota.isEmpty)
                const Padding(
                  padding: EdgeInsets.symmetric(vertical: 8),
                  child: Text('Belum ada anggota tercatat.', style: TextStyle(color: Brand.muted, height: 1.35)),
                )
              else
                for (final member in anggota)
                  _MemberRow(
                    member: member,
                    onOpen: editable && draft.ready
                        ? () async {
                            await Navigator.push<void>(
                              context,
                              MaterialPageRoute(
                                builder: (_) => MemberPage(household: data, member: Map<String, dynamic>.from(member)),
                              ),
                            );
                          }
                        : null,
                  ),
              if (editable && draft.ready) ...[
                const SizedBox(height: 8),
                SizedBox(
                  width: double.infinity,
                  child: OutlinedButton(
                    onPressed: () => Navigator.push<void>(context, MaterialPageRoute(builder: (_) => FamilyEditPage(household: data))),
                    child: const Text('Ubah data keluarga', textAlign: TextAlign.center),
                  ),
                ),
                const SizedBox(height: 8),
                SizedBox(
                  width: double.infinity,
                  child: FilledButton(
                    onPressed: () => Navigator.push<void>(context, MaterialPageRoute(builder: (_) => MemberPage(household: data))),
                    child: const Text('Tambah anggota', textAlign: TextAlign.center),
                  ),
                ),
              ],
              if (!editable) ...[
                const SizedBox(height: 8),
                const Text('Perubahan data keluarga dilakukan oleh akun utama.', style: TextStyle(color: Brand.muted, height: 1.35)),
              ],
            ],
          );
        },
      ),
    );
  }
}

class _MemberRow extends StatelessWidget {
  const _MemberRow({required this.member, this.onOpen});

  final Map member;
  final VoidCallback? onOpen;

  @override
  Widget build(BuildContext context) {
    final phone = member['whatsapp'];
    final subtitle = [
      '${member['hubungan_keluarga'] ?? 'Anggota'}',
      if (member['punya_akun'] == true) 'punya akun',
      if (phone != null && '$phone'.isNotEmpty) '$phone',
    ].join(' • ');
    return InkWell(
      onTap: onOpen,
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 8),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Icon(member['is_kepala'] == true ? Icons.star_rounded : Icons.person_outline, color: Brand.navy),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text('${member['nama']}', style: const TextStyle(fontWeight: FontWeight.w700, height: 1.25)),
                  Text(subtitle, style: const TextStyle(color: Brand.muted, height: 1.3)),
                ],
              ),
            ),
            if (onOpen != null) const Icon(Icons.chevron_right, color: Brand.muted),
          ],
        ),
      ),
    );
  }
}

class FamilyEditPage extends ConsumerStatefulWidget {
  const FamilyEditPage({super.key, required this.household});

  final Json household;

  @override
  ConsumerState<FamilyEditPage> createState() => _FamilyEditPageState();
}

class _FamilyEditPageState extends ConsumerState<FamilyEditPage> {
  late final TextEditingController _nama;
  late final TextEditingController _wa;
  late int? _versi;
  var _busy = false;
  String? _namaError;
  String? _waError;
  String? _formError;
  String? _review;

  @override
  void initState() {
    super.initState();
    final kepala = _kepala(widget.household);
    _nama = TextEditingController(text: '${kepala?['nama'] ?? ''}');
    _wa = TextEditingController(text: '${kepala?['whatsapp'] ?? ''}');
    _versi = readVersi(widget.household);
  }

  @override
  void dispose() {
    _nama.dispose();
    _wa.dispose();
    super.dispose();
  }

  Map? _kepala(Json data) {
    for (final member in ((data['anggota'] as List?) ?? const []).whereType<Map>()) {
      if (member['is_kepala'] == true) return member;
    }
    return null;
  }

  Future<void> _save() async {
    if (_busy) return;
    final namaError = personNameError(_nama.text, label: 'Nama kepala');
    final waError = whatsappError(_wa.text);
    if (namaError != null || waError != null) {
      setState(() {
        _namaError = namaError;
        _waError = waError;
        _formError = null;
      });
      return;
    }
    final draft = HouseholdDraft(versi: _versi);
    if (!draft.ready) {
      setState(() => _formError = versiMissingMessage);
      return;
    }
    setState(() => _busy = true);
    try {
      final phone = _wa.text.trim();
      await ref
          .read(apiProvider)
          .patch(
            '/household',
            data: draft.householdPatch(namaKepala: _nama.text, whatsapp: phone.isEmpty ? null : phone),
          );
      if (!mounted) return;
      ref.invalidate(householdProvider);
      Navigator.pop(context);
    } on ApiException catch (error) {
      if (!mounted) return;
      if (error.status == 403) {
        setState(() => _formError = error.message);
        await _reloadVersi();
        if (!mounted) return;
        await refreshHouseholdAccess(ref);
        return;
      }
      final next = draft.onFailure(error);
      if (next.conflict != null) {
        setState(() => _formError = next.conflict);
        await _reloadVersi();
        return;
      }
      setState(() {
        _namaError = error.fields['nama_kepala']?.join(' ');
        _waError = error.fields['whatsapp']?.join(' ');
        _formError = (_namaError == null && _waError == null) ? error.display : null;
      });
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _reloadVersi() async {
    try {
      final data = await ref.read(apiProvider).get('/household');
      if (!mounted || data is! Map) return;
      final fresh = Json.from(data);
      final versi = readVersi(fresh);
      final kepala = _kepala(fresh);
      setState(() {
        if (versi != null) _versi = versi;
        _review = reviewLine(kepala?['nama']);
      });
    } catch (_) {}
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const Text('Data keluarga')),
    body: ListView(
      padding: const EdgeInsets.all(16),
      children: [
        const Text('Nama dan WhatsApp kepala keluarga. Anggota lain diubah dari daftar.', style: TextStyle(color: Brand.muted, height: 1.35)),
        const SizedBox(height: 16),
        TextField(
          controller: _nama,
          textInputAction: TextInputAction.next,
          maxLength: 120,
          decoration: InputDecoration(labelText: 'Nama kepala', error: _namaError == null ? null : const SizedBox.shrink()),
          onChanged: (_) {
            if (_namaError != null) setState(() => _namaError = null);
          },
        ),
        if (_namaError != null) Text(_namaError!, style: const TextStyle(color: Brand.danger, height: 1.35)),
        const SizedBox(height: 12),
        TextField(
          controller: _wa,
          keyboardType: TextInputType.phone,
          maxLength: 25,
          decoration: InputDecoration(
            labelText: 'WhatsApp',
            helperText: 'Kosongkan bila tidak ada. 8–25 karakter.',
            helperMaxLines: 3,
            error: _waError == null ? null : const SizedBox.shrink(),
          ),
          onChanged: (_) {
            if (_waError != null) setState(() => _waError = null);
          },
        ),
        if (_waError != null) Text(_waError!, style: const TextStyle(color: Brand.danger, height: 1.35)),
        if (_review != null) ...[const SizedBox(height: 8), Text(_review!, style: const TextStyle(height: 1.35))],
        if (_formError != null) ...[const SizedBox(height: 8), Text(_formError!, style: const TextStyle(color: Brand.danger, height: 1.35))],
        const SizedBox(height: 16),
        BusyButton(label: 'Simpan perubahan', onPressed: _busy ? null : _save),
      ],
    ),
  );
}

class MemberPage extends ConsumerStatefulWidget {
  const MemberPage({super.key, this.household, this.member});

  /// Null saat menambah anggota baru.
  final Json? household;
  final Json? member;

  @override
  ConsumerState<MemberPage> createState() => _MemberPageState();
}

class _MemberPageState extends ConsumerState<MemberPage> {
  late final TextEditingController _nama;
  late final TextEditingController _hubungan;
  late final TextEditingController _wa;
  int? _versi;
  var _busy = false;
  String? _namaError;
  String? _hubunganError;
  String? _waError;
  String? _formError;
  String? _review;

  bool get _creating => widget.member == null;
  bool get _kepala => widget.member?['is_kepala'] == true;

  @override
  void initState() {
    super.initState();
    _nama = TextEditingController(text: '${widget.member?['nama'] ?? ''}');
    _hubungan = TextEditingController(text: '${widget.member?['hubungan_keluarga'] ?? ''}');
    _wa = TextEditingController(text: '${widget.member?['whatsapp'] ?? ''}');
    _versi = readVersi(widget.household);
  }

  @override
  void dispose() {
    _nama.dispose();
    _hubungan.dispose();
    _wa.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    if (_busy) return;
    final namaError = _kepala ? null : personNameError(_nama.text);
    final hubunganError = _kepala ? null : relationshipError(_hubungan.text);
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
    if (_versi == null) {
      setState(() => _formError = versiMissingMessage);
      return;
    }
    setState(() => _busy = true);
    try {
      final api = ref.read(apiProvider);
      if (_creating) {
        await api.post(
          '/household/members',
          data: memberBody(nama: _nama.text, hubungan: _hubungan.text, whatsapp: _wa.text, versi: _versi),
        );
      } else if (_kepala) {
        final draft = HouseholdDraft(versi: _versi);
        final phone = _wa.text.trim();
        await api.patch(
          '/household',
          data: draft.householdPatch(namaKepala: _nama.text, whatsapp: phone.isEmpty ? null : phone),
        );
      } else {
        final phone = _wa.text.trim();
        await api.patch(
          '/household/members/${widget.member!['id']}',
          data: {
            ...memberBody(nama: _nama.text, hubungan: _hubungan.text, versi: _versi),
            'whatsapp': phone.isEmpty ? null : phone,
          },
        );
      }
      if (!mounted) return;
      ref.invalidate(householdProvider);
      Navigator.pop(context);
    } on ApiException catch (error) {
      if (!mounted) return;
      if (error.status == 403) {
        setState(() => _formError = error.message);
        await _reloadVersi();
        if (!mounted) return;
        await refreshHouseholdAccess(ref);
        return;
      }
      if (staleConflict(error)) {
        setState(() => _formError = conflictKeptMessage(error.message));
        await _reloadVersi();
        return;
      }
      setState(() {
        _namaError = error.fields['nama']?.join(' ') ?? error.fields['nama_kepala']?.join(' ');
        _hubunganError = error.fields['hubungan_keluarga']?.join(' ');
        _waError = error.fields['whatsapp']?.join(' ');
        _formError = (_namaError == null && _hubunganError == null && _waError == null) ? error.display : null;
      });
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _archive(String status) async {
    if (_busy || widget.member == null) return;
    if (_versi == null) {
      setState(() => _formError = versiMissingMessage);
      return;
    }
    final label = status == 'meninggal' ? 'meninggal' : 'pindah';
    final ok = await confirm(
      context,
      title: 'Tandai $label?',
      message: '${widget.member!['nama']} ditandai $label. Riwayat tetap tersimpan.',
      yes: 'Tandai $label',
    );
    if (!ok || !mounted) return;
    setState(() => _busy = true);
    try {
      await ref.read(apiProvider).delete('/household/members/${widget.member!['id']}', data: archiveMemberBody(status, versi: _versi));
      if (!mounted) return;
      ref.invalidate(householdProvider);
      Navigator.pop(context);
    } on ApiException catch (error) {
      if (!mounted) return;
      if (error.status == 403) {
        setState(() => _formError = error.message);
        await _reloadVersi();
        if (!mounted) return;
        await refreshHouseholdAccess(ref);
        return;
      }
      if (staleConflict(error)) {
        setState(() => _formError = conflictKeptMessage(error.message));
        await _reloadVersi();
        return;
      }
      setState(() => _formError = error.display);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _reloadVersi() async {
    try {
      final data = await ref.read(apiProvider).get('/household');
      if (!mounted || data is! Map) return;
      final fresh = Json.from(data);
      final versi = readVersi(fresh);
      Map? kepala;
      for (final member in ((fresh['anggota'] as List?) ?? const []).whereType<Map>()) {
        if (member['is_kepala'] == true) kepala = member;
      }
      setState(() {
        if (versi != null) _versi = versi;
        _review = reviewLine(kepala?['nama']);
      });
    } catch (_) {}
  }

  @override
  Widget build(BuildContext context) {
    final archive = widget.member != null && canArchiveMember(widget.member!);
    return Scaffold(
      appBar: AppBar(title: Text(_creating ? 'Anggota baru' : 'Anggota keluarga')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          TextField(
            controller: _nama,
            readOnly: _kepala,
            maxLength: 120,
            decoration: InputDecoration(labelText: 'Nama lengkap', error: _namaError == null ? null : const SizedBox.shrink()),
            onChanged: (_) {
              if (_namaError != null) setState(() => _namaError = null);
            },
          ),
          if (_namaError != null) Text(_namaError!, style: const TextStyle(color: Brand.danger, height: 1.35)),
          if (!_kepala) ...[
            const SizedBox(height: 12),
            TextField(
              controller: _hubungan,
              maxLength: 40,
              decoration: InputDecoration(
                labelText: 'Hubungan',
                hintText: 'Anak, istri, orang tua',
                error: _hubunganError == null ? null : const SizedBox.shrink(),
              ),
              onChanged: (_) {
                if (_hubunganError != null) setState(() => _hubunganError = null);
              },
            ),
            if (_hubunganError != null) Text(_hubunganError!, style: const TextStyle(color: Brand.danger, height: 1.35)),
          ],
          const SizedBox(height: 12),
          TextField(
            controller: _wa,
            keyboardType: TextInputType.phone,
            maxLength: 25,
            decoration: InputDecoration(
              labelText: 'WhatsApp',
              helperText: 'Boleh dikosongkan.',
              helperMaxLines: 2,
              error: _waError == null ? null : const SizedBox.shrink(),
            ),
            onChanged: (_) {
              if (_waError != null) setState(() => _waError = null);
            },
          ),
          if (_waError != null) Text(_waError!, style: const TextStyle(color: Brand.danger, height: 1.35)),
          if (_kepala) ...[
            const SizedBox(height: 8),
            const Text('Nama kepala diubah dari Data keluarga. Hubungan kepala tidak diubah di sini.', style: TextStyle(color: Brand.muted, height: 1.35)),
          ],
          if (_review != null) ...[const SizedBox(height: 8), Text(_review!, style: const TextStyle(height: 1.35))],
          if (_formError != null) ...[const SizedBox(height: 8), Text(_formError!, style: const TextStyle(color: Brand.danger, height: 1.35))],
          const SizedBox(height: 16),
          BusyButton(label: _creating ? 'Tambah anggota' : 'Simpan perubahan', onPressed: _busy ? null : _save),
          if (archive) ...[
            const SizedBox(height: 12),
            OutlinedButton(onPressed: _busy ? null : () => _archive('pindah'), child: const Text('Tandai pindah')),
            const SizedBox(height: 8),
            OutlinedButton(onPressed: _busy ? null : () => _archive('meninggal'), child: const Text('Tandai meninggal')),
          ],
        ],
      ),
    );
  }
}
