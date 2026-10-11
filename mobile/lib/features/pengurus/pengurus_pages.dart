import 'dart:math';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/theme.dart';
import '../../core/api.dart';
import '../../core/format.dart';
import '../../core/models.dart';
import '../../core/widgets.dart';
import '../warga/providers.dart';
import '../warga/warga_pages.dart';

/// UUID v4 untuk Idempotency-Key; dibuat sekali per niat transaksi dan dipakai ulang saat retry.
String newKey() {
  final r = Random.secure();
  final b = List<int>.generate(16, (_) => r.nextInt(256));
  b[6] = (b[6] & 0x0f) | 0x40;
  b[8] = (b[8] & 0x3f) | 0x80;
  final h = b.map((x) => x.toRadixString(16).padLeft(2, '0')).join();
  return '${h.substring(0, 8)}-${h.substring(8, 12)}-${h.substring(12, 16)}-${h.substring(16, 20)}-${h.substring(20)}';
}

final dashboardProvider = FutureProvider.autoDispose<Json>((ref) async => Json.from(await ref.read(apiProvider).get('/admin/dashboard') as Map));
final housesProvider = FutureProvider.autoDispose.family<List<Json>, String>(
  (ref, q) async => ((await ref.read(apiProvider).page('/admin/houses', query: {'per_page': 100, if (q.isNotEmpty) 'q': q}))['data'] as List)
      .cast<Map>()
      .map(Json.from)
      .toList(),
);
final houseDuesProvider = FutureProvider.autoDispose.family<Json, String>(
  (ref, id) async => Json.from(await ref.read(apiProvider).get('/admin/houses/$id/dues') as Map),
);
final requestsProvider = FutureProvider.autoDispose<List<Json>>(
  (ref) async => ((await ref.read(apiProvider).page('/admin/additional-account-requests'))['data'] as List).cast<Map>().map(Json.from).toList(),
);
final tokenProvider = FutureProvider.autoDispose<Json>((ref) async => Json.from(await ref.read(apiProvider).get('/admin/registration-token') as Map));
final paymentsProvider = FutureProvider.autoDispose<List<Json>>(
  (ref) async => ((await ref.read(apiProvider).page('/admin/dues-payments', query: {'per_page': 50}))['data'] as List).cast<Map>().map(Json.from).toList(),
);

// ─────────────────────────────────────────── Ringkasan
class AdminHomePage extends ConsumerWidget {
  const AdminHomePage({super.key, required this.goTab});
  final void Function(int) goTab;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final d = ref.watch(dashboardProvider);
    return RefreshIndicator(
      onRefresh: () async => ref.invalidate(dashboardProvider),
      child: AsyncView(
        value: d,
        onRetry: () => ref.invalidate(dashboardProvider),
        builder: (d) {
          final iuran = d['iuran_bulan_ini'] as Map;
          final p = Map<String, dynamic>.from(d['pengaduan'] as Map? ?? {});
          final a = Map<String, dynamic>.from(d['aspirasi'] as Map? ?? {});
          return ListView(
            padding: const EdgeInsets.all(16),
            children: [
              Container(
                padding: const EdgeInsets.all(20),
                decoration: BoxDecoration(color: Brand.navy, borderRadius: BorderRadius.circular(20)),
                child: Row(
                  children: [
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          const Text('Saldo kas RT', style: TextStyle(color: Colors.white70)),
                          Text(rupiah(d['saldo_kas'] as num), style: Theme.of(context).textTheme.headlineMedium?.copyWith(color: Colors.white)),
                          const SizedBox(height: 6),
                          Text(
                            'Iuran ${periode('${iuran['periode']}')}: ${iuran['lunas']} lunas, ${iuran['belum_bayar']} belum',
                            style: const TextStyle(color: Brand.yellow),
                          ),
                        ],
                      ),
                    ),
                    const GapuraMark(size: 52, light: true),
                  ],
                ),
              ),
              const SizedBox(height: 16),
              // Dua kolom dengan tinggi mengikuti isi (bukan rasio tetap) agar aman pada skala teks besar.
              _TileRow(
                _Tile('Rumah aktif', '${d['rumah_aktif']}', Icons.home_work_outlined, () => goTab(1)),
                _Tile(
                  'Permohonan akun',
                  '${d['permohonan_menunggu']}',
                  Icons.how_to_reg_outlined,
                  () => goTab(1),
                  alert: (d['permohonan_menunggu'] as num) > 0,
                ),
              ),
              const SizedBox(height: 10),
              _TileRow(
                _Tile('Pengaduan baru', '${p['diajukan'] ?? 0}', Icons.report_outlined, () => goTab(2), alert: (p['diajukan'] ?? 0) > 0),
                _Tile('Aspirasi baru', '${a['diajukan'] ?? 0}', Icons.lightbulb_outline, () => goTab(2), alert: (a['diajukan'] ?? 0) > 0),
              ),
              const SizedBox(height: 16),
              BusyButton(
                label: 'Catat pembayaran iuran',
                icon: Icons.point_of_sale,
                onPressed: () async {
                  await Navigator.push(context, MaterialPageRoute(builder: (_) => const RecordPaymentPage()));
                  ref.invalidate(dashboardProvider);
                },
              ),
              const SizedBox(height: 10),
              BusyButton(
                label: 'Token registrasi RT',
                icon: Icons.key_outlined,
                outlined: true,
                onPressed: () async {
                  await Navigator.push(context, MaterialPageRoute(builder: (_) => const TokenPage()));
                },
              ),
            ],
          );
        },
      ),
    );
  }
}

