import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/theme.dart';
import '../../core/api.dart';
import '../../core/format.dart';
import '../../core/models.dart';
import '../../core/session.dart';
import '../../core/widgets.dart';
import 'admin_edits.dart';
import 'admin_links.dart';
import 'census_providers.dart';
import 'family_actions.dart';
import 'family_page.dart';
import 'paged_list.dart';

class HouseAdminPage extends ConsumerWidget {
  const HouseAdminPage({super.key, required this.summary, required this.onRecordPayment});

  final Json summary;
  final Future<void> Function(Json house) onRecordPayment;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final id = '${summary['id']}';
    final detail = ref.watch(houseDetailProvider(id));
    final dues = ref.watch(houseDuesProvider(id));
    return Scaffold(
      appBar: AppBar(title: Text('Rumah ${summary['kode_rumah'] ?? ''}')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          AsyncView(
            value: detail,
            onRetry: () => ref.invalidate(houseDetailProvider(id)),
            builder: (house) => _HouseBody(house: house, onChanged: () => ref.invalidate(houseDetailProvider(id))),
          ),
          const SizedBox(height: 16),
          SectionCard(
            title: 'Iuran rumah',
            trailing: TextButton(
              onPressed: () async {
                final house = detail.asData?.value ?? summary;
                await onRecordPayment(house);
                ref.invalidate(houseDuesProvider(id));
              },
              child: const Text('Catat bayar'),
            ),
            child: AsyncView(
              value: dues,
              onRetry: () => ref.invalidate(houseDuesProvider(id)),
              builder: (data) {
                final rows = ((data['tagihan'] as List?) ?? const []).whereType<Map>().map((row) => Tagihan(Json.from(row))).toList();
                if (rows.isEmpty) return const Text('Belum ada tagihan pada rumah ini.');
                return Column(
                  children: [
                    for (final bill in rows)
                      Padding(
                        padding: const EdgeInsets.symmetric(vertical: 6),
                        child: Row(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Expanded(
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Text(periode(bill.periode)),
                                  Text(rupiah(bill.nominal), style: const TextStyle(color: Brand.muted)),
                                ],
                              ),
                            ),
                            const SizedBox(width: 8),
                            StatusChip(bill.status, label: bill.label),
                          ],
                        ),
                      ),
                  ],
                );
              },
            ),
          ),
        ],
      ),
    );
  }
}

class _HouseBody extends ConsumerWidget {
  const _HouseBody({required this.house, required this.onChanged});

