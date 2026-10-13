<?php

namespace App\Domain\Residents;

use App\Exceptions\DomainConflict;
use App\Models\Akun;
use App\Models\KeanggotaanKeluarga;
use App\Models\Keluarga;
use App\Models\Penghunian;
use App\Models\TautanAkun;
use App\Models\Warga;
use App\Support\Audit;

/**
 * Transaksi keluarga dan anggota (BR05, ADR10, M07). Dipakai akun utama (keluarga sendiri) dan
 * pengurus (semua keluarga, hak setara). Setiap mutasi: kunci keluarga → periksa ulang hak aktor
 * (Links::assertEditor, bukan cache controller) → periksa versi → tulis histori keanggotaan/tautan
 * + pointer lama → naikkan versi → audit, dalam satu transaksi. Pengecualian versi yang disengaja:
 * archive (transisi status final dengan alasan wajib; keadaan diperiksa ulang di bawah lock) dan
 * create (keluarga baru).
 * Tidak ada hapus permanen; anggota yang keluar diarsipkan dengan rentang keanggotaan ditutup.
 */
final class Households
{
    /** PATCH /household: WhatsApp/nama kepala keluarga, versi wajib. */
    public static function updateHead(Akun $actor, string $keluargaId, array $data): void
    {
        Links::transaction(function () use ($actor, $keluargaId, $data) {
            $k = self::lockActive($keluargaId);
            Links::assertEditor($actor, $k);
            Links::assertVersion($k, $data['versi']);
            $kepala = Warga::where('keluarga_id', $k->id)->where('is_kepala', true)->whereNull('archived_at')->first();
            if ($kepala) {
                if (array_key_exists('whatsapp', $data)) {
                    $kepala->whatsapp = $data['whatsapp'];
                }
                if (array_key_exists('nama_kepala', $data)) {
                    $kepala->nama = $data['nama_kepala'];
                }
                $kepala->save();
            }
            Links::bump($k);
            Audit::record($actor, 'keluarga.diubah', 'keluarga', $k->id, ['field' => array_keys(array_diff_key($data, ['versi' => 1]))]);
        });
    }

    /** @param array{nama:string,hubungan_keluarga:string,whatsapp?:?string} $data */
    public static function addMember(Akun $actor, string $keluargaId, array $data, mixed $versi, string $sumber): Warga
    {
        return Links::transaction(function () use ($actor, $keluargaId, $data, $versi, $sumber) {
            $k = self::lockActive($keluargaId);
            Links::assertEditor($actor, $k);
            Links::assertVersion($k, $versi);
            $warga = Warga::create([
                'keluarga_id' => $k->id,
                'nama' => trim($data['nama']),
                'hubungan_keluarga' => trim($data['hubungan_keluarga']),
                'whatsapp' => $data['whatsapp'] ?? null,
                'is_kepala' => false,
            ]);
            Links::startMembership($warga, $k, $warga->hubungan_keluarga, $sumber, $actor);
            Links::bump($k);
            Audit::record($actor, 'warga.ditambah', 'warga', $warga->id, ['keluarga_id' => $k->id]);

            return $warga;
        });
    }

    /** Ubah nama/hubungan/WhatsApp anggota aktif; anggota keluarga lain → 404. */
    public static function updateMember(Akun $actor, string $keluargaId, string $wargaId, array $data, mixed $versi, bool $viaPengurus = false): Warga
    {
        return Links::transaction(function () use ($actor, $keluargaId, $wargaId, $data, $versi, $viaPengurus) {
            $k = self::lockActive($keluargaId);
            Links::assertEditor($actor, $k);
            Links::assertVersion($k, $versi);
            $warga = self::member($k, $wargaId, $viaPengurus);
            $warga->fill(array_intersect_key($data, array_flip(['nama', 'hubungan_keluarga', 'whatsapp'])))->save();
            if (array_key_exists('hubungan_keluarga', $data)) {
                KeanggotaanKeluarga::where('warga_id', $warga->id)->whereNull('selesai_at')->update(['hubungan_keluarga' => $data['hubungan_keluarga']]);
            }
            Links::bump($k);
            Audit::record($actor, 'warga.diubah', 'warga', $warga->id, ['field' => array_keys($data)]);

            return $warga;
        });
    }

