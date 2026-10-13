import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api.dart';
import '../../core/models.dart';
import 'paged_list.dart';

typedef HouseQuery = ({String q, bool arsip, int page});

final housesProvider = FutureProvider.autoDispose.family<PageSlice, HouseQuery>((ref, query) async {
  final body = await ref
      .read(apiProvider)
      .page('/admin/houses', query: {'per_page': 100, 'page': query.page, if (query.q.isNotEmpty) 'q': query.q, if (query.arsip) 'arsip': 1});
  return parsePage(body);
});

final houseDuesProvider = FutureProvider.autoDispose.family<Json, String>(
  (ref, id) async => Json.from(await ref.read(apiProvider).get('/admin/houses/$id/dues') as Map),
);

final houseDetailProvider = FutureProvider.autoDispose.family<Json, String>(
  (ref, id) async => Json.from(await ref.read(apiProvider).get('/admin/houses/$id') as Map),
);

typedef FamilyQuery = ({String q, bool arsip, String status, int page});

final familiesProvider = FutureProvider.autoDispose.family<PageSlice, FamilyQuery>((ref, query) async {
  final body = await ref
      .read(apiProvider)
      .page(
        '/admin/families',
        query: {
          'per_page': 100,
          'page': query.page,
          if (query.q.isNotEmpty) 'q': query.q,
          if (query.arsip) 'arsip': 1,
          if (query.status.isNotEmpty) 'status': query.status,
        },
      );
  return parsePage(body);
});

final familyDetailProvider = FutureProvider.autoDispose.family<Json, String>(
  (ref, id) async => Json.from(await ref.read(apiProvider).get('/admin/families/$id') as Map),
);

final residentProvider = FutureProvider.autoDispose.family<Json, String>(
  (ref, id) async => Json.from(await ref.read(apiProvider).get('/admin/residents/$id') as Map),
);

typedef AccountQuery = ({String status, String q, int page});

final accountsProvider = FutureProvider.autoDispose.family<PageSlice, AccountQuery>((ref, query) async {
  final body = await ref
      .read(apiProvider)
      .page(
        '/admin/accounts',
        query: {'per_page': 100, 'page': query.page, if (query.status.isNotEmpty) 'status': query.status, if (query.q.isNotEmpty) 'q': query.q},
      );
  return parsePage(body);
});

final accountDetailProvider = FutureProvider.autoDispose.family<Json, String>(
  (ref, id) async => Json.from(await ref.read(apiProvider).get('/admin/accounts/$id') as Map),
);