  final Json house;
  final VoidCallback onChanged;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final archived = house['archived_at'] != null;
    final family = house['keluarga'] as Map?;
    final members = ((family?['anggota'] as List?) ?? const []).whereType<Map>().toList();
    final history = ((house['riwayat_penghunian'] as List?) ?? const []).whereType<Map>().toList();
    final activeId =
        house['penghunian_aktif_id'] ?? history.where((stay) => stay['selesai_tanggal'] == null && stay['id'] != null).map((stay) => stay['id']).firstOrNull;
    final pemilik = house['pemilik'];
    final pemilikNama = pemilik is Map ? '${pemilik['nama'] ?? ''}' : '';
    final hunian = hunianLabel(family?['jenis_hunian']);
    final start = house['mulai_tagih'] == null ? '' : periode('${house['mulai_tagih']}');
    final billing = house['tagihan_aktif'] == true ? 'Tagihan aktif${start.isEmpty ? '' : ' sejak $start'}' : 'Tidak ditagih';
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        HousePlate(
          kode: '${house['kode_rumah'] ?? 'Rumah'}',
          alamat: '${house['alamat'] ?? ''}',
          hunian: hunian.isEmpty ? null : hunian,
          footer: archived ? 'Rumah ini diarsipkan. $billing' : billing,
        ),
        const SizedBox(height: 8),
        Text(
          pemilikNama.isEmpty ? 'Belum ada pemilik tercatat. Pemilik terpisah dari penghuni.' : 'Pemilik tercatat: $pemilikNama',
          style: const TextStyle(color: Brand.muted, height: 1.35),
        ),
        const SizedBox(height: 16),
        SectionCard(
          title: 'Keluarga',
          child: family == null
              ? const Text('Belum ada keluarga di rumah ini.', style: TextStyle(color: Brand.muted, height: 1.35))
              : Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    StatusChip(
                      family['status'] == 'terverifikasi' ? 'aktif' : 'menunggu',
                      label: family['status'] == 'terverifikasi' ? 'Terverifikasi' : 'Perlu verifikasi',
                    ),
                    const SizedBox(height: 8),
                    if (members.isEmpty)
                      const Text('Belum ada anggota tercatat.', style: TextStyle(color: Brand.muted))
                    else
                      for (final member in members)
                        Padding(
                          padding: const EdgeInsets.symmetric(vertical: 6),
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text('${member['nama']}', style: const TextStyle(fontWeight: FontWeight.w700)),
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
                    if (!archived && family['status'] != 'terverifikasi') ...[
                      const SizedBox(height: 8),
                      BusyButton(
                        label: 'Verifikasi data keluarga',
                        icon: Icons.verified_outlined,
                        outlined: true,
                        onPressed: () async {
                          try {
                            final versi = readVersi(family);
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
                  ],
                ),
        ),
        const SizedBox(height: 16),
        SectionCard(
          title: 'Riwayat hunian',
          child: history.isEmpty
              ? const Text('Belum ada riwayat hunian.', style: TextStyle(color: Brand.muted, height: 1.35))
              : Column(
                  children: [for (final stay in history) _StayTile(stay: stay, currentFamilyId: family?['id'])],
                ),
        ),
        if (!archived) ...[
          const SizedBox(height: 16),
          if (activeId == null)
            OutlinedButton(
              onPressed: () async {
                final changed = await Navigator.push<bool>(
                  context,
                  MaterialPageRoute(
                    builder: (_) => OccupancyBeginPage(rumahId: '${house['id']}', rumahLabel: '${house['kode_rumah'] ?? 'Rumah'}'),
                  ),
                );
                if (changed == true) onChanged();
              },
              child: const Text('Mulai hunian', textAlign: TextAlign.center),
            )
          else ...[
            OutlinedButton(
              onPressed: () async {
                final changed = await Navigator.push<bool>(context, MaterialPageRoute(builder: (_) => OccupancyEndPage(occupancyId: '$activeId')));
                if (changed == true) onChanged();
              },
              child: const Text('Akhiri hunian', textAlign: TextAlign.center),
            ),
            const SizedBox(height: 8),
            OutlinedButton(
              onPressed: () async {
                final changed = await Navigator.push<bool>(
                  context,
                  MaterialPageRoute(
                    builder: (_) => OccupancyMovePage(occupancyId: '$activeId', currentHouseId: '${house['id']}'),
                  ),
                );
                if (changed == true) onChanged();
              },
              child: const Text('Pindah rumah', textAlign: TextAlign.center),
            ),
          ],
          const SizedBox(height: 8),
          OutlinedButton(
            onPressed: () async {
              final changed = await Navigator.push<bool>(context, MaterialPageRoute(builder: (_) => HouseAddressPage(house: house)));
              if (changed == true) onChanged();
            },
            child: const Text('Ubah alamat', textAlign: TextAlign.center),
          ),
          const SizedBox(height: 8),
          OutlinedButton(
            onPressed: () async {
              final changed = await Navigator.push<bool>(context, MaterialPageRoute(builder: (_) => HouseOwnerPage(house: house)));
              if (changed == true) onChanged();
            },
            child: const Text('Ubah pemilik', textAlign: TextAlign.center),
          ),
          const SizedBox(height: 8),
          OutlinedButton(
            onPressed: () async {
              final changed = await Navigator.push<bool>(context, MaterialPageRoute(builder: (_) => HouseBillingPage(house: house)));
              if (changed == true) onChanged();
            },
            child: const Text('Ubah tagihan'),
          ),
          const SizedBox(height: 8),
          OutlinedButton(
            onPressed: () async {
              final changed = await Navigator.push<bool>(context, MaterialPageRoute(builder: (_) => ArchiveHousePage(house: house)));
              if (changed == true && context.mounted) Navigator.pop(context);
            },
            child: const Text('Arsipkan rumah'),
          ),
        ],
      ],
    );
  }
}

class _StayTile extends StatelessWidget {
  const _StayTile({required this.stay, required this.currentFamilyId});