    /**
     * Akhiri keanggotaan (pindah keluar RT / meninggal): arsip warga, rentang ditutup. Kepala keluarga
     * dan anggota yang memiliki tautan akun aktif ditolak (ganti kepala/arsip akun lebih dulu).
     * Jalur akun utama memakai MEMBER_PROTECTED 422 (kompatibel M06); jalur pengurus kode spesifik 409.
     * Alasan pengurus (wajib di endpoint admin) disimpan di audit; status pindah/meninggal di histori.
     */
    public static function endMember(Akun $actor, string $keluargaId, string $wargaId, string $status, mixed $versi, bool $viaPengurus, ?string $alasan = null): void
    {
        Links::transaction(function () use ($actor, $keluargaId, $wargaId, $status, $versi, $viaPengurus, $alasan) {
            $k = self::lockActive($keluargaId);
            Links::assertEditor($actor, $k);
            Links::assertVersion($k, $versi);
            $warga = self::member($k, $wargaId, $viaPengurus);
            $hasLink = TautanAkun::where('warga_id', $warga->id)->whereNull('selesai_at')->exists();
            if (! $viaPengurus && ($warga->is_kepala || $hasLink)) {
                throw new DomainConflict('MEMBER_PROTECTED', 'Kepala keluarga atau anggota yang memiliki akun diubah melalui pengurus.', 422);
            }
            if ($warga->is_kepala) {
                throw new DomainConflict('HEAD_OF_FAMILY', 'Tetapkan kepala keluarga lain sebelum mengakhiri keanggotaan ini.');
            }
            if ($hasLink) {
                throw new DomainConflict('RESIDENT_HAS_ACCOUNT', 'Arsipkan atau pindahkan akun anggota ini lebih dulu.');
            }
            Links::endMembership($warga, $status, $actor);
            $warga->forceFill(['archived_at' => now(), 'status' => $status])->save();
            Links::bump($k);
            Audit::record($actor, 'warga.diarsipkan', 'warga', $warga->id, array_filter(
                ['keluarga_id' => $k->id, 'status' => $status, 'alasan' => $alasan], fn ($v) => $v !== null));
        });
    }

    /**
     * Pindah anggota ke keluarga lain (pengurus): tutup keanggotaan lama, buka yang baru, dan bila
     * anggota memiliki akun, tautannya ikut pindah sebagai akun tambahan. Kedua keluarga dikunci
     * dengan urutan id dan versinya naik. Rumah/tagihan tidak berubah.
     */
    public static function moveMember(Akun $actor, string $wargaId, string $tujuanId, string $hubungan, string $alasan, mixed $versiAsal): Warga
    {
        return Links::transaction(function () use ($actor, $wargaId, $tujuanId, $hubungan, $alasan, $versiAsal) {
            $asalId = self::familyOf($wargaId);
            if ($asalId === $tujuanId) {
                throw new DomainConflict('SAME_FAMILY', 'Anggota sudah berada di keluarga tersebut.', 422);
            }
            $locked = Links::lockFamilies([$asalId, $tujuanId]);
            $asal = self::active($locked[$asalId] ?? null);
            $tujuan = self::active($locked[$tujuanId] ?? null);
            Links::assertEditor($actor, $asal, true);
            // Dibaca ulang di bawah lock: pemindahan bersamaan yang menang lebih dulu → 409, bukan 404.
            if (KeanggotaanKeluarga::where('warga_id', $wargaId)->whereNull('selesai_at')->value('keluarga_id') !== $asalId) {
                throw new DomainConflict('CONFLICT', 'Data anggota berubah bersamaan. Muat ulang lalu coba lagi.');
            }
            Links::assertVersion($asal, $versiAsal);
            $warga = Links::memberOf($asal, $wargaId);
            if ($warga->is_kepala) {
                throw new DomainConflict('HEAD_OF_FAMILY', 'Tetapkan kepala keluarga lain sebelum memindahkan kepala keluarga.');
            }

            Links::endMembership($warga, 'pindah_keluarga', $actor);
            Links::startMembership($warga, $tujuan, trim($hubungan), 'pengurus', $actor);

            $link = TautanAkun::where('warga_id', $warga->id)->whereNull('selesai_at')->lockForUpdate()->first();
            if ($link) {
                $akun = Akun::whereKey($link->akun_id)->lockForUpdate()->firstOrFail();
                Links::endLink($link, 'pindah_keluarga', $actor);
                Links::startLink($akun, $warga, false, 'pengurus', $actor);
            }
            Links::bump($asal);
            Links::bump($tujuan);
            Audit::record($actor, 'warga.pindah_keluarga', 'warga', $warga->id, [
                'dari' => $asal->id, 'ke' => $tujuan->id, 'akun_ikut' => (bool) $link, 'alasan' => $alasan,
            ]);

            return $warga;
        });
    }

