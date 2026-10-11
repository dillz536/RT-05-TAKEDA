import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api.dart';
import '../../core/models.dart';

/// Provider data warga. Semua angka (tunggakan, saldo, status) berasal dari server.
final duesMyProvider = FutureProvider.autoDispose<Json>((ref) async => Json.from(await ref.read(apiProvider).get('/dues/my') as Map));

final transparencyProvider = FutureProvider.autoDispose.family<Json, String?>(
  (ref, p) async => Json.from(await ref.read(apiProvider).get('/dues/transparency', query: {'periode': ?p}) as Map),
);

final cashProvider = FutureProvider.autoDispose<Json>((ref) async => Json.from(await ref.read(apiProvider).get('/cash/summary') as Map));

final householdProvider = FutureProvider.autoDispose<Json>((ref) async => Json.from(await ref.read(apiProvider).get('/household') as Map));

final agendasProvider = FutureProvider.autoDispose<List<Json>>(
  (ref) async => ((await ref.read(apiProvider).page('/agendas'))['data'] as List).cast<Map>().map(Json.from).toList(),
);

final announcementsProvider = FutureProvider.autoDispose<List<Json>>(
  (ref) async => ((await ref.read(apiProvider).page('/announcements'))['data'] as List).cast<Map>().map(Json.from).toList(),
);

final notificationsProvider = FutureProvider.autoDispose<Json>((ref) async => await ref.read(apiProvider).page('/notifications'));

final categoriesProvider = FutureProvider.autoDispose<List<Json>>(
  (ref) async => (await ref.read(apiProvider).get('/complaint-categories') as List).cast<Map>().map(Json.from).toList(),
);

/// Feed laporan: (aspirasi?, hanyaMilikSaya?, status?).
final reportsProvider = FutureProvider.autoDispose.family<List<Laporan>, (bool, bool, String?)>((ref, args) async {
  final (aspirasi, mine, status) = args;
  final res = await ref
      .read(apiProvider)
      .page(aspirasi ? '/aspirations' : '/complaints', query: {if (mine) 'milik_saya': 1, 'status': ?status, 'per_page': 50});
  return (res['data'] as List).cast<Map>().map((m) => Laporan(Json.from(m), aspirasi: aspirasi)).toList();
});

final reportDetailProvider = FutureProvider.autoDispose.family<(Laporan, List<Json>), (bool, String)>((ref, args) async {
  final (aspirasi, id) = args;
  final base = aspirasi ? '/aspirations/$id' : '/complaints/$id';
  final api = ref.read(apiProvider);
  final results = await Future.wait([api.get(base), api.get('$base/history')]);
  return (Laporan(Json.from(results[0] as Map), aspirasi: aspirasi), (results[1] as List).cast<Map>().map(Json.from).toList());
});