  final Map stay;
  final Object? currentFamilyId;

  @override
  Widget build(BuildContext context) {
    final ended = stay['selesai_tanggal'];
    final start = tanggalHari(stay['mulai_tanggal']);
    final finish = tanggalHari(ended);
    final current = ended == null;
    final mine = stay['keluarga_id'] != null && stay['keluarga_id'] == currentFamilyId;
    final kind = hunianLabel(stay['jenis_hunian']);
    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(current ? Icons.home_rounded : Icons.history, color: Brand.navy),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(mine ? 'Keluarga di rumah ini' : 'Penghuni sebelumnya', style: const TextStyle(fontWeight: FontWeight.w700)),
                Text(
                  [
                    if (kind.isNotEmpty) kind,
                    if (start.isNotEmpty) 'sejak $start',
                    if (current) 'masih menghuni' else if (finish.isNotEmpty) 'sampai $finish',
                  ].join(' • '),
                  style: const TextStyle(color: Brand.muted, height: 1.3),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class HouseCreatePage extends ConsumerStatefulWidget {
  const HouseCreatePage({super.key});

  @override
  ConsumerState<HouseCreatePage> createState() => _HouseCreatePageState();
}

class _HouseCreatePageState extends ConsumerState<HouseCreatePage> {
  final _jalan = TextEditingController();
  final _nomor = TextEditingController();
  final _blok = TextEditingController();
  final _kode = TextEditingController();
  final _mulai = TextEditingController();
  var _tagihan = true;
  var _busy = false;
  final _errors = <String, String>{};
  String? _formError;

  @override
  void dispose() {
    _jalan.dispose();
    _nomor.dispose();
    _blok.dispose();
    _kode.dispose();
    _mulai.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    if (_busy) return;
    final errors = <String, String>{};
    if (_jalan.text.trim().isEmpty) errors['jalan'] = 'Jalan wajib diisi.';
    if (_jalan.text.trim().length > 150) errors['jalan'] = 'Jalan paling panjang 150 karakter.';
    if (_nomor.text.trim().isEmpty) errors['nomor'] = 'Nomor wajib diisi.';
    if (_nomor.text.trim().length > 20) errors['nomor'] = 'Nomor paling panjang 20 karakter.';
    if (_blok.text.trim().length > 30) errors['blok'] = 'Blok paling panjang 30 karakter.';
    final code = houseCodeError(_kode.text);
    final month = billingMonthError(_mulai.text);
    if (code != null) errors['kode'] = code;
    if (month != null) errors['mulai'] = month;
    if (errors.isNotEmpty) {
      setState(() {
        _errors
          ..clear()
          ..addAll(errors);
        _formError = null;
      });
      return;
    }
    setState(() => _busy = true);
    try {
      await ref
          .read(apiProvider)
          .post(
            '/admin/houses',
            data: houseCreateBody(blok: _blok.text, jalan: _jalan.text, nomor: _nomor.text, kode: _kode.text, tagihanAktif: _tagihan, mulaiTagih: _mulai.text),
          );
      if (!mounted) return;
      Navigator.pop(context, true);
    } on ApiException catch (error) {
      if (!mounted) return;
      setState(() {
        _errors
          ..clear()
          ..addAll({for (final entry in error.fields.entries) entry.key: entry.value.join(' ')});
        _formError = error.fields.isEmpty ? error.display : null;
      });
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const Text('Rumah baru')),
    body: ListView(
      keyboardDismissBehavior: ScrollViewKeyboardDismissBehavior.onDrag,
      padding: const EdgeInsets.all(16),
      children: [
        TextField(
          controller: _jalan,
          maxLength: 150,
          decoration: InputDecoration(labelText: 'Jalan', errorText: _errors['jalan'], errorMaxLines: 3),
        ),
        const SizedBox(height: 8),
        TextField(
          controller: _nomor,
          maxLength: 20,
          decoration: InputDecoration(labelText: 'Nomor', errorText: _errors['nomor'], errorMaxLines: 3),
        ),
        const SizedBox(height: 8),
        TextField(
          controller: _blok,
          maxLength: 30,
          decoration: InputDecoration(labelText: 'Blok', errorText: _errors['blok'], errorMaxLines: 3),
        ),
        const SizedBox(height: 8),
        TextField(
          controller: _kode,
          maxLength: 30,
          textCapitalization: TextCapitalization.characters,
          decoration: InputDecoration(
            labelText: 'Kode rumah',
            helperText: 'Kosongkan bila ingin kode dari blok dan nomor.',
            helperMaxLines: 3,
            errorText: _errors['kode_rumah'] ?? _errors['kode'],
            errorMaxLines: 4,
          ),
        ),
        const SizedBox(height: 8),
        SwitchListTile(
          contentPadding: EdgeInsets.zero,
          title: const Text('Tagihan iuran aktif'),
          value: _tagihan,
          onChanged: _busy ? null : (value) => setState(() => _tagihan = value),
        ),
        TextField(
          controller: _mulai,
          maxLength: 7,
          decoration: InputDecoration(labelText: 'Mulai tagih', hintText: '2026-10', errorText: _errors['mulai_tagih'] ?? _errors['mulai'], errorMaxLines: 3),
        ),
        if (_formError != null) ...[const SizedBox(height: 8), Text(_formError!, style: const TextStyle(color: Brand.danger, height: 1.35))],
        const SizedBox(height: 16),
        BusyButton(label: 'Simpan rumah', onPressed: _busy ? null : _save),
      ],
    ),
  );
}

class HouseBillingPage extends ConsumerStatefulWidget {
  const HouseBillingPage({super.key, required this.house});

  final Json house;

  @override
  ConsumerState<HouseBillingPage> createState() => _HouseBillingPageState();
}

class _HouseBillingPageState extends ConsumerState<HouseBillingPage> {
  late bool _aktif;
  late final TextEditingController _mulai;
  late final TextEditingController _alasan;
  late int? _versi;
  var _busy = false;
  String? _monthError;
  String? _reasonError;
  String? _formError;
  String? _review;

  @override
  void initState() {
    super.initState();
    _aktif = widget.house['tagihan_aktif'] == true;
    _mulai = TextEditingController(text: '${widget.house['mulai_tagih'] ?? ''}');
    _alasan = TextEditingController();
    _versi = readVersi(widget.house);
  }

  @override
  void dispose() {
    _mulai.dispose();
    _alasan.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    if (_busy) return;
    final monthError = billingMonthError(_mulai.text);
    final reason = reasonError(_alasan.text);
    final versi = _versi;
    if (monthError != null || reason != null || versi == null) {
      setState(() {
        _monthError = monthError;
        _reasonError = reason;
        _formError = versi == null && monthError == null && reason == null ? versiMissingMessage : null;
      });
      return;
    }
    setState(() => _busy = true);
    try {
      await ref
          .read(apiProvider)
          .patch(
            '/admin/houses/${widget.house['id']}',
            data: houseBillingBody(tagihanAktif: _aktif, mulaiTagih: _mulai.text.trim().isEmpty ? '' : _mulai.text, alasan: _alasan.text, versi: _versi),
          );
      if (!mounted) return;
      Navigator.pop(context, true);
    } on ApiException catch (error) {
      if (!mounted) return;
      if (staleConflict(error)) {
        setState(() => _formError = conflictKeptMessage(error.message));
        try {
          final data = await ref.read(apiProvider).get('/admin/houses/${widget.house['id']}');
          if (mounted && data is Map) {
            final fresh = Json.from(data);
            final next = readVersi(fresh);
            setState(() {
              if (next != null) _versi = next;
              _review = reviewLine(fresh['alamat']);
            });
          }
        } catch (_) {}
        return;
      }
      setState(() {
        _monthError = error.fields['mulai_tagih']?.join(' ');
        _reasonError = error.fields['alasan']?.join(' ');
        _formError = (_monthError == null && _reasonError == null) ? error.display : null;
      });
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const Text('Tagihan rumah')),
    body: ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Text('Rumah ${widget.house['kode_rumah']}. Perubahan ini tidak mengubah tagihan yang sudah terbit.', style: const TextStyle(height: 1.35)),
        const SizedBox(height: 12),
        SwitchListTile(
          contentPadding: EdgeInsets.zero,
          title: const Text('Tagihan iuran aktif'),
          value: _aktif,
          onChanged: _busy ? null : (value) => setState(() => _aktif = value),
        ),
        TextField(
          controller: _mulai,
          maxLength: 7,
          decoration: InputDecoration(labelText: 'Mulai tagih', hintText: '2026-10', errorText: _monthError, errorMaxLines: 3),
        ),
        const SizedBox(height: 8),
        TextField(
          controller: _alasan,
          maxLength: 300,
          minLines: 2,
          maxLines: 4,
          decoration: InputDecoration(labelText: 'Alasan', errorText: _reasonError, errorMaxLines: 4),
        ),
        if (_review != null) ...[const SizedBox(height: 8), Text(_review!, style: const TextStyle(height: 1.35))],
        if (_formError != null) ...[const SizedBox(height: 8), Text(_formError!, style: const TextStyle(color: Brand.danger, height: 1.35))],
        const SizedBox(height: 16),
        BusyButton(label: 'Simpan tagihan', onPressed: _busy ? null : _save),
      ],
    ),
  );
}

class ArchiveHousePage extends ConsumerStatefulWidget {
  const ArchiveHousePage({super.key, required this.house});

