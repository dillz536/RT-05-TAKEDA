import 'package:flutter/material.dart';

import '../../app/theme.dart';
import '../../core/api.dart';
import '../../core/models.dart';

/// Satu halaman daftar dari meta API: page, per_page, total, last_page.
class PageSlice {
  const PageSlice({required this.rows, required this.page, required this.lastPage, required this.total});

  final List<Json> rows;
  final int page;
  final int lastPage;
  final int total;

  bool get hasMore => page < lastPage;
}

PageSlice parsePage(Map<dynamic, dynamic> body) {
  final rows = ((body['data'] as List?) ?? const <dynamic>[]).whereType<Map>().map(Json.from).toList();
  final meta = body['meta'];
  final map = meta is Map ? meta : const <dynamic, dynamic>{};
  final page = (map['page'] as num?)?.toInt() ?? (map['current_page'] as num?)?.toInt() ?? 1;
  final total = (map['total'] as num?)?.toInt() ?? rows.length;
  final knownLast = (map['last_page'] as num?)?.toInt();
  final last = knownLast ?? (total > rows.length && rows.isNotEmpty ? page + 1 : page);
  return PageSlice(rows: rows, page: page, lastPage: last < page ? page : last, total: total);
}

/// Menampilkan halaman pertama, lalu meminta halaman berikutnya bila meta masih punya sisa.
class AppendedPage extends StatefulWidget {
  const AppendedPage({super.key, required this.first, required this.load, required this.builder});

  final PageSlice first;
  final Future<PageSlice> Function(int page) load;
  final Widget Function(BuildContext context, List<Json> rows) builder;

  @override
  State<AppendedPage> createState() => _AppendedPageState();
}

class _AppendedPageState extends State<AppendedPage> {
  late List<Json> _rows;
  late int _page;
  late int _last;
  late int _total;
  var _loading = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _apply(widget.first, replace: true);
  }

  @override
  void didUpdateWidget(AppendedPage oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (!identical(oldWidget.first, widget.first)) _apply(widget.first, replace: true);
  }

  void _apply(PageSlice slice, {required bool replace}) {
    _rows = replace ? [...slice.rows] : [..._rows, ...slice.rows];
    _page = slice.page;
    _last = slice.lastPage;
    _total = slice.total;
    _error = null;
  }

  Future<void> _more() async {
    if (_loading || _page >= _last) return;
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final next = await widget.load(_page + 1);
      if (!mounted) return;
      setState(() {
        _apply(next, replace: false);
        _loading = false;
      });
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        _error = error is ApiException ? error.display : 'Halaman berikutnya belum dapat dimuat.';
      });
    }
  }

  @override
  Widget build(BuildContext context) => Column(
    crossAxisAlignment: CrossAxisAlignment.stretch,
    children: [
      widget.builder(context, _rows),
      const SizedBox(height: 8),
      Text('Menampilkan ${_rows.length} dari $_total.', style: const TextStyle(color: Brand.muted, height: 1.35)),
      if (_error != null) Text(_error!, style: const TextStyle(color: Brand.danger, height: 1.35)),
      if (_page < _last)
        SizedBox(
          width: double.infinity,
          child: TextButton(
            onPressed: _loading ? null : _more,
            child: Text(_loading ? 'Memuat…' : 'Muat halaman berikutnya', textAlign: TextAlign.center),
          ),
        ),
    ],
  );
}
