import '../../core/api.dart';

/// Payload dan aturan tampilan keluarga sesuai kontrak yang sudah terpasang.
/// Klien tidak mengirim peran, status akun, atau hak admin.
const whatsappPattern = r'^[0-9+ -]{8,25}$';

String? personNameError(String value, {int max = 120, String label = 'Nama'}) {
  final v = value.trim();
  if (v.isEmpty) return '$label wajib diisi.';
  if (v.length > max) return '$label paling panjang $max karakter.';
  return null;
}

String? relationshipError(String value) {
  final v = value.trim();
  if (v.isEmpty) return 'Hubungan wajib diisi.';
  if (v.length > 40) return 'Hubungan paling panjang 40 karakter.';
  return null;
}

String? whatsappError(String value) {
  final v = value.trim();
  if (v.isEmpty) return null;
  if (!RegExp(whatsappPattern).hasMatch(v)) {
    return 'Nomor 8–25 karakter: angka, spasi, plus, atau tanda hubung.';
  }
  return null;
}

String? houseCodeError(String value) {
  final v = value.trim();
  if (v.isEmpty) return null;
  if (v.length > 30 || !RegExp(r'^[A-Z0-9-]+$').hasMatch(v)) {
    return 'Kode paling panjang 30 karakter: huruf besar, angka, dan tanda hubung.';
  }
  return null;
}

String? billingMonthError(String value) {
  final v = value.trim();
  if (v.isEmpty) return null;
  final match = RegExp(r'^(\d{4})-(\d{2})$').firstMatch(v);
  if (match == null) return 'Isi bulan seperti 2026-10.';
  final month = int.parse(match.group(2)!);
  if (month < 1 || month > 12) return 'Bulan tidak dikenal.';
  return null;
}

String? dayError(String value) {
  final v = value.trim();
  if (v.isEmpty) return null;
  final match = RegExp(r'^(\d{4})-(\d{2})-(\d{2})$').firstMatch(v);
  if (match == null) return 'Isi tanggal seperti 2026-10-13.';
  final month = int.parse(match.group(2)!);
  final day = int.parse(match.group(3)!);
  if (month < 1 || month > 12 || day < 1 || day > 31) return 'Tanggal tidak dikenal.';
  return null;
}

String? reasonError(String value) {
  final v = value.trim();
  if (v.isEmpty) return 'Alasan wajib diisi.';
  if (v.length > 300) return 'Alasan paling panjang 300 karakter.';
  return null;
}

/// Draf suntingan keluarga. Konflik versi menyimpan versi lama sampai muat ulang menggantinya.
class HouseholdDraft {
  const HouseholdDraft({this.versi, this.conflict});

  final int? versi;
  final String? conflict;

  bool get ready => versi != null;

  factory HouseholdDraft.fromHousehold(Map<dynamic, dynamic> data) => HouseholdDraft(versi: (data['versi'] as num?)?.toInt());

  Map<String, dynamic> householdPatch({required String namaKepala, required String? whatsapp}) {
    final version = versi;
    if (version == null) throw StateError('Versi keluarga belum dimuat.');
    return {'versi': version, 'nama_kepala': namaKepala.trim(), 'whatsapp': whatsapp};
  }

  HouseholdDraft onFailure(ApiException error) {
    if (staleConflict(error)) return HouseholdDraft(versi: versi, conflict: conflictKeptMessage(error.message));
    return this;
  }

  HouseholdDraft accept(Map<dynamic, dynamic> data) => HouseholdDraft.fromHousehold(data);
}

const conflictKeptTail = 'Teks yang Anda ketik tetap ada. Periksa data terbaru, lalu kirim lagi.';

/// Pesan konflik yang tidak menutup formulir. Teks yang sudah diketik tetap dipakai.
String conflictKeptMessage(String server) {
  final text = server.trim();
  if (text.contains(conflictKeptTail)) return text;
  return text.isEmpty ? conflictKeptTail : '$text $conflictKeptTail';
}

