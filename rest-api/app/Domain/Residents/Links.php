<?php

namespace App\Domain\Residents;

use App\Exceptions\DomainConflict;
use App\Models\Akun;
use App\Models\KeanggotaanKeluarga;
use App\Models\Keluarga;
use App\Models\Rumah;
use App\Models\TautanAkun;
use App\Models\Warga;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Primitif M07 untuk keanggotaan keluarga dan tautan akun. Dipanggil di dalam transaksi domain
 * setelah baris keluarga terkait dikunci. Setiap perubahan menulis histori baru (bukan mengedit
 * rentang lama) lalu menyinkronkan pointer lama (warga.keluarga_id/hubungan, akun.warga_id/jenis)
 * agar image M06 tetap konsisten.
 *
 * Urutan lock seluruh layanan rumah/keluarga: permohonan → rumah (id naik) → keluarga (id naik)
 * → tautan → akun. Tautan hanya diubah sambil memegang lock keluarga tautan tersebut.
 * Constraint unik Postgres adalah pertahanan terakhir dan dipetakan ke 409, bukan 500; deadlock
 * atau kegagalan serialisasi yang tetap terjadi juga menjadi 409 CONFLICT (sudah di-rollback).
 */
final class Links
{
    /** Transaksi domain; pelanggaran constraint unik dari penulis bersamaan menjadi 409 spesifik. */
    public static function transaction(Closure $work): mixed
    {
        try {
            return DB::transaction($work);
        } catch (UniqueConstraintViolationException $e) {
            $m = $e->getMessage();
            throw match (true) {
                str_contains($m, 'penghunian_satu_aktif_per_rumah') => new DomainConflict('HOUSE_OCCUPIED', 'Rumah masih dihuni keluarga lain.'),
                str_contains($m, 'penghunian_satu_aktif_per_keluarga') => new DomainConflict('FAMILY_ALREADY_OCCUPYING', 'Keluarga masih menghuni rumah lain. Gunakan pindah rumah.'),
                str_contains($m, 'tautan_satu_utama_per_keluarga') => new DomainConflict('PRIMARY_ACCOUNT_EXISTS', 'Keluarga sudah memiliki akun utama aktif.'),
                str_contains($m, 'tautan_satu_aktif'), str_contains($m, 'akun_warga_id') => new DomainConflict('RESIDENT_HAS_ACCOUNT', 'Anggota atau akun ini sudah tertaut.'),
                str_contains($m, 'keanggotaan_satu_aktif'), str_contains($m, 'warga_satu_kepala') => new DomainConflict('CONFLICT', 'Data keluarga berubah bersamaan. Muat ulang lalu coba lagi.'),
                str_contains($m, 'rumah_alamat_key') => new DomainConflict('HOUSE_EXISTS', 'Rumah dengan alamat ini sudah ada.'),
                default => new DomainConflict('CONFLICT', 'Perubahan bersamaan terdeteksi. Coba lagi.'),
            };
        } catch (QueryException|DeadlockException $e) {
            // 40P01 deadlock_detected, 40001 serialization_failure: transaksi batal utuh, aman diulang.
            // Di transaksi bersarang Laravel membungkusnya sebagai DeadlockException.
            $state = (string) ($e instanceof DeadlockException ? $e->getPrevious()?->getCode() : $e->getCode());
            if (! in_array($state, ['40P01', '40001'], true)) {
                throw $e;
            }
            Log::warning('Konflik konkurensi database dipetakan ke 409.', ['sqlstate' => $state]);
            throw new DomainConflict('CONFLICT', 'Perubahan bersamaan terdeteksi. Coba lagi.');
        }
    }

    /**
     * Hak ubah aktor dibaca ulang dari database setelah keluarga dikunci, bukan dari relasi
     * Eloquent yang dimuat sebelum lock (middleware/controller): akun masih aktif, lalu pengurus
     * (hak setara) atau tautan aktif akun utama pada keluarga yang terkunci. Pindah utama, arsip
     * akun, dan pindah anggota mengubah tautan sambil memegang lock keluarga yang sama, sehingga
     * pembacaan ini (READ COMMITTED, per statement) sudah melihat perubahan yang commit lebih dulu.
     * Tidak mengambil lock baru, jadi urutan lock tidak bertambah.
     */
    public static function assertEditor(Akun $actor, Keluarga $locked, bool $pengurusOnly = false): void
    {
        $fresh = Akun::query()->whereKey($actor->id)->first(['id', 'peran', 'status']);
        if (! $fresh || $fresh->status !== 'aktif') {
            throw new AuthorizationException('Akun tidak aktif.');
        }
        if ($fresh->isPengurus()) {
            return;
        }
        if ($pengurusOnly) {
            throw new AuthorizationException('Khusus pengurus.');
        }
        $link = TautanAkun::where('akun_id', $actor->id)->whereNull('selesai_at')->first(['keluarga_id', 'is_utama']);
        if (! $link) {
            throw new DomainConflict('HOUSEHOLD_NOT_LINKED', 'Akun belum terhubung dengan keluarga.', 404);
        }
        if ($link->keluarga_id !== $locked->id) {
            throw new DomainConflict('CONFLICT', 'Tautan akun berubah bersamaan. Muat ulang lalu coba lagi.');
        }
        if (! $link->is_utama) {
            throw new DomainConflict('PRIMARY_ACCOUNT_REQUIRED', 'Hanya akun utama yang dapat mengubah data keluarga.', 403);
        }
    }

