import 'dart:async';
import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:rt05_takeda/core/session.dart';

/// Hanya untuk tes: penyimpanan token di memori dengan jejak operasi.
class MemoryTokenStorage implements TokenStorage {
  MemoryTokenStorage([this.token]);
  String? token;
  final log = <String>[];

  @override
  Future<String?> read() async {
    log.add('read');
    return token;
  }

  @override
  Future<void> write(String value) async {
    log.add('write');
    token = value;
  }

  @override
  Future<void> delete() async {
    log.add('delete');
    token = null;
  }
}

typedef FakeHandler = FutureOr<ResponseBody> Function(RequestOptions o);

/// Hanya untuk tes: transport HTTP Dio palsu. Request tetap melewati Dio, interceptor,
/// dan parser aplikasi; hanya soket jaringan yang diganti.
class FakeAdapter implements HttpClientAdapter {
  FakeAdapter(this.handler);
  final FakeHandler handler;
  final requests = <RequestOptions>[];

  @override
  Future<ResponseBody> fetch(RequestOptions options, Stream<Uint8List>? requestStream, Future<void>? cancelFuture) async {
    requests.add(options);
    return await handler(options);
  }

  @override
  void close({bool force = false}) {}
}

ResponseBody jsonBody(int status, Object? body, {Map<String, String> headers = const {}}) => ResponseBody.fromString(
  jsonEncode(body),
  status,
  headers: {
    Headers.contentTypeHeader: [Headers.jsonContentType],
    for (final e in headers.entries) e.key: [e.value],
  },
);

ResponseBody errorBody(int status, String code, String message) => jsonBody(status, {
  'error': {'code': code, 'message': message, 'request_id': '00000000-0000-4000-8000-000000000000'},
});

Never offline(RequestOptions o) => throw DioException.connectionError(requestOptions: o, reason: 'offline (tes)');

const healthRequestId = '7f1c2a9e-4b3d-4e8a-9c21-5d6e7f8a9b0c';

ResponseBody healthOk() => jsonBody(200, {'status': 'ok'}, headers: {'x-request-id': healthRequestId});

/// Data sintetis (bukan data warga nyata).
Map<String, dynamic> akunJson({String id = 'a-1', String peran = 'warga', String status = 'aktif', String jenis = 'utama'}) => {
  'id': id,
  'nama': 'Warga Uji Coba Dengan Nama Cukup Panjang',
  'email': 'warga.uji.coba@example.test',
  'peran': peran,
  'jenis': jenis,
  'status': status,
  'dapat_ubah_keluarga': jenis == 'utama',
  'rumah': {'kode_rumah': 'A-01', 'alamat': 'Jalan Uji Coba Nomor 1 Blok A'},
};