String? reviewLine(Object? value) {
  final text = value == null ? '' : '$value'.trim();
  if (text.isEmpty || text == 'null') return null;
  return 'Data terbaru: $text.';
}

Map<String, dynamic> memberBody({required String nama, required String hubungan, String? whatsapp, int? versi}) {
  final phone = whatsapp?.trim();
  return {'nama': nama.trim(), 'hubungan_keluarga': hubungan.trim(), if (phone != null && phone.isNotEmpty) 'whatsapp': phone, 'versi': ?versi};
}

Map<String, dynamic> archiveMemberBody(String status, {int? versi}) {
  if (status != 'pindah' && status != 'meninggal') throw ArgumentError('Status anggota tidak dikenal.');
  return {'status': status, 'versi': ?versi};
}

int? readVersi(Map<dynamic, dynamic>? data) => (data?['versi'] as num?)?.toInt();

/// Konflik versi keluarga/rumah. 409 lain (rumah sudah ada, hunian masih berjalan) tetap di formulir.
bool staleConflict(ApiException error) => error.code == 'VERSION_CONFLICT';

const versiMissingMessage = 'Muat ulang data lalu coba lagi.';

Map<String, dynamic> accountStatusBody({required String status, required String alasan}) {
  if (status != 'aktif' && status != 'nonaktif') throw ArgumentError('Status akun tidak dikenal.');
  return {'status': status, 'alasan': alasan.trim()};
}

Map<String, dynamic> houseCreateBody({String? blok, required String jalan, required String nomor, String? kode, bool tagihanAktif = true, String? mulaiTagih}) {
  final block = blok?.trim();
  final code = kode?.trim();
  final start = mulaiTagih?.trim();
  return {
    if (block != null && block.isNotEmpty) 'blok': block,
    'jalan': jalan.trim(),
    'nomor': nomor.trim(),
    if (code != null && code.isNotEmpty) 'kode_rumah': code,
    'tagihan_aktif': tagihanAktif,
    if (start != null && start.isNotEmpty) 'mulai_tagih': start,
  };
}

Map<String, dynamic> houseBillingBody({bool? tagihanAktif, String? mulaiTagih, required String alasan, int? versi}) {
  final start = mulaiTagih?.trim();
  return {'tagihan_aktif': ?tagihanAktif, if (start != null) 'mulai_tagih': start.isEmpty ? null : start, 'alasan': alasan.trim(), 'versi': ?versi};
}

Map<String, dynamic> occupancyBeginBody({required String rumahId, required String keluargaId, required String jenis, String? mulai}) {
  if (jenis != 'pemilik' && jenis != 'kontrak') throw ArgumentError('Jenis hunian tidak dikenal.');
  final start = mulai?.trim();
  return {'rumah_id': rumahId, 'keluarga_id': keluargaId, 'jenis_hunian': jenis, if (start != null && start.isNotEmpty) 'mulai_tanggal': start};
}

Map<String, dynamic> occupancyEndBody({required String alasan, String? selesai}) {
  final end = selesai?.trim();
  return {'alasan': alasan.trim(), if (end != null && end.isNotEmpty) 'selesai_tanggal': end};
}

Map<String, dynamic> occupancyMoveBody({required String rumahId, required String alasan, String? jenis, String? tanggal}) {
  if (jenis != null && jenis != 'pemilik' && jenis != 'kontrak') throw ArgumentError('Jenis hunian tidak dikenal.');
  final day = tanggal?.trim();
  return {'rumah_id': rumahId, 'alasan': alasan.trim(), 'jenis_hunian': ?jenis, if (day != null && day.isNotEmpty) 'tanggal': day};
}

Map<String, dynamic> residentEndBody({required String status, required String alasan, int? versi}) {
  if (status != 'pindah' && status != 'meninggal') throw ArgumentError('Status anggota tidak dikenal.');
  return {'status': status, 'alasan': alasan.trim(), 'versi': ?versi};
}

Map<String, dynamic> changeHeadBody({required String wargaId, required int versi}) => {'kepala_warga_id': wargaId, 'versi': versi};