  final Json house;

  @override
  ConsumerState<ArchiveHousePage> createState() => _ArchiveHousePageState();
}

class _ArchiveHousePageState extends ConsumerState<ArchiveHousePage> {
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
      title: 'Arsipkan rumah ${widget.house['kode_rumah']}?',
      message: 'Hunian yang masih berjalan ditutup. Tagihan dan pembayaran yang sudah tercatat tetap ada.',
      yes: 'Arsipkan',
    );
    if (!ok || !mounted) return;
    setState(() => _busy = true);
    try {
      await ref.read(apiProvider).post('/admin/houses/${widget.house['id']}/archive', data: {'alasan': _alasan.text.trim()});
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
    appBar: AppBar(title: const Text('Arsip rumah')),
    body: ListView(
      padding: const EdgeInsets.all(16),
      children: [
        const Text('Rumah arsip tidak dihapus. Histori iuran tetap dapat dibaca pengurus.', style: TextStyle(height: 1.35)),
        const SizedBox(height: 12),
        TextField(
          controller: _alasan,
          maxLength: 300,
          minLines: 2,
          maxLines: 4,
          decoration: InputDecoration(labelText: 'Alasan', errorText: _error, errorMaxLines: 4),
        ),
        const SizedBox(height: 16),
        BusyButton(label: 'Arsipkan rumah', onPressed: _busy ? null : _save),
      ],
    ),
  );
}