class _TileRow extends StatelessWidget {
  const _TileRow(this.left, this.right);
  final Widget left;
  final Widget right;

  @override
  Widget build(BuildContext context) => IntrinsicHeight(
    child: Row(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Expanded(child: left),
        const SizedBox(width: 10),
        Expanded(child: right),
      ],
    ),
  );
}

class _Tile extends StatelessWidget {
  const _Tile(this.label, this.value, this.icon, this.onTap, {this.alert = false});
  final String label;
  final String value;
  final IconData icon;
  final VoidCallback onTap;
  final bool alert;

  @override
  Widget build(BuildContext context) => Card(
    child: InkWell(
      borderRadius: BorderRadius.circular(16),
      onTap: onTap,
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          mainAxisSize: MainAxisSize.min,
          children: [
            Row(
              children: [
                Icon(icon, color: Brand.navy),
                const Spacer(),
                if (alert) const Icon(Icons.circle, size: 10, color: Brand.danger),
              ],
            ),
            const SizedBox(height: 6),
            Text(value, style: const TextStyle(fontSize: 24, fontWeight: FontWeight.w800)),
            Text(label, style: const TextStyle(color: Brand.muted)),
          ],
        ),
      ),
    ),
  );
}

// ─────────────────────────────────────────── Token registrasi
class TokenPage extends ConsumerWidget {
  const TokenPage({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(tokenProvider);
    return Scaffold(
      appBar: AppBar(title: const Text('Token registrasi')),
      body: AsyncView(
        value: t,
        onRetry: () => ref.invalidate(tokenProvider),
        builder: (d) => ListView(
          padding: const EdgeInsets.all(16),
          children: [
            SectionCard(
              child: Column(
                children: [
                  const Text(
                    'Bagikan token ini hanya kepada warga RT 05. Token tidak memberi hak pengurus.',
                    textAlign: TextAlign.center,
                    style: TextStyle(color: Brand.muted),
                  ),
                  const SizedBox(height: 16),
                  SelectableText(
                    '${d['kode']}',
                    style: const TextStyle(fontSize: 44, letterSpacing: 10, fontWeight: FontWeight.w800, color: Brand.navy),
                  ),
                  const SizedBox(height: 6),
                  Text('Berlaku sampai ${tanggal(parseTime(d['berlaku_sampai']), jam: true)}', style: const TextStyle(color: Brand.muted)),
                  const SizedBox(height: 16),
                  BusyButton(
                    label: 'Salin token',
                    icon: Icons.copy,
                    onPressed: () async {
                      await Clipboard.setData(ClipboardData(text: '${d['kode']}'));
                      if (context.mounted) showMessage(context, 'Token disalin.');
                    },
                  ),
                  const SizedBox(height: 10),
                  BusyButton(
                    label: 'Rotasi sekarang',
                    icon: Icons.autorenew,
                    outlined: true,
                    onPressed: () async {
                      if (!await confirm(context, title: 'Rotasi token?', message: 'Token lama langsung tidak berlaku.')) return;
                      await ref.read(apiProvider).post('/admin/registration-token/rotate');
                      ref.invalidate(tokenProvider);
                    },
                  ),
                ],
              ),
            ),
            const SizedBox(height: 8),
            const Text(
              'Token juga dirotasi otomatis setiap Senin.',
              textAlign: TextAlign.center,
              style: TextStyle(color: Brand.muted, fontSize: 13),
            ),
          ],
        ),
      ),
    );
  }
}

// ─────────────────────────────────────────── Warga: rumah & permohonan
class ResidentsAdminPage extends ConsumerStatefulWidget {
  const ResidentsAdminPage({super.key});
  @override
  ConsumerState<ResidentsAdminPage> createState() => _ResidentsAdminPageState();
}

class _ResidentsAdminPageState extends ConsumerState<ResidentsAdminPage> {
  String _q = '';