Map<String, dynamic> familyCreateBody({
  required String namaKepala,
  String? hubunganKepala,
  String? whatsapp,
  String? rumahId,
  String? jenis,
  String? mulai,
  List<Map<String, dynamic>> anggota = const [],
}) {
  if (jenis != null && jenis != 'pemilik' && jenis != 'kontrak') throw ArgumentError('Jenis hunian tidak dikenal.');
  final phone = whatsapp?.trim();
  final relation = hubunganKepala?.trim();
  final start = mulai?.trim();
  final house = rumahId?.trim();
  return {
    'nama_kepala': namaKepala.trim(),
    if (relation != null && relation.isNotEmpty) 'hubungan_kepala': relation,
    if (phone != null && phone.isNotEmpty) 'whatsapp': phone,
    if (anggota.isNotEmpty) 'anggota': anggota,
    if (house != null && house.isNotEmpty) ...{'rumah_id': house, 'jenis_hunian': jenis, if (start != null && start.isNotEmpty) 'mulai_tanggal': start},
  };
}

Map<String, dynamic> transferPrimaryBody({required String akunId, required String alasan, int? versi}) => {
  'akun_id': akunId,
  'alasan': alasan.trim(),
  'versi': ?versi,
};

Map<String, dynamic> residentMoveBody({required String keluargaId, required String hubungan, required String alasan, int? versi}) => {
  'keluarga_id': keluargaId,
  'hubungan_keluarga': hubungan.trim(),
  'alasan': alasan.trim(),
  'versi': ?versi,
};

/// Alamat saja. Pemilik dan tagihan tidak ikut agar tidak saling menimpa.
Map<String, dynamic> houseAddressBody({required String blok, required String jalan, required String nomor, required String alasan, int? versi}) {
  final block = blok.trim();
  return {'blok': block.isEmpty ? null : block, 'jalan': jalan.trim(), 'nomor': nomor.trim(), 'alasan': alasan.trim(), 'versi': ?versi};
}

/// Pemilik saja. `clear` mengosongkan pemilik tanpa menghapus catatan penghuni.
Map<String, dynamic> houseOwnerBody({String? nama, String? whatsapp, String? catatan, bool clear = false, required String alasan, int? versi}) {
  final phone = whatsapp?.trim() ?? '';
  final note = catatan?.trim() ?? '';
  return {
    'pemilik': clear ? null : {'nama': (nama ?? '').trim(), 'whatsapp': phone.isEmpty ? null : phone, 'catatan': note.isEmpty ? null : note},
    'alasan': alasan.trim(),
    'versi': ?versi,
  };
}

int? familyVersi(Map<dynamic, dynamic>? data) => readVersi(data) ?? (data?['versi_keluarga'] as num?)?.toInt();

/// Tombol ubah mengikuti `dapat_ubah` pada GET /household yang baru dan status akun aktif.
/// Nilai yang hilang atau false ditutup menjadi baca saja. Kemampuan sesi tidak dipakai:
/// /auth/me bisa masih true setelah pindah akun utama atau pindah anggota.
bool canEditHousehold({required String status, required Object? dapatUbah}) => status == 'aktif' && dapatUbah == true;

bool canArchiveMember(Map<dynamic, dynamic> member) => member['is_kepala'] != true && member['punya_akun'] != true;

String hunianLabel(Object? raw) => switch (raw) {
  'pemilik' => 'Pemilik',
  'kontrak' => 'Kontrak',
  _ => '',
};

const _bulan = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

/// Tanggal kalender `YYYY-MM-DD` tanpa digeser zona waktu.
String tanggalHari(Object? raw) {
  final text = '$raw';
  final match = RegExp(r'^(\d{4})-(\d{2})-(\d{2})').firstMatch(text);
  if (match == null) return '';
  final month = int.parse(match.group(2)!);
  final day = int.parse(match.group(3)!);
  if (month < 1 || month > 12) return '';
  return '$day ${_bulan[month - 1]} ${match.group(1)}';
}