class ArchiveAccountPage extends ConsumerStatefulWidget {
  const ArchiveAccountPage({super.key, required this.akun});

  final Json akun;

  @override
  ConsumerState<ArchiveAccountPage> createState() => _ArchiveAccountPageState();
}

class _ArchiveAccountPageState extends ConsumerState<ArchiveAccountPage> {
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
      title: 'Arsipkan akun ${widget.akun['nama']}?',
      message: 'Akun tidak dapat masuk lagi. Tautan ke keluarga ditutup. Histori tetap tersimpan.',
      yes: 'Arsipkan',
    );
    if (!ok || !mounted) return;
    setState(() => _busy = true);
    try {
      await ref.read(apiProvider).post('/admin/accounts/${widget.akun['id']}/archive', data: {'alasan': _alasan.text.trim()});
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
    appBar: AppBar(title: const Text('Arsip akun')),
    body: ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Text('${widget.akun['nama']}', style: Theme.of(context).textTheme.titleMedium),
        Text('${widget.akun['email']}', style: const TextStyle(color: Brand.muted)),
        const SizedBox(height: 12),
        const Text('Arsip bersifat final. Peran tidak diubah dari aplikasi.', style: TextStyle(height: 1.35)),
        const SizedBox(height: 12),
        TextField(
          controller: _alasan,
          maxLength: 300,
          minLines: 2,
          maxLines: 4,
          decoration: InputDecoration(labelText: 'Alasan', errorText: _error, errorMaxLines: 4),
          onChanged: (_) {
            if (_error != null) setState(() => _error = null);
          },
        ),
        const SizedBox(height: 16),
        SizedBox(
          width: double.infinity,
          child: FilledButton(
            onPressed: _busy ? null : _save,
            child: Text(_busy ? 'Menyimpan…' : 'Arsipkan akun', textAlign: TextAlign.center),
          ),
        ),
      ],
    ),
  );
}