/// Respons API sintetis untuk shell warga dan pengurus (bentuk mengikuti docs/openapi.yaml).
Map<String, Object?> shellFixtures() {
  final page = {'current_page': 1, 'per_page': 50, 'total': 1};
  final laporan = {
    'id': 'l-1',
    'judul': 'Lampu jalan di ujung gang padam sejak beberapa hari',
    'deskripsi': 'Uji',
    'isi': 'Uji',
    'status': 'diproses',
    'versi': 1,
    'sembunyikan_identitas': true,
    'milik_saya': false,
    'kategori': {'nama': 'Fasilitas umum'},
    'created_at': '2026-10-09T03:00:00Z',
  };
  final tagihan = {'id': 't-1', 'periode': '2026-10', 'nominal': 75000, 'status': 'belum_bayar', 'status_label': 'Belum dibayar'};
  return {
    '/api/v1/dues/my': {
      'data': {
        'rumah': {'kode_rumah': 'A-01'},
        'tarif_berlaku': 75000,
        'bulan_berjalan': tagihan,
        'tunggakan': {'jumlah_bulan': 2, 'total': 150000},
        'catatan': 'Pembayaran dilakukan langsung kepada pengurus RT dan dicatat oleh pengurus.',
        'tagihan': [
          tagihan,
          {...tagihan, 'id': 't-2', 'periode': '2026-09', 'status': 'lunas', 'status_label': 'Lunas'},
        ],
      },
    },
    '/api/v1/agendas': {
      'data': [
        {'id': 'g-1', 'nama': 'Kerja bakti lingkungan bersama warga', 'mulai_at': '2026-10-18T00:00:00Z', 'lokasi': 'Pos RT', 'deskripsi': 'Uji'},
      ],
      'meta': page,
    },
    '/api/v1/announcements': {
      'data': [
        {'id': 'n-1', 'judul': 'Pengumuman jadwal pengambilan sampah terbaru', 'isi': 'Uji isi pengumuman', 'published_at': '2026-10-08T02:00:00Z'},
      ],
      'meta': page,
    },
    '/api/v1/notifications': {
      'data': [
        {'id': 'm-1', 'jenis': 'pengaduan', 'judul': 'Status pengaduan berubah', 'isi': 'Uji', 'created_at': '2026-10-09T03:00:00Z', 'dibaca_at': null},
      ],
      'meta': {...page, 'belum_dibaca': 1},
    },
    '/api/v1/complaints': {
      'data': [laporan],
      'meta': page,
    },
    '/api/v1/aspirations': {
      'data': [laporan],
      'meta': page,
    },
    '/api/v1/household': {
      'data': {
        'rumah': {'alamat': 'Jalan Uji Coba Nomor 1 Blok A'},
        'jenis_hunian': 'pemilik',
        'status': 'terverifikasi',
        'anggota': [
          {'id': 'w-1', 'nama': 'Kepala Keluarga Uji', 'hubungan_keluarga': 'Kepala keluarga', 'is_kepala': true, 'punya_akun': true},
        ],
      },
    },
    '/api/v1/dues/transparency': {
      'data': {
        'periode': '2026-10',
        'lunas': 1,
        'belum_bayar': 2,
        'rumah': [
          {'kode_rumah': 'A-01', 'nominal': 75000, 'status': 'belum_bayar', 'status_label': 'Belum dibayar'},
        ],
      },
    },
    '/api/v1/cash/summary': {
      'data': {
        'saldo': 1250000,
        'bulan_ini': {'pemasukan': 225000, 'pengeluaran': 50000},
        'mutasi_terakhir': [
          {'keterangan': 'Iuran Oktober rumah A-01', 'tanggal': '2026-10-05', 'kategori': 'iuran', 'nominal': 75000},
        ],
      },
    },
    '/api/v1/admin/dashboard': {
      'data': {
        'saldo_kas': 1250000,
        'iuran_bulan_ini': {'periode': '2026-10', 'lunas': 1, 'belum_bayar': 2},
        'rumah_aktif': 3,
        'permohonan_menunggu': 1,
        'pengaduan': {'diajukan': 1},
        'aspirasi': {'diajukan': 0},
      },
    },
    '/api/v1/admin/houses': {
      'data': [
        {
          'id': 'h-1',
          'kode_rumah': 'A-01',
          'alamat': 'Jalan Uji Coba Nomor 1 Blok A',
          'keluarga': {'kepala': 'Kepala Keluarga Uji', 'jumlah_anggota': 3, 'status': 'terverifikasi'},
        },
      ],
      'meta': page,
    },
    '/api/v1/admin/additional-account-requests': {'data': [], 'meta': page},
    '/api/v1/admin/dues-payments': {
      'data': [
        {
          'id': 'p-1',
          'nomor_bukti': 'BKT-0001',
          'rumah': {'kode_rumah': 'A-01'},
          'periode': ['2026-09'],
          'total_bayar': 75000,
          'tanggal_bayar': '2026-09-05',
          'status': 'tercatat',
        },
      ],
      'meta': page,
    },
  };
}

/// Handler API: health + /auth/me + fixture shell; path lain → 404 envelope.
FakeHandler apiHandler({required Map<String, dynamic> Function() me, Map<String, Object?>? fixtures}) {
  final data = fixtures ?? shellFixtures();
  return (o) {
    final path = o.uri.path;
    if (path == '/health/live') return healthOk();
    if (path == '/api/v1/auth/me') return jsonBody(200, {'data': me()});
    if (data.containsKey(path)) return jsonBody(200, data[path]);
    return errorBody(404, 'NOT_FOUND', 'Tidak ditemukan.');
  };
}