    /**
     * Pengurus membuat keluarga (warga tanpa akun boleh). Hunian opsional dibuat atomik lewat
     * Occupancies::beginLocked sehingga keluarga tidak setengah jadi bila rumah sudah dihuni.
     *
     * @param  array{nama_kepala:string,hubungan_kepala?:?string,whatsapp?:?string,anggota?:array,rumah_id?:?string,jenis_hunian?:?string,mulai_tanggal?:?string}  $data
     */
    public static function create(Akun $actor, array $data): Keluarga
    {
        return Links::transaction(function () use ($actor, $data) {
            if (! empty($data['rumah_id'])) {
                Links::lockHouses([$data['rumah_id']]);
            }
            $k = Keluarga::create(['status' => 'belum_verifikasi']);
            $kepala = Warga::create([
                'keluarga_id' => $k->id,
                'nama' => trim($data['nama_kepala']),
                'hubungan_keluarga' => trim($data['hubungan_kepala'] ?? '') ?: 'Kepala keluarga',
                'is_kepala' => true,
                'whatsapp' => $data['whatsapp'] ?? null,
            ]);
            Links::startMembership($kepala, $k, $kepala->hubungan_keluarga, 'pengurus', $actor);
            foreach ($data['anggota'] ?? [] as $a) {
                $w = Warga::create([
                    'keluarga_id' => $k->id,
                    'nama' => trim($a['nama']),
                    'hubungan_keluarga' => trim($a['hubungan_keluarga']),
                    'whatsapp' => $a['whatsapp'] ?? null,
                ]);
                Links::startMembership($w, $k, $w->hubungan_keluarga, 'pengurus', $actor);
            }
            Audit::record($actor, 'keluarga.dibuat', 'keluarga', $k->id, ['anggota' => 1 + count($data['anggota'] ?? [])]);
            if (! empty($data['rumah_id'])) {
                $k = Keluarga::whereKey($k->id)->lockForUpdate()->first();
                Occupancies::beginLocked($actor, $data['rumah_id'], $k, $data['jenis_hunian'] ?? 'pemilik', $data['mulai_tanggal'] ?? null);
            }

            return $k->refresh();
        });
    }

    /** Ganti kepala keluarga ke anggota aktif lain (versi wajib). Hubungan diubah terpisah. */
    public static function changeHead(Akun $actor, string $keluargaId, string $wargaId, mixed $versi): void
    {
        Links::transaction(function () use ($actor, $keluargaId, $wargaId, $versi) {
            $k = self::lockActive($keluargaId);
            Links::assertEditor($actor, $k, true);
            Links::assertVersion($k, $versi);
            $baru = Links::memberOf($k, $wargaId);
            if ($baru->is_kepala) {
                throw new DomainConflict('ALREADY_HEAD', 'Anggota ini sudah menjadi kepala keluarga.', 422);
            }
            $lama = Warga::where('keluarga_id', $k->id)->where('is_kepala', true)->whereNull('archived_at')->first();
            $lama?->forceFill(['is_kepala' => false])->save();
            $baru->forceFill(['is_kepala' => true])->save();
            Links::bump($k);
            Audit::record($actor, 'keluarga.ganti_kepala', 'keluarga', $k->id, ['dari' => $lama?->id, 'ke' => $baru->id]);
        });
    }

    public static function verify(Akun $actor, string $keluargaId, mixed $versi): Keluarga
    {
        return Links::transaction(function () use ($actor, $keluargaId, $versi) {
            $k = self::lockActive($keluargaId);
            Links::assertEditor($actor, $k, true);
            Links::assertVersion($k, $versi);
            if ($k->status !== 'terverifikasi') {
                $k->forceFill(['status' => 'terverifikasi'])->save();
                Links::bump($k);
                Audit::record($actor, 'keluarga.diverifikasi', 'keluarga', $k->id);
            }

            return $k->refresh();
        });
    }

    /**
     * Arsip keluarga: hanya bila tidak menghuni rumah dan tidak memiliki akun tertaut aktif
     * (akhiri hunian/arsip akun lebih dulu, eksplisit dan teraudit). Keanggotaan ditutup, warga
     * diarsipkan; histori dan tagihan rumah tetap.
     */
    public static function archive(Akun $actor, string $keluargaId, string $alasan): void
    {
        Links::transaction(function () use ($actor, $keluargaId, $alasan) {
            $k = self::lockActive($keluargaId);
            Links::assertEditor($actor, $k, true);
            if (Penghunian::where('keluarga_id', $k->id)->whereNull('selesai_tanggal')->exists()) {
                throw new DomainConflict('FAMILY_OCCUPYING', 'Akhiri hunian keluarga ini sebelum diarsipkan.');
            }
            if (TautanAkun::where('keluarga_id', $k->id)->whereNull('selesai_at')->exists()) {
                throw new DomainConflict('FAMILY_HAS_ACCOUNTS', 'Arsipkan atau pindahkan akun keluarga ini lebih dulu.');
            }
            $anggota = Warga::whereIn('id', KeanggotaanKeluarga::where('keluarga_id', $k->id)->whereNull('selesai_at')->select('warga_id'))->get();
            foreach ($anggota as $w) {
                Links::endMembership($w, 'diarsipkan', $actor);
                $w->forceFill(['archived_at' => now(), 'status' => $w->status === 'aktif' ? 'pindah' : $w->status])->save();
            }
            $k->forceFill(['archived_at' => now()])->save();
            Links::bump($k);
            Audit::record($actor, 'keluarga.diarsipkan', 'keluarga', $k->id, ['anggota' => $anggota->count(), 'alasan' => $alasan]);
        });
    }