  @override
  Widget build(BuildContext context) => DefaultTabController(
    length: 2,
    child: Column(
      children: [
        const Material(
          color: Colors.white,
          child: TabBar(
            labelColor: Brand.navy,
            indicatorColor: Brand.yellow,
            indicatorWeight: 3,
            tabs: [
              Tab(text: 'Rumah & keluarga'),
              Tab(text: 'Permohonan akun'),
            ],
          ),
        ),
        Expanded(
          child: TabBarView(
            children: [
              ListView(
                padding: const EdgeInsets.all(16),
                children: [
                  TextField(
                    decoration: const InputDecoration(prefixIcon: Icon(Icons.search), labelText: 'Cari kode rumah / jalan'),
                    onSubmitted: (v) => setState(() => _q = v.trim()),
                  ),
                  const SizedBox(height: 12),
                  AsyncView(
                    value: ref.watch(housesProvider(_q)),
                    onRetry: () => ref.invalidate(housesProvider(_q)),
                    builder: (list) => list.isEmpty
                        ? const EmptyState(icon: Icons.home_outlined, title: 'Belum ada rumah terdaftar')
                        : Column(
                            children: [
                              for (final h in list)
                                Card(
                                  margin: const EdgeInsets.only(bottom: 8),
                                  child: ListTile(
                                    title: Text('${h['kode_rumah']} • ${h['alamat']}', style: const TextStyle(fontWeight: FontWeight.w700)),
                                    // Status di bawah subjudul (bukan trailing) agar judul alamat tetap lebar pada skala teks besar.
                                    subtitle: Column(
                                      crossAxisAlignment: CrossAxisAlignment.start,
                                      children: [
                                        Text(
                                          h['keluarga'] == null
                                              ? 'Belum ada keluarga aktif'
                                              : '${(h['keluarga'] as Map)['kepala'] ?? '-'} • ${(h['keluarga'] as Map)['jumlah_anggota']} anggota',
                                        ),
                                        if (h['keluarga'] != null)
                                          Padding(
                                            padding: const EdgeInsets.only(top: 6),
                                            child: StatusChip(
                                              (h['keluarga'] as Map)['status'] == 'terverifikasi' ? 'aktif' : 'menunggu',
                                              label: (h['keluarga'] as Map)['status'] == 'terverifikasi' ? 'Terverifikasi' : 'Perlu verifikasi',
                                            ),
                                          ),
                                      ],
                                    ),
                                    onTap: () async {
                                      await Navigator.push(context, MaterialPageRoute(builder: (_) => HouseAdminPage(h)));
                                      ref.invalidate(housesProvider(_q));
                                    },
                                  ),
                                ),
                            ],
                          ),
                  ),
                ],
              ),
              const _RequestsView(),
            ],
          ),
        ),
      ],
    ),
  );
}

class _RequestsView extends ConsumerWidget {
  const _RequestsView();

