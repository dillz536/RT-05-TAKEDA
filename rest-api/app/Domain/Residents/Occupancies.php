<?php

namespace App\Domain\Residents;

use App\Exceptions\DomainConflict;
use App\Models\Akun;
use App\Models\Keluarga;
use App\Models\Penghunian;
use App\Models\Rumah;
use App\Support\Audit;
use Carbon\CarbonImmutable;

/**
 * Hunian keluarga di rumah (BR v1): satu hunian aktif per rumah (= keluarga utama rumah) dan per
 * keluarga, rentang tanggal Asia/Jakarta tanpa tumpang tindih dengan histori rumah/keluarga.
 * Tagihan melekat pada rumah: memulai/mengakhiri/memindah hunian tidak membuat, memindah,
 * menghapus, atau membebaskan tagihan; tagihan_aktif/mulai_tagih tetap keputusan pengurus.
 * Tautan akun mengikuti keluarga, jadi rumah akun ikut berubah tanpa rotasi tautan.
 */
final class Occupancies
{
    public static function begin(Akun $actor, string $rumahId, string $keluargaId, string $jenis, ?string $mulai): Penghunian
    {
        return Links::transaction(function () use ($actor, $rumahId, $keluargaId, $jenis, $mulai) {
            Links::lockHouses([$rumahId]);
            $k = Links::lockFamilies([$keluargaId])[$keluargaId] ?? throw new DomainConflict('NOT_FOUND', 'Data tidak ditemukan.', 404);

            return self::beginLocked($actor, $rumahId, $k, $jenis, $mulai);
        });
    }

    /** Dipanggil setelah rumah lalu keluarga dikunci (urutan lock tetap). */
    public static function beginLocked(Akun $actor, string $rumahId, Keluarga $k, string $jenis, ?string $mulai): Penghunian
    {
        $rumah = Rumah::find($rumahId) ?? throw new DomainConflict('NOT_FOUND', 'Data tidak ditemukan.', 404);
        if ($rumah->archived_at) {
            throw new DomainConflict('HOUSE_ARCHIVED', 'Rumah sudah diarsipkan.');
        }
        if ($k->archived_at) {
            throw new DomainConflict('FAMILY_ARCHIVED', 'Keluarga sudah diarsipkan.');
        }
        $mulai = self::date($mulai);
        if (Penghunian::where('rumah_id', $rumah->id)->whereNull('selesai_tanggal')->exists()) {
            throw new DomainConflict('HOUSE_OCCUPIED', 'Rumah masih dihuni keluarga lain.');
        }
        if (Penghunian::where('keluarga_id', $k->id)->whereNull('selesai_tanggal')->exists()) {
            throw new DomainConflict('FAMILY_ALREADY_OCCUPYING', 'Keluarga masih menghuni rumah lain. Gunakan pindah rumah.');
        }
        $overlap = Penghunian::where(fn ($q) => $q->where('rumah_id', $rumah->id)->orWhere('keluarga_id', $k->id))
            ->where('selesai_tanggal', '>', $mulai)->exists();
        if ($overlap) {
            throw new DomainConflict('DATE_OVERLAP', 'Tanggal mulai tumpang tindih dengan riwayat hunian.', 422);
        }

        $p = Penghunian::create(['rumah_id' => $rumah->id, 'keluarga_id' => $k->id, 'jenis_hunian' => $jenis, 'mulai_tanggal' => $mulai]);
        Links::bump($k);
        Audit::record($actor, 'penghunian.dimulai', 'penghunian', $p->id, ['rumah_id' => $rumah->id, 'keluarga_id' => $k->id, 'jenis_hunian' => $jenis]);

        return $p;
    }

    public static function end(Akun $actor, string $penghunianId, ?string $selesai, string $alasan): Penghunian
    {
        return Links::transaction(function () use ($actor, $penghunianId, $selesai, $alasan) {
            [$p, $k] = self::lockOccupancy($penghunianId, []);

            return self::endLocked($actor, $p, $k, $selesai, $alasan);
        });
    }

    /**
     * Pindah rumah: akhiri hunian lama dan mulai hunian baru pada tanggal yang sama, atomik.
     * Tagihan dan tunggakan rumah lama tetap di rumah lama.
     */
    public static function move(Akun $actor, string $penghunianId, string $rumahBaruId, ?string $jenis, ?string $tanggal, string $alasan): Penghunian
    {
        return Links::transaction(function () use ($actor, $penghunianId, $rumahBaruId, $jenis, $tanggal, $alasan) {
            [$p, $k] = self::lockOccupancy($penghunianId, [$rumahBaruId]);
            if ($p->rumah_id === $rumahBaruId) {
                throw new DomainConflict('SAME_HOUSE', 'Keluarga sudah menghuni rumah ini.', 422);
            }
            $tanggal = self::date($tanggal);
            self::endLocked($actor, $p, $k, $tanggal, $alasan);
            $baru = self::beginLocked($actor, $rumahBaruId, $k, $jenis ?? $p->jenis_hunian, $tanggal);
            Audit::record($actor, 'penghunian.pindah', 'keluarga', $k->id, ['dari' => $p->rumah_id, 'ke' => $rumahBaruId, 'alasan' => $alasan]);

            return $baru;
        });
    }

    /** Kunci rumah (lama + tambahan) lalu keluarga, kemudian baca ulang hunian di bawah lock. */
    private static function lockOccupancy(string $penghunianId, array $extraHouses): array
    {
        $snapshot = Penghunian::find($penghunianId) ?? throw new DomainConflict('NOT_FOUND', 'Data tidak ditemukan.', 404);
        Links::lockHouses([$snapshot->rumah_id, ...$extraHouses]);
        $k = Links::lockFamilies([$snapshot->keluarga_id])[$snapshot->keluarga_id];
        $p = Penghunian::whereKey($penghunianId)->lockForUpdate()->first();

        return [$p, $k];
    }

    private static function endLocked(Akun $actor, Penghunian $p, Keluarga $k, ?string $selesai, string $alasan): Penghunian
    {
        if ($p->selesai_tanggal) {
            throw new DomainConflict('OCCUPANCY_ENDED', 'Hunian sudah berakhir.');
        }
        $selesai = self::date($selesai);
        if ($selesai < $p->mulai_tanggal->toDateString()) {
            throw new DomainConflict('DATE_OVERLAP', 'Tanggal selesai tidak boleh sebelum tanggal mulai.', 422);
        }
        $p->forceFill(['selesai_tanggal' => $selesai])->save();
        Links::bump($k);
        Audit::record($actor, 'penghunian.diakhiri', 'penghunian', $p->id, ['rumah_id' => $p->rumah_id, 'keluarga_id' => $k->id, 'alasan' => $alasan]);

        return $p;
    }

    /** Tanggal Asia/Jakarta (default hari ini); tanggal masa depan ditolak. */
    private static function date(?string $date): string
    {
        $today = CarbonImmutable::now('Asia/Jakarta')->toDateString();
        $date ??= $today;
        if ($date > $today) {
            throw new DomainConflict('DATE_IN_FUTURE', 'Tanggal tidak boleh melewati hari ini.', 422);
        }

        return $date;
    }
}