class AccountsAdminView extends ConsumerStatefulWidget {
  const AccountsAdminView({super.key});

  @override
  ConsumerState<AccountsAdminView> createState() => _AccountsAdminViewState();
}

class _AccountsAdminViewState extends ConsumerState<AccountsAdminView> {
  String _status = '';
  String _query = '';

  @override
  Widget build(BuildContext context) {
    final mine = ref.watch(sessionProvider).akun?.id;
    final data = ref.watch(accountsProvider((status: _status, q: _query, page: 1)));
    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        TextField(
          decoration: const InputDecoration(prefixIcon: Icon(Icons.search), labelText: 'Cari nama atau email'),
          onSubmitted: (value) => setState(() => _query = value.trim()),
        ),
        const SizedBox(height: 8),
        Wrap(
          spacing: 8,
          runSpacing: 8,
          children: [
            for (final choice in const [('Semua', ''), ('Aktif', 'aktif'), ('Nonaktif', 'nonaktif'), ('Menunggu', 'menunggu'), ('Arsip', 'diarsipkan')])
              ChoiceChip(label: Text(choice.$1), selected: _status == choice.$2, onSelected: (_) => setState(() => _status = choice.$2)),
          ],
        ),
        const SizedBox(height: 8),
        const Text('Setiap pengurus memiliki hak yang sama. Peran tidak diubah dari aplikasi.', style: TextStyle(color: Brand.muted, height: 1.35)),
        const SizedBox(height: 12),
        AsyncView(
          value: data,
          onRetry: () => ref.invalidate(accountsProvider((status: _status, q: _query, page: 1))),
          builder: (page) {
            if (page.rows.isEmpty) {
              return const Padding(
                padding: EdgeInsets.symmetric(vertical: 24),
                child: Text(
                  'Tidak ada akun pada saringan ini.',
                  textAlign: TextAlign.center,
                  style: TextStyle(color: Brand.muted),
                ),
              );
            }
            return AppendedPage(
              key: ValueKey('akun|$_status|$_query'),
              first: page,
              load: (n) => ref.read(accountsProvider((status: _status, q: _query, page: n)).future),
              builder: (context, rows) => Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  for (final akun in rows)
                    _AccountTile(
                      akun: akun,
                      self: mine != null && akun['id'] == mine,
                      onChanged: () => ref.invalidate(accountsProvider((status: _status, q: _query, page: 1))),
                    ),
                ],
              ),
            );
          },
        ),
      ],
    );
  }
}

class _AccountTile extends StatelessWidget {
  const _AccountTile({required this.akun, required this.self, required this.onChanged});

  final Json akun;
  final bool self;
  final VoidCallback onChanged;

