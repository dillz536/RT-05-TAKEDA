import 'package:intl/intl.dart';

/// Tampilan uang dan waktu. Server menyimpan UTC; tampilan Asia/Jakarta (UTC+7, tanpa DST).
final _rupiah = NumberFormat.currency(locale: 'id_ID', symbol: 'Rp', decimalDigits: 0);

String rupiah(num value) => _rupiah.format(value);

DateTime toJakarta(DateTime utc) => utc.toUtc().add(const Duration(hours: 7));

const _bulan = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
const _hari = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'];

String tanggal(DateTime? utc, {bool jam = false, bool hari = false}) {
  if (utc == null) return '-';
  final t = toJakarta(utc);
  final d = '${hari ? '${_hari[t.weekday - 1]}, ' : ''}${t.day} ${_bulan[t.month - 1]} ${t.year}';
  return jam ? '$d • ${t.hour.toString().padLeft(2, '0')}.${t.minute.toString().padLeft(2, '0')} WIB' : d;
}

/// "2026-10" → "Oktober 2026".
String periode(String yyyyMm) {
  final parts = yyyyMm.split('-');
  if (parts.length < 2) return yyyyMm;
  return '${_bulan[int.parse(parts[1]) - 1]} ${parts[0]}';
}

String statusLabel(String s) =>
    const {
      'diajukan': 'Diajukan',
      'diproses': 'Diproses',
      'ditinjau': 'Ditinjau',
      'selesai': 'Selesai',
      'lunas': 'Lunas',
      'belum_bayar': 'Belum dibayar',
      'tercatat': 'Tercatat',
      'dibatalkan': 'Dibatalkan',
      'aktif': 'Aktif',
      'menunggu': 'Menunggu',
      'nonaktif': 'Nonaktif',
    }[s] ??
    s;