  @override
  Widget build(BuildContext context, WidgetRef ref) => RefreshIndicator(
    onRefresh: () async => ref.invalidate(requestsProvider),
    child: AsyncView(
      value: ref.watch(requestsProvider),
      onRetry: () => ref.invalidate(requestsProvider),
      builder: (list) => list.isEmpty
          ? ListView(
              children: const [EmptyState(icon: Icons.how_to_reg_outlined, title: 'Tidak ada permohonan menunggu')],
            )
          : ListView(
              padding: const EdgeInsets.all(16),
              children: [
                for (final p in list)
                  Card(
                    margin: const EdgeInsets.only(bottom: 10),
                    child: Padding(
                      padding: const EdgeInsets.all(14),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text('${p['nama_pemohon']} (${p['hubungan_keluarga'] ?? '-'})', style: Theme.of(context).textTheme.titleMedium),
                          Text('${p['email']}', style: const TextStyle(color: Brand.muted)),
                          const SizedBox(height: 6),
                          Text('Alamat diajukan: ${p['alamat_diajukan']}'),
                          Text(
                            p['rumah_cocok'] == null
                                ? 'Rumah tidak cocok dengan data — tolak atau minta pemohon mendaftar ulang.'
                                : 'Cocok dengan rumah ${(p['rumah_cocok'] as Map)['kode_rumah']}',
                            style: TextStyle(color: p['rumah_cocok'] == null ? Brand.danger : Brand.success),
                          ),
                          const SizedBox(height: 12),
                          Row(
                            children: [
                              Expanded(
                                child: BusyButton(
                                  label: 'Tolak',
                                  outlined: true,
                                  onPressed: () async {
                                    final reason = await askText(context, title: 'Alasan penolakan', label: 'Alasan (wajib)');
                                    if (reason == null) return;
                                    await ref.read(apiProvider).post('/admin/additional-account-requests/${p['id']}/reject', data: {'alasan': reason});
                                    ref.invalidate(requestsProvider);
                                  },
                                ),
                              ),
                              const SizedBox(width: 10),
                              Expanded(
                                child: BusyButton(
                                  label: 'Setujui',
                                  onPressed: p['rumah_cocok'] == null
                                      ? null
                                      : () async {
                                          try {
                                            await ref.read(apiProvider).post('/admin/additional-account-requests/${p['id']}/approve', data: {});
                                            ref.invalidate(requestsProvider);
                                            if (context.mounted) showMessage(context, 'Akun disetujui sebagai warga.');
                                          } on ApiException catch (e) {
                                            if (context.mounted) showMessage(context, e.display, error: true);
                                          }
                                        },
                                ),
                              ),
                            ],
                          ),
                        ],
                      ),
                    ),
                  ),
              ],
            ),
    ),
  );
}