  @override
  Widget build(BuildContext context) {
    final status = '${akun['status']}';
    final pending = status == 'menunggu';
    final archived = status == 'diarsipkan';
    final active = status == 'aktif';
    final pengurus = akun['peran'] == 'pengurus';
    return Card(
      margin: const EdgeInsets.only(bottom: 8),
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            InkWell(
              onTap: () => Navigator.push<void>(context, MaterialPageRoute(builder: (_) => AccountDetailPage(id: '${akun['id']}'))),
              child: Text(
                '${akun['nama']}',
                style: const TextStyle(fontWeight: FontWeight.w700, height: 1.25, color: Brand.navy),
              ),
            ),
            Text('${akun['email']}', style: const TextStyle(color: Brand.muted)),
            TextButton(
              onPressed: () => Navigator.push<void>(context, MaterialPageRoute(builder: (_) => AccountDetailPage(id: '${akun['id']}'))),
              style: TextButton.styleFrom(
                padding: EdgeInsets.zero,
                minimumSize: Size.zero,
                tapTargetSize: MaterialTapTargetSize.shrinkWrap,
                alignment: Alignment.centerLeft,
              ),
              child: const Text('Riwayat akun', textAlign: TextAlign.start),
            ),
            const SizedBox(height: 8),
            Wrap(
              spacing: 6,
              runSpacing: 6,
              children: [
                StatusChip(
                  status == 'aktif'
                      ? 'aktif'
                      : status == 'nonaktif'
                      ? 'nonaktif'
                      : 'menunggu',
                  label: _statusLabel(status),
                ),
                StatusChip('x', label: akun['peran'] == 'pengurus' ? 'Pengurus' : 'Warga'),
                StatusChip('x', label: akun['jenis'] == 'utama' ? 'Akun utama' : 'Akun tambahan'),
              ],
            ),
            const SizedBox(height: 8),
            if (self)
              const Text('Status akun sendiri tidak diubah dari perangkat ini.', style: TextStyle(color: Brand.muted, height: 1.35))
            else if (pending)
              const Text('Akun menunggu diproses melalui permohonan.', style: TextStyle(color: Brand.muted, height: 1.35))
            else if (archived)
              const Text('Akun ini sudah diarsipkan.', style: TextStyle(color: Brand.muted, height: 1.35))
            else
              Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  OutlinedButton(
                    onPressed: () async {
                      final changed = await Navigator.push<bool>(
                        context,
                        MaterialPageRoute(
                          builder: (_) => AccountStatusPage(akun: akun, status: active ? 'nonaktif' : 'aktif'),
                        ),
                      );
                      if (changed == true) onChanged();
                    },
                    child: Text(active ? 'Nonaktifkan' : 'Aktifkan kembali', textAlign: TextAlign.center),
                  ),
                  if (!pengurus) ...[
                    const SizedBox(height: 8),
                    OutlinedButton(
                      onPressed: () async {
                        final changed = await Navigator.push<bool>(context, MaterialPageRoute(builder: (_) => ArchiveAccountPage(akun: akun)));
                        if (changed == true) onChanged();
                      },
                      child: const Text('Arsipkan akun', textAlign: TextAlign.center),
                    ),
                  ] else
                    const Padding(
                      padding: EdgeInsets.only(top: 8),
                      child: Text('Akun pengurus tidak diarsipkan dari aplikasi.', style: TextStyle(color: Brand.muted, height: 1.35)),
                    ),
                ],
              ),
          ],
        ),
      ),
    );
  }

  String _statusLabel(String status) => switch (status) {
    'aktif' => 'Aktif',
    'nonaktif' => 'Nonaktif',
    'menunggu' => 'Menunggu',
    'diarsipkan' => 'Arsip',
    _ => status,
  };
}

class AccountStatusPage extends ConsumerStatefulWidget {
  const AccountStatusPage({super.key, required this.akun, required this.status});

  final Json akun;
  final String status;

  @override
  ConsumerState<AccountStatusPage> createState() => _AccountStatusPageState();
}

class _AccountStatusPageState extends ConsumerState<AccountStatusPage> {
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
    final turningOff = widget.status == 'nonaktif';
    final ok = await confirm(
      context,
      title: turningOff ? 'Nonaktifkan akun?' : 'Aktifkan akun?',
      message: turningOff ? '${widget.akun['nama']} tidak dapat masuk sampai diaktifkan kembali.' : '${widget.akun['nama']} dapat masuk kembali.',
      yes: turningOff ? 'Nonaktifkan' : 'Aktifkan',
    );
    if (!ok || !mounted) return;
    setState(() => _busy = true);
    try {
      await ref
          .read(apiProvider)
          .patch(
            '/admin/accounts/${widget.akun['id']}',
            data: accountStatusBody(status: widget.status, alasan: _alasan.text),
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
    appBar: AppBar(title: Text(widget.status == 'nonaktif' ? 'Nonaktifkan akun' : 'Aktifkan akun')),
    body: ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Text('${widget.akun['nama']}', style: Theme.of(context).textTheme.titleMedium),
        Text('${widget.akun['email']}', style: const TextStyle(color: Brand.muted)),
        const SizedBox(height: 12),
        TextField(
          controller: _alasan,
          maxLength: 300,
          minLines: 2,
          maxLines: 4,
          decoration: InputDecoration(labelText: 'Alasan', errorText: _error, errorMaxLines: 4),
        ),
        const SizedBox(height: 16),
        BusyButton(label: widget.status == 'nonaktif' ? 'Nonaktifkan' : 'Aktifkan kembali', onPressed: _busy ? null : _save),
      ],
    ),
  );
}