    /** @param  list<string|null>  $ids */
    public static function lockHouses(array $ids): void
    {
        $ids = array_values(array_unique(array_filter($ids)));
        sort($ids);
        foreach ($ids as $id) {
            Rumah::whereKey($id)->lockForUpdate()->first();
        }
    }

    /**
     * Kunci keluarga (urutan id naik) dan kembalikan baris segar yang terkunci.
     *
     * @param  list<string|null>  $ids
     * @return array<string, Keluarga>
     */
    public static function lockFamilies(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        sort($ids);
        $locked = [];
        foreach ($ids as $id) {
            if ($k = Keluarga::whereKey($id)->lockForUpdate()->first()) {
                $locked[$id] = $k;
            }
        }

        return $locked;
    }

    /** Optimistic version keluarga (wajib pada semua mutasi keluarga/anggota; divalidasi controller). */
    public static function assertVersion(Keluarga $locked, mixed $versi): void
    {
        if ($locked->versi !== (int) $versi) {
            throw new DomainConflict('VERSION_CONFLICT', 'Data keluarga sudah berubah. Muat ulang lalu coba lagi.');
        }
    }

    public static function bump(Keluarga $locked): void
    {
        $locked->increment('versi');
    }

    public static function activeMembership(Warga $warga): ?KeanggotaanKeluarga
    {
        return KeanggotaanKeluarga::where('warga_id', $warga->id)->whereNull('selesai_at')->lockForUpdate()->first();
    }

    /** Anggota aktif keluarga ini (404 bila di luar scope, termasuk keluarga lain). */
    public static function memberOf(Keluarga $keluarga, string $wargaId): Warga
    {
        $warga = Warga::whereKey($wargaId)
            ->whereHas('keanggotaanAktif', fn ($q) => $q->where('keluarga_id', $keluarga->id))
            ->first();
        if (! $warga) {
            throw new DomainConflict('NOT_FOUND', 'Data tidak ditemukan.', 404);
        }

        return $warga;
    }

    public static function startMembership(Warga $warga, Keluarga $keluarga, string $hubungan, string $sumber, ?Akun $actor): KeanggotaanKeluarga
    {
        $m = KeanggotaanKeluarga::create([
            'keluarga_id' => $keluarga->id,
            'warga_id' => $warga->id,
            'hubungan_keluarga' => $hubungan,
            'mulai_at' => now(),
            'sumber' => $sumber,
            'dicatat_oleh_id' => $actor?->id,
        ]);
        if ($warga->keluarga_id !== $keluarga->id || $warga->hubungan_keluarga !== $hubungan) {
            $warga->forceFill(['keluarga_id' => $keluarga->id, 'hubungan_keluarga' => $hubungan])->save();
        }

        return $m;
    }

    public static function endMembership(Warga $warga, string $alasan, ?Akun $actor): KeanggotaanKeluarga
    {
        $m = self::activeMembership($warga);
        if (! $m) {
            throw new DomainConflict('MEMBERSHIP_ENDED', 'Keanggotaan sudah berakhir.');
        }
        $m->forceFill(['selesai_at' => now(), 'alasan_selesai' => $alasan, 'diakhiri_oleh_id' => $actor?->id])->save();

        return $m;
    }

    /** Tautan baru; pointer akun.warga_id dan akun.jenis mengikuti tautan terbaru. */
    public static function startLink(Akun $akun, Warga $warga, bool $utama, string $sumber, ?Akun $actor): TautanAkun
    {
        $t = TautanAkun::create([
            'akun_id' => $akun->id,
            'warga_id' => $warga->id,
            'keluarga_id' => $warga->keluarga_id,
            'is_utama' => $utama,
            'mulai_at' => now(),
            'sumber' => $sumber,
            'dicatat_oleh_id' => $actor?->id,
        ]);
        $akun->forceFill(['warga_id' => $warga->id, 'jenis' => $utama ? 'utama' : 'tambahan'])->save();
        $akun->unsetRelation('tautanAktif');

        return $t;
    }

    public static function endLink(TautanAkun $link, string $alasan, ?Akun $actor): void
    {
        $link->forceFill(['selesai_at' => now(), 'alasan_selesai' => $alasan, 'diakhiri_oleh_id' => $actor?->id])->save();
    }
}