    /**
     * Pindahkan status akun utama ke akun aktif lain yang tertaut pada keluarga yang sama.
     * Tautan lama ditutup (pergantian_utama) dan dibuka ulang dengan peran baru; histori tetap.
     */
    public static function transferPrimary(Akun $actor, string $keluargaId, string $akunId, mixed $versi, string $alasan): void
    {
        Links::transaction(function () use ($actor, $keluargaId, $akunId, $versi, $alasan) {
            $k = self::lockActive($keluargaId);
            Links::assertEditor($actor, $k, true);
            Links::assertVersion($k, $versi);
            $target = TautanAkun::where('keluarga_id', $k->id)->where('akun_id', $akunId)->whereNull('selesai_at')->lockForUpdate()->first();
            if (! $target) {
                throw new DomainConflict('NOT_FOUND', 'Data tidak ditemukan.', 404);
            }
            if ($target->is_utama) {
                throw new DomainConflict('ALREADY_PRIMARY', 'Akun ini sudah menjadi akun utama.', 422);
            }
            $current = TautanAkun::where('keluarga_id', $k->id)->where('is_utama', true)->whereNull('selesai_at')->lockForUpdate()->first();
            $ids = array_values(array_filter([$target->akun_id, $current?->akun_id]));
            sort($ids);
            $akun = Akun::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($akun[$target->akun_id]->status !== 'aktif') {
                throw new DomainConflict('ACCOUNT_NOT_ACTIVE', 'Akun utama harus berstatus aktif.', 422);
            }
            if ($current) {
                Links::endLink($current, 'pergantian_utama', $actor);
                Links::startLink($akun[$current->akun_id], Warga::findOrFail($current->warga_id), false, 'pengurus', $actor);
            }
            Links::endLink($target, 'pergantian_utama', $actor);
            Links::startLink($akun[$target->akun_id], Warga::findOrFail($target->warga_id), true, 'pengurus', $actor);
            Links::bump($k);
            Audit::record($actor, 'keluarga.ganti_akun_utama', 'keluarga', $k->id, [
                'dari' => $current?->akun_id, 'ke' => $target->akun_id, 'alasan' => $alasan,
            ]);
        });
    }

    /** Keluarga aktif dari keanggotaan aktif warga (tanpa lock; dikunci ulang oleh pemanggil). */
    public static function familyOf(string $wargaId): string
    {
        $id = KeanggotaanKeluarga::where('warga_id', $wargaId)->whereNull('selesai_at')->value('keluarga_id');
        if (! $id) {
            throw new DomainConflict('NOT_FOUND', 'Data tidak ditemukan.', 404);
        }

        return $id;
    }

    /**
     * Anggota aktif keluarga terkunci. Jalur pengurus: anggota yang baru saja dipindah ke keluarga
     * lain oleh transaksi bersamaan menjadi 409 CONFLICT (bukan 404). Jalur akun utama tetap 404
     * agar keberadaan anggota keluarga lain tidak terungkap.
     */
    private static function member(Keluarga $k, string $wargaId, bool $viaPengurus): Warga
    {
        if ($viaPengurus) {
            $sekarang = KeanggotaanKeluarga::where('warga_id', $wargaId)->whereNull('selesai_at')->value('keluarga_id');
            if ($sekarang && $sekarang !== $k->id) {
                throw new DomainConflict('CONFLICT', 'Data anggota berubah bersamaan. Muat ulang lalu coba lagi.');
            }
        }

        return Links::memberOf($k, $wargaId);
    }

    private static function lockActive(string $keluargaId): Keluarga
    {
        return self::active(Links::lockFamilies([$keluargaId])[$keluargaId] ?? null);
    }

    private static function active(?Keluarga $k): Keluarga
    {
        if (! $k) {
            throw new DomainConflict('NOT_FOUND', 'Data tidak ditemukan.', 404);
        }
        if ($k->archived_at) {
            throw new DomainConflict('FAMILY_ARCHIVED', 'Keluarga sudah diarsipkan.');
        }

        return $k;
    }
}
