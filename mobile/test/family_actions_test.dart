import 'package:flutter_test/flutter_test.dart';
import 'package:rt05_takeda/core/api.dart';
import 'package:rt05_takeda/features/household/family_actions.dart';

void main() {
  test('suntingan keluarga membawa versi dan tidak membawa peran', () {
    final body = HouseholdDraft(versi: 4).householdPatch(namaKepala: ' Siti ', whatsapp: null);
    expect(body, {'versi': 4, 'nama_kepala': 'Siti', 'whatsapp': null});
    expect(body.containsKey('peran'), isFalse);
    expect(body.containsKey('role'), isFalse);
    expect(body.containsKey('status'), isFalse);
  });

  test('konflik versi menyimpan versi dan menjelaskan bahwa teks tetap ada', () {
    const staleMessage = 'Data keluarga sudah berubah. Muat ulang lalu coba lagi.';
    final stale = const HouseholdDraft(versi: 1).onFailure(ApiException(409, 'VERSION_CONFLICT', staleMessage));
    expect(stale.versi, 1);
    expect(stale.ready, isTrue);
    expect(stale.conflict, conflictKeptMessage(staleMessage));
    expect(stale.conflict, contains('Teks yang Anda ketik tetap ada. Periksa data terbaru, lalu kirim lagi.'));
    final fresh = stale.accept({'versi': 2, 'anggota': []});
    expect(fresh.versi, 2);
    expect(fresh.conflict, isNull);
    expect(fresh.ready, isTrue);
    final occupied = const HouseholdDraft(versi: 3).onFailure(ApiException(409, 'HOUSE_OCCUPIED', 'Rumah masih dihuni.'));
    expect(occupied.versi, 3);
    expect(occupied.conflict, isNull);
  });

  test('kegagalan selain konflik tidak menghapus versi', () {
    final kept = HouseholdDraft(versi: 3).onFailure(ApiException(0, 'NETWORK_ERROR', 'Tidak dapat terhubung ke server.'));
    expect(kept.versi, 3);
    expect(kept.conflict, isNull);
  });

  test('tombol ubah mengikuti data keluarga baru dan status aktif', () {
    expect(canEditHousehold(status: 'aktif', dapatUbah: true), isTrue);
    expect(canEditHousehold(status: 'aktif', dapatUbah: false), isFalse);
    expect(canEditHousehold(status: 'aktif', dapatUbah: null), isFalse);
    expect(canEditHousehold(status: 'menunggu', dapatUbah: true), isFalse);
    expect(canEditHousehold(status: 'nonaktif', dapatUbah: true), isFalse);
  });

  test('kepala dan anggota berakun tidak diarsipkan dari layar warga', () {
    expect(canArchiveMember({'is_kepala': true, 'punya_akun': true}), isFalse);
    expect(canArchiveMember({'is_kepala': false, 'punya_akun': true}), isFalse);
    expect(canArchiveMember({'is_kepala': false, 'punya_akun': false}), isTrue);
  });

  test('status anggota dan akun hanya memakai nilai yang diizinkan server', () {
    expect(archiveMemberBody('pindah'), {'status': 'pindah'});
    expect(archiveMemberBody('pindah', versi: 2), {'status': 'pindah', 'versi': 2});
    expect(archiveMemberBody('meninggal'), {'status': 'meninggal'});
    expect(() => archiveMemberBody('dihapus'), throwsArgumentError);
    final member = memberBody(nama: ' Ani ', hubungan: ' Anak ', versi: 3);
    expect(member, {'nama': 'Ani', 'hubungan_keluarga': 'Anak', 'versi': 3});
    expect(member.containsKey('peran'), isFalse);
    final status = accountStatusBody(status: 'nonaktif', alasan: ' Pindah ');
    expect(status, {'status': 'nonaktif', 'alasan': 'Pindah'});
    expect(status.containsKey('peran'), isFalse);
    expect(status.containsKey('role'), isFalse);
    expect(() => accountStatusBody(status: 'pengurus', alasan: 'naik'), throwsArgumentError);
  });

  test('rumah baru tidak memuat peran dan mengosongkan kode bila tidak diisi', () {
    final body = houseCreateBody(jalan: ' Jalan Uji ', nomor: '1', kode: ' ', tagihanAktif: true, mulaiTagih: '');
    expect(body, {'jalan': 'Jalan Uji', 'nomor': '1', 'tagihan_aktif': true});
    expect(body.containsKey('peran'), isFalse);
    expect(houseCodeError('a-01'), isNotNull);
    expect(houseCodeError('A-01'), isNull);
    expect(billingMonthError('2026-13'), isNotNull);
    expect(billingMonthError('2026-10'), isNull);
  });

  test('hunian dan akhir anggota tidak membawa peran', () {
    final begin = occupancyBeginBody(rumahId: 'r-1', keluargaId: 'k-1', jenis: 'pemilik', mulai: ' ');
    expect(begin, {'rumah_id': 'r-1', 'keluarga_id': 'k-1', 'jenis_hunian': 'pemilik'});
    expect(begin.containsKey('peran'), isFalse);
    expect(begin.containsKey('role'), isFalse);
    expect(() => occupancyBeginBody(rumahId: 'r-1', keluargaId: 'k-1', jenis: 'sewa'), throwsArgumentError);
    final end = occupancyEndBody(alasan: ' Pindah kota ', selesai: '');
    expect(end, {'alasan': 'Pindah kota'});
    expect(end.containsKey('selesai_tanggal'), isFalse);
    final move = occupancyMoveBody(rumahId: 'r-2', alasan: 'Pindah', jenis: 'kontrak', tanggal: '2026-10-13');
    expect(move, {'rumah_id': 'r-2', 'alasan': 'Pindah', 'jenis_hunian': 'kontrak', 'tanggal': '2026-10-13'});
    expect(move.containsKey('peran'), isFalse);
    final resident = residentEndBody(status: 'meninggal', alasan: ' Meninggal ', versi: 4);
    expect(resident, {'status': 'meninggal', 'alasan': 'Meninggal', 'versi': 4});
    expect(resident.containsKey('role'), isFalse);
    expect(changeHeadBody(wargaId: 'w-2', versi: 5).containsKey('peran'), isFalse);
    expect(staleConflict(ApiException(409, 'VERSION_CONFLICT', 'Data keluarga sudah berubah. Muat ulang lalu coba lagi.')), isTrue);
    expect(staleConflict(ApiException(409, 'HOUSE_OCCUPIED', 'Rumah masih dihuni keluarga lain.')), isFalse);
    expect(dayError('2026-10-13'), isNull);
    expect(dayError('2026-13-01'), isNotNull);
    final bill = houseBillingBody(tagihanAktif: false, mulaiTagih: '', alasan: 'Kosong', versi: 2);
    expect(bill['versi'], 2);
    expect(bill.containsKey('peran'), isFalse);
  });

  test('keluarga, pindah akun, pindah anggota, alamat, dan pemilik memakai payload yang terpisah', () {
    final created = familyCreateBody(
      namaKepala: ' Siti ',
      hubunganKepala: ' ',
      whatsapp: '',
      anggota: [memberBody(nama: ' Ani ', hubungan: ' Anak ')],
    );
    expect(created, {
      'nama_kepala': 'Siti',
      'anggota': [
        {'nama': 'Ani', 'hubungan_keluarga': 'Anak'},
      ],
    });
    expect(created.containsKey('peran'), isFalse);
    expect(created.containsKey('role'), isFalse);
    expect(created.containsKey('rumah_id'), isFalse);
    final linked = familyCreateBody(namaKepala: 'Siti', rumahId: 'r-1', jenis: 'kontrak', mulai: ' ');
    expect(linked, {'nama_kepala': 'Siti', 'rumah_id': 'r-1', 'jenis_hunian': 'kontrak'});
    expect(() => familyCreateBody(namaKepala: 'Siti', rumahId: 'r-1', jenis: 'sewa'), throwsArgumentError);

    final transfer = transferPrimaryBody(akunId: 'a-2', alasan: ' Pindah utama ', versi: 6);
    expect(transfer, {'akun_id': 'a-2', 'alasan': 'Pindah utama', 'versi': 6});
    expect(transfer.containsKey('peran'), isFalse);
    expect(transfer.containsKey('role'), isFalse);

    final moved = residentMoveBody(keluargaId: 'k-9', hubungan: ' Anak ', alasan: ' Ikut keluarga ', versi: 8);
    expect(moved, {'keluarga_id': 'k-9', 'hubungan_keluarga': 'Anak', 'alasan': 'Ikut keluarga', 'versi': 8});
    expect(moved.containsKey('peran'), isFalse);

    final address = houseAddressBody(blok: '', jalan: ' Jalan Mawar ', nomor: ' 2 ', alasan: ' Koreksi ', versi: 3);
    expect(address, {'blok': null, 'jalan': 'Jalan Mawar', 'nomor': '2', 'alasan': 'Koreksi', 'versi': 3});
    expect(address.containsKey('pemilik'), isFalse);
    expect(address.containsKey('tagihan_aktif'), isFalse);
    expect(address.containsKey('kode_rumah'), isFalse);

    final owner = houseOwnerBody(nama: ' Budi ', whatsapp: '', catatan: 'Catatan', alasan: 'Ubah', versi: 4);
    expect(owner['pemilik'], {'nama': 'Budi', 'whatsapp': null, 'catatan': 'Catatan'});
    expect(owner.containsKey('jalan'), isFalse);
    expect(owner.containsKey('blok'), isFalse);
    expect(owner.containsKey('nomor'), isFalse);
    expect(owner.containsKey('peran'), isFalse);
    final cleared = houseOwnerBody(clear: true, alasan: 'Lepas', versi: 4);
    expect(cleared['pemilik'], isNull);
    expect(cleared.containsKey('jalan'), isFalse);
    expect(familyVersi({'versi_keluarga': 9}), 9);
  });
}