class HouseAdminPage extends ConsumerWidget {
  const HouseAdminPage(this.house, {super.key});
  final Json house;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final id = '${house['id']}';
    final dues = ref.watch(houseDuesProvider(id));
    final fam = house['keluarga'] as Map?;
    return Scaffold(
      appBar: AppBar(title: Text('Rumah ${house['kode_rumah']}')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          SectionCard(
            title: '${house['alamat']}',
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('Tagihan: ${house['tagihan_aktif'] == true ? 'aktif sejak ${house['mulai_tagih'] ?? '-'}' : 'nonaktif'}'),
                if (fam != null) Text('Kepala keluarga: ${fam['kepala']} • ${fam['jumlah_anggota']} anggota'),
                if (fam != null && fam['status'] != 'terverifikasi') ...[
                  const SizedBox(height: 12),
                  BusyButton(
                    label: 'Verifikasi data keluarga',
                    icon: Icons.verified_outlined,
                    outlined: true,
                    onPressed: () async {
                      await ref.read(apiProvider).post('/admin/families/${fam['id']}/verify');
                      if (context.mounted) {
                        showMessage(context, 'Keluarga terverifikasi.');
                        Navigator.pop(context);
                      }
                    },
                  ),
                ],
              ],
            ),
          ),
          const SizedBox(height: 16),
          SectionCard(
            title: 'Iuran rumah',
            trailing: TextButton(
              onPressed: () async {
                await Navigator.push(context, MaterialPageRoute(builder: (_) => RecordPaymentPage(house: house)));
                ref.invalidate(houseDuesProvider(id));
              },
              child: const Text('Catat bayar'),
            ),
            child: AsyncView(
              value: dues,
              onRetry: () => ref.invalidate(houseDuesProvider(id)),
              builder: (d) => Column(
                children: [
                  for (final t in (d['tagihan'] as List).cast<Map>().map((m) => Tagihan(Json.from(m))))
                    ListTile(
                      dense: true,
                      contentPadding: EdgeInsets.zero,
                      title: Text(periode(t.periode)),
                      subtitle: Text(rupiah(t.nominal)),
                      trailing: StatusChip(t.status, label: t.label),
                    ),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}

// ─────────────────────────────────────────── Keuangan
class FinanceAdminPage extends ConsumerWidget {
  const FinanceAdminPage({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) => DefaultTabController(
    length: 3,
    child: Column(
      children: [
        const Material(
          color: Colors.white,
          child: TabBar(
            labelColor: Brand.navy,
            indicatorColor: Brand.yellow,
            indicatorWeight: 3,
            tabs: [
              Tab(text: 'Pembayaran'),
              Tab(text: 'Kas'),
              Tab(text: 'Transparansi'),
            ],
          ),
        ),
        Expanded(child: TabBarView(children: [_PaymentsView(), const _AdminCashView(), const TransparencyView()])),
      ],
    ),
  );
}

class _PaymentsView extends ConsumerWidget {
  @override
  Widget build(BuildContext context, WidgetRef ref) => Scaffold(
    backgroundColor: Brand.sky,
    floatingActionButton: FloatingActionButton.extended(
      backgroundColor: Brand.yellow,
      foregroundColor: Brand.navy,
      icon: const Icon(Icons.point_of_sale),
      label: const Text('Catat iuran'),
      onPressed: () async {
        await Navigator.push(context, MaterialPageRoute(builder: (_) => const RecordPaymentPage()));
        ref.invalidate(paymentsProvider);
      },
    ),
    body: RefreshIndicator(
      onRefresh: () async => ref.invalidate(paymentsProvider),
      child: AsyncView(
        value: ref.watch(paymentsProvider),
        onRetry: () => ref.invalidate(paymentsProvider),
        builder: (list) => list.isEmpty
            ? ListView(
                children: const [EmptyState(icon: Icons.receipt_long_outlined, title: 'Belum ada pembayaran tercatat')],
              )
            : ListView(
                padding: const EdgeInsets.fromLTRB(16, 16, 16, 96),
                children: [
                  for (final p in list)
                    Card(
                      margin: const EdgeInsets.only(bottom: 8),
                      child: ListTile(
                        title: Text('${p['nomor_bukti']} • ${(p['rumah'] as Map?)?['kode_rumah']}', style: const TextStyle(fontWeight: FontWeight.w700)),
                        subtitle: Text(
                          '${(p['periode'] as List).map((x) => periode('$x')).join(', ')}\n${rupiah(p['total_bayar'] as num)} • dibayar ${p['tanggal_bayar']}',
                        ),
                        isThreeLine: true,
                        trailing: StatusChip('${p['status']}'),
                        onLongPress: p['status'] != 'tercatat'
                            ? null
                            : () async {
                                final reason = await askText(
                                  context,
                                  title: 'Batalkan pembayaran ${p['nomor_bukti']}?',
                                  label: 'Alasan pembatalan (wajib)',
                                  hint: 'mis. salah rumah',
                                );
                                if (reason == null) return;
                                try {
                                  await ref
                                      .read(apiProvider)
                                      .post('/admin/dues-payments/${p['id']}/reverse', data: {'alasan': reason}, idempotencyKey: newKey());
                                  ref.invalidate(paymentsProvider);
                                  if (context.mounted) showMessage(context, 'Pembayaran dibalik; tagihan kembali belum dibayar.');
                                } on ApiException catch (e) {
                                  if (context.mounted) showMessage(context, e.display, error: true);
                                }
                              },
                      ),
                    ),
                  const Text(
                    'Tekan lama pembayaran untuk membatalkan (pembalikan tercatat, data asal tidak dihapus).',
                    textAlign: TextAlign.center,
                    style: TextStyle(color: Brand.muted, fontSize: 13),
                  ),
                ],
              ),
      ),
    ),
  );
}

class _AdminCashView extends ConsumerWidget {
  const _AdminCashView();

  @override
  Widget build(BuildContext context, WidgetRef ref) => Scaffold(
    backgroundColor: Brand.sky,
    floatingActionButton: FloatingActionButton.extended(
      backgroundColor: Brand.yellow,
      foregroundColor: Brand.navy,
      icon: const Icon(Icons.add),
      label: const Text('Catat kas'),
      onPressed: () async {
        await Navigator.push(context, MaterialPageRoute(builder: (_) => const CashEntryPage()));
        ref.invalidate(cashProvider);
      },
    ),
    body: const CashView(),
  );
}

/// Pencatatan pembayaran iuran offline: pilih rumah → centang bulan belum bayar →
/// pratinjau total dari snapshot server → konfirmasi. Idempotency-Key tetap selama layar terbuka.
class RecordPaymentPage extends ConsumerStatefulWidget {
  const RecordPaymentPage({super.key, this.house});
  final Json? house;
  @override
  ConsumerState<RecordPaymentPage> createState() => _RecordPaymentPageState();
}

class _RecordPaymentPageState extends ConsumerState<RecordPaymentPage> {
  Json? _house;
  final Set<String> _selected = {};
  final _note = TextEditingController();
  DateTime _paidOn = toJakarta(DateTime.now().toUtc());
  final String _key = newKey();

  @override
  void initState() {
    super.initState();
    _house = widget.house;
  }

  @override
  Widget build(BuildContext context) {
    final houses = ref.watch(housesProvider(''));
    return Scaffold(
      appBar: AppBar(title: const Text('Catat pembayaran iuran')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Container(
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(color: Brand.warningBg, borderRadius: BorderRadius.circular(12)),
            child: const Text('Catat hanya setelah uang diterima langsung (offline). Tidak ada pembayaran online.'),
          ),
          const SizedBox(height: 14),
          AsyncView(
            value: houses,
            onRetry: () => ref.invalidate(housesProvider('')),
            builder: (list) => DropdownButtonFormField<String>(
              initialValue: _house?['id'] as String?,
              isExpanded: true,
              decoration: const InputDecoration(labelText: 'Rumah'),
              items: [
                for (final h in list)
                  DropdownMenuItem(
                    value: '${h['id']}',
                    child: Text('${h['kode_rumah']} • ${h['alamat']}', overflow: TextOverflow.ellipsis),
                  ),
              ],
              onChanged: (v) => setState(() {
                _house = list.firstWhere((h) => h['id'] == v);
                _selected.clear();
              }),
            ),
          ),
          if (_house != null) ...[
            const SizedBox(height: 16),
            Text('Pilih bulan yang dibayar', style: Theme.of(context).textTheme.titleMedium),
            AsyncView(
              value: ref.watch(houseDuesProvider('${_house!['id']}')),
              onRetry: () => ref.invalidate(houseDuesProvider('${_house!['id']}')),
              builder: (d) {
                final unpaid = (d['tagihan'] as List).cast<Map>().map((m) => Tagihan(Json.from(m))).where((t) => !t.lunas).toList()
                  ..sort((a, b) => a.periode.compareTo(b.periode));
                final total = unpaid.where((t) => _selected.contains(t.periode)).fold<int>(0, (s, t) => s + t.nominal);
                if (unpaid.isEmpty) return const Padding(padding: EdgeInsets.all(16), child: Text('Semua tagihan rumah ini sudah lunas.'));
                return Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    for (final t in unpaid)
                      CheckboxListTile(
                        value: _selected.contains(t.periode),
                        onChanged: (v) => setState(() => v == true ? _selected.add(t.periode) : _selected.remove(t.periode)),
                        title: Text(periode(t.periode)),
                        subtitle: Text(rupiah(t.nominal)),
                      ),
                    const SizedBox(height: 8),
                    ListTile(
                      contentPadding: EdgeInsets.zero,
                      leading: const Icon(Icons.calendar_today_outlined),
                      title: Text('Tanggal diterima: ${_paidOn.day}/${_paidOn.month}/${_paidOn.year}'),
                      onTap: () async {
                        final picked = await showDatePicker(
                          context: context,
                          initialDate: _paidOn,
                          firstDate: DateTime(2025),
                          lastDate: toJakarta(DateTime.now().toUtc()),
                        );
                        if (picked != null) setState(() => _paidOn = picked);
                      },
                    ),
                    TextField(
                      controller: _note,
                      decoration: const InputDecoration(labelText: 'Catatan (opsional)'),
                    ),
                    const SizedBox(height: 16),
                    SectionCard(
                      child: Row(
                        children: [
                          const Expanded(child: Text('Total sesuai tagihan')),
                          Text(
                            rupiah(total),
                            style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w800, color: Brand.navy),
                          ),
                        ],
                      ),
                    ),
                    const SizedBox(height: 16),
                    BusyButton(
                      label: 'Simpan pembayaran',
                      icon: Icons.check,
                      onPressed: _selected.isEmpty
                          ? null
                          : () async {
                              final months = _selected.toList()..sort();
                              if (!await confirm(
                                context,
                                title: 'Konfirmasi pembayaran',
                                message: 'Rumah ${_house!['kode_rumah']}\n${months.map(periode).join(', ')}\nTotal ${rupiah(total)}',
                                yes: 'Simpan',
                              )) {
                                return;
                              }
                              try {
                                final res = await ref
                                    .read(apiProvider)
                                    .post(
                                      '/admin/dues-payments',
                                      idempotencyKey: _key,
                                      data: {
                                        'rumah_id': _house!['id'],
                                        'periode': months,
                                        'nominal': total,
                                        'tanggal_bayar':
                                            '${_paidOn.year}-${_paidOn.month.toString().padLeft(2, '0')}-${_paidOn.day.toString().padLeft(2, '0')}',
                                        'catatan': _note.text.trim().isEmpty ? null : _note.text.trim(),
                                      },
                                    );
                                ref.invalidate(houseDuesProvider('${_house!['id']}'));
                                if (context.mounted) {
                                  showMessage(context, 'Tercatat: ${(res as Map)['nomor_bukti']}');
                                  Navigator.pop(context);
                                }
                              } on ApiException catch (e) {
                                if (e.isUnknownOutcome) {
                                  // Kunci yang sama dipakai saat retry sehingga server memutar ulang hasil, tidak dobel.
                                  if (context.mounted) showMessage(context, '${e.display} Tekan Simpan lagi untuk memeriksa (aman, tidak dobel).', error: true);
                                } else {
                                  ref.invalidate(houseDuesProvider('${_house!['id']}'));
                                  if (context.mounted) showMessage(context, e.display, error: true);
                                }
                              }
                            },
                    ),
                  ],
                );
              },
            ),
          ],
        ],
      ),
    );
  }
}

class CashEntryPage extends ConsumerStatefulWidget {
  const CashEntryPage({super.key});
  @override
  ConsumerState<CashEntryPage> createState() => _CashEntryPageState();
}

class _CashEntryPageState extends ConsumerState<CashEntryPage> {
  String _jenis = 'pengeluaran';
  final _nominal = TextEditingController();
  final _kategori = TextEditingController();
  final _ket = TextEditingController();
  final String _key = newKey();

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const Text('Catat kas')),
    body: ListView(
      padding: const EdgeInsets.all(16),
      children: [
        SegmentedButton<String>(
          segments: const [
            ButtonSegment(value: 'pengeluaran', label: Text('Pengeluaran')),
            ButtonSegment(value: 'pemasukan', label: Text('Pemasukan lain')),
            ButtonSegment(value: 'saldo_awal', label: Text('Saldo awal')),
          ],
          selected: {_jenis},
          onSelectionChanged: (s) => setState(() => _jenis = s.first),
        ),
        const SizedBox(height: 8),
        const Text('Iuran warga dicatat lewat "Catat iuran", bukan di sini.', style: TextStyle(color: Brand.muted, fontSize: 13)),
        const SizedBox(height: 14),
        TextField(
          controller: _nominal,
          keyboardType: TextInputType.number,
          inputFormatters: [FilteringTextInputFormatter.digitsOnly],
          decoration: const InputDecoration(labelText: 'Nominal (Rp)'),
        ),
        const SizedBox(height: 14),
        TextField(
          controller: _kategori,
          decoration: const InputDecoration(labelText: 'Kategori', hintText: 'mis. Kebersihan, Keamanan, Donasi'),
        ),
        const SizedBox(height: 14),
        TextField(
          controller: _ket,
          maxLines: 3,
          decoration: const InputDecoration(labelText: 'Keterangan'),
        ),
        const SizedBox(height: 20),
        BusyButton(
          label: 'Simpan',
          onPressed: () async {
            final n = int.tryParse(_nominal.text) ?? 0;
            if (n <= 0 || _kategori.text.trim().isEmpty || _ket.text.trim().isEmpty) {
              showMessage(context, 'Lengkapi nominal, kategori, dan keterangan.', error: true);
              return;
            }
            if (!await confirm(
              context,
              title: 'Simpan transaksi kas?',
              message: '$_jenis ${rupiah(n)}\nTransaksi tidak dapat diedit; koreksi melalui pembalikan.',
            )) {
              return;
            }
            try {
              final now = toJakarta(DateTime.now().toUtc());
              await ref
                  .read(apiProvider)
                  .post(
                    '/admin/cash-transactions',
                    idempotencyKey: _key,
                    data: {
                      'jenis': _jenis,
                      'nominal': n,
                      'tanggal': '${now.year}-${now.month.toString().padLeft(2, '0')}-${now.day.toString().padLeft(2, '0')}',
                      'kategori': _kategori.text.trim(),
                      'keterangan': _ket.text.trim(),
                    },
                  );
              if (context.mounted) {
                showMessage(context, 'Transaksi kas tercatat.');
                Navigator.pop(context);
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

// ─────────────────────────────────────────── Lainnya: agenda & pengumuman
class MoreAdminPage extends ConsumerWidget {
  const MoreAdminPage({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) => ListView(
    padding: const EdgeInsets.all(16),
    children: [
      SectionCard(
        title: 'Komunitas',
        child: Column(
          children: [
            ListTile(
              contentPadding: EdgeInsets.zero,
              leading: const Icon(Icons.event_note_outlined, color: Brand.navy),
              title: const Text('Buat agenda'),
              subtitle: const Text('Warga menerima notifikasi dan pengingat H-1'),
              onTap: () => Navigator.push(context, MaterialPageRoute(builder: (_) => const AgendaFormPage())),
            ),
            ListTile(
              contentPadding: EdgeInsets.zero,
              leading: const Icon(Icons.campaign_outlined, color: Brand.navy),
              title: const Text('Terbitkan pengumuman'),
              onTap: () => Navigator.push(context, MaterialPageRoute(builder: (_) => const AnnouncementFormPage())),
            ),
            ListTile(
              contentPadding: EdgeInsets.zero,
              leading: const Icon(Icons.key_outlined, color: Brand.navy),
              title: const Text('Token registrasi'),
              onTap: () => Navigator.push(context, MaterialPageRoute(builder: (_) => const TokenPage())),
            ),
          ],
        ),
      ),
    ],
  );
}

class AgendaFormPage extends ConsumerStatefulWidget {
  const AgendaFormPage({super.key});
  @override
  ConsumerState<AgendaFormPage> createState() => _AgendaFormPageState();
}

class _AgendaFormPageState extends ConsumerState<AgendaFormPage> {
  final _nama = TextEditingController();
  final _lokasi = TextEditingController();
  final _desk = TextEditingController();
  DateTime _start = toJakarta(DateTime.now().toUtc()).add(const Duration(days: 1));

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const Text('Agenda baru')),
    body: ListView(
      padding: const EdgeInsets.all(16),
      children: [
        TextField(
          controller: _nama,
          decoration: const InputDecoration(labelText: 'Nama kegiatan'),
        ),
        const SizedBox(height: 14),
        TextField(
          controller: _lokasi,
          decoration: const InputDecoration(labelText: 'Lokasi'),
        ),
        const SizedBox(height: 14),
        ListTile(
          contentPadding: EdgeInsets.zero,
          leading: const Icon(Icons.schedule),
          title: Text(
            'Mulai: ${_start.day}/${_start.month}/${_start.year} ${_start.hour.toString().padLeft(2, '0')}.${_start.minute.toString().padLeft(2, '0')} WIB',
          ),
          onTap: () async {
            final d = await showDatePicker(
              context: context,
              initialDate: _start,
              firstDate: DateTime.now(),
              lastDate: DateTime.now().add(const Duration(days: 365)),
            );
            if (d == null || !context.mounted) return;
            final t = await showTimePicker(context: context, initialTime: TimeOfDay.fromDateTime(_start));
            setState(() => _start = DateTime(d.year, d.month, d.day, t?.hour ?? 8, t?.minute ?? 0));
          },
        ),
        TextField(
          controller: _desk,
          maxLines: 4,
          decoration: const InputDecoration(labelText: 'Deskripsi'),
        ),
        const SizedBox(height: 20),
        BusyButton(
          label: 'Terbitkan agenda',
          onPressed: () async {
            // Input dipahami sebagai Asia/Jakarta (UTC+7), dikirim sebagai UTC.
            final utc = DateTime.utc(_start.year, _start.month, _start.day, _start.hour, _start.minute).subtract(const Duration(hours: 7));
            try {
              await ref
                  .read(apiProvider)
                  .post(
                    '/admin/agendas',
                    data: {'nama': _nama.text.trim(), 'lokasi': _lokasi.text.trim(), 'deskripsi': _desk.text.trim(), 'mulai_at': utc.toIso8601String()},
                  );
              ref.invalidate(agendasProvider);
              if (context.mounted) {
                showMessage(context, 'Agenda diterbitkan; warga diberi tahu.');
                Navigator.pop(context);
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

class AnnouncementFormPage extends ConsumerStatefulWidget {
  const AnnouncementFormPage({super.key});
  @override
  ConsumerState<AnnouncementFormPage> createState() => _AnnouncementFormPageState();
}

class _AnnouncementFormPageState extends ConsumerState<AnnouncementFormPage> {
  final _judul = TextEditingController();
  final _isi = TextEditingController();

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const Text('Pengumuman baru')),
    body: ListView(
      padding: const EdgeInsets.all(16),
      children: [
        TextField(
          controller: _judul,
          decoration: const InputDecoration(labelText: 'Judul'),
        ),
        const SizedBox(height: 14),
        TextField(
          controller: _isi,
          maxLines: 8,
          decoration: const InputDecoration(labelText: 'Isi pengumuman', alignLabelWithHint: true),
        ),
        const SizedBox(height: 20),
        BusyButton(
          label: 'Terbitkan',
          onPressed: () async {
            try {
              await ref.read(apiProvider).post('/admin/announcements', data: {'judul': _judul.text.trim(), 'isi': _isi.text.trim()});
              ref.invalidate(announcementsProvider);
              if (context.mounted) {
                showMessage(context, 'Pengumuman diterbitkan.');
                Navigator.pop(context);
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
