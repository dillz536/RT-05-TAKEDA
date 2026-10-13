<?php

namespace App\Domain\Identity;

use App\Domain\Residents\Address;
use App\Domain\Residents\Links;
use App\Exceptions\DomainConflict;
use App\Models\Akun;
use App\Models\Keluarga;
use App\Models\Penghunian;
use App\Models\PermohonanAkun;
use App\Models\PreferensiNotifikasi;
use App\Models\Rumah;
use App\Models\Warga;
use App\Support\Audit;
use App\Support\Outbox;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Registrasi (FR03/FR05, BR03/BR05/BR06). Satu transaksi: akun + rumah + keluarga + warga + penghunian
 * + keanggotaan + tautan akun utama (M07) + preferensi + audit/outbox; token perangkat dibuat pemanggil di transaksi luar yang sama.
 * Rumah dengan alamat ternormalisasi yang sudah ada (termasuk rumah kosong/arsip yang dibuat
 * pengurus) selalu ditolak tanpa auto-merge; pemohon diarahkan ke akun tambahan/pengurus.
 *
 * Concurrency: registrasi diserialkan advisory lock transaksi dan token aktif dikunci FOR SHARE.
 * Constraint unik PostgreSQL (email, alamat_key, kode_rumah, satu penghunian aktif) tetap menjadi
 * pertahanan terakhir terhadap penulis lain dan dipetakan ke konflik generik, bukan 500.
 */
final class Registration
{
    public const HOUSE_EXISTS = 'Rumah ini sudah terdaftar. Daftar sebagai akun tambahan dan tunggu persetujuan pengurus.';

    public const REJECTED = 'Pendaftaran tidak dapat diproses. Hubungi pengurus bila sudah pernah mendaftar.';

    /**
     * @param  array{email:string,password:string,nama_kepala:string,whatsapp:?string,blok:?string,jalan:string,nomor:string,jenis_hunian:string,anggota:array<int,array{nama:string,hubungan_keluarga:string}>}  $data
     */
    public static function primary(array $data, string $tokenCode): Akun
    {
        return self::guarded(function () use ($data, $tokenCode) {
            $token = self::lockAndVerify($tokenCode);

            $email = self::email($data['email']);
            if (Akun::where('email', $email)->exists()) {
                // Pesan generik agar tidak membuka daftar email warga.
                throw new DomainConflict('REGISTRATION_REJECTED', self::REJECTED, 422);
            }

            $key = Address::key($data['blok'] ?? null, $data['jalan'], $data['nomor']);
            if (Rumah::where('alamat_key', $key)->exists()) {
                throw new DomainConflict('HOUSE_ALREADY_REGISTERED', self::HOUSE_EXISTS);
            }

            $code = Address::code($data['blok'] ?? null, $data['nomor']);
            $suffix = 1;
            $base = $code;
            while (Rumah::where('kode_rumah', $code)->exists()) {
                $code = $base.'-'.(++$suffix);
            }
            $rumah = Rumah::create([
                'kode_rumah' => $code,
                'blok' => $data['blok'] ?? null,
                'jalan' => trim($data['jalan']),
                'nomor' => trim($data['nomor']),
                'alamat_key' => $key,
                'tagihan_aktif' => true,
                'mulai_tagih' => now('Asia/Jakarta')->startOfMonth()->toDateString(),
            ]);

            $keluarga = Keluarga::create(['status' => 'belum_verifikasi']);
            $kepala = Warga::create([
                'keluarga_id' => $keluarga->id,
                'nama' => trim($data['nama_kepala']),
                'hubungan_keluarga' => 'Kepala keluarga',
                'is_kepala' => true,
                'whatsapp' => $data['whatsapp'] ?? null,
            ]);
            Links::startMembership($kepala, $keluarga, $kepala->hubungan_keluarga, 'registrasi', null);
            foreach ($data['anggota'] ?? [] as $anggota) {
                $w = Warga::create([
                    'keluarga_id' => $keluarga->id,
                    'nama' => trim($anggota['nama']),
                    'hubungan_keluarga' => trim($anggota['hubungan_keluarga']),
                    'is_kepala' => false,
                ]);
                Links::startMembership($w, $keluarga, $w->hubungan_keluarga, 'registrasi', null);
            }
            Penghunian::create([
                'rumah_id' => $rumah->id,
                'keluarga_id' => $keluarga->id,
                'jenis_hunian' => $data['jenis_hunian'],
                'mulai_tanggal' => now('Asia/Jakarta')->toDateString(),
            ]);

            $akun = Akun::create([
                'warga_id' => $kepala->id,
                'nama' => $kepala->nama,
                'email' => $email,
                'password_hash' => $data['password'],
                'jenis' => 'utama',
                'status' => 'aktif',
            ]);
            Links::startLink($akun, $kepala, true, 'registrasi', null);
            PermohonanAkun::create([
                'akun_id' => $akun->id,
                'token_id' => $token->id,
                'jenis' => 'utama',
                'status' => 'disetujui',
                'rumah_id' => $rumah->id,
                'diproses_at' => now(),
                'catatan' => 'Akun utama aktif otomatis; data keluarga menunggu verifikasi pengurus.',
            ]);
            PreferensiNotifikasi::create(['akun_id' => $akun->id]);

            Audit::record($akun, 'akun.registrasi_utama', 'akun', $akun->id, ['rumah_id' => $rumah->id, 'anggota' => count($data['anggota'] ?? [])]);
            Outbox::record('akun.registrasi_utama', 'akun', $akun->id);

            return $akun;
        });
    }

    /** Akun tambahan: status menunggu sampai pengurus menyetujui; rumah dicocokkan diam-diam. */
    public static function additional(array $data, string $tokenCode): Akun
    {
        return self::guarded(function () use ($data, $tokenCode) {
            $token = self::lockAndVerify($tokenCode);
            $email = self::email($data['email']);
            if (Akun::where('email', $email)->exists()) {
                throw new DomainConflict('REGISTRATION_REJECTED', self::REJECTED, 422);
            }

            $rumah = Rumah::where('alamat_key', Address::key($data['blok'] ?? null, $data['jalan'], $data['nomor']))->first();

            $akun = Akun::create([
                'nama' => trim($data['nama']),
                'email' => $email,
                'password_hash' => $data['password'],
                'jenis' => 'tambahan',
                'status' => 'menunggu',
            ]);
            PermohonanAkun::create([
                'akun_id' => $akun->id,
                'token_id' => $token->id,
                'jenis' => 'tambahan',
                'status' => 'diajukan',
                'rumah_id' => $rumah?->id,
                'nama_pemohon' => trim($data['nama']),
                'hubungan_keluarga' => trim($data['hubungan_keluarga']),
                'alamat_diajukan' => trim(($data['blok'] ?? '').' '.$data['jalan'].' '.$data['nomor']),
            ]);
            PreferensiNotifikasi::create(['akun_id' => $akun->id]);

            Audit::record($akun, 'akun.registrasi_tambahan', 'akun', $akun->id, ['rumah_cocok' => (bool) $rumah]);
            Outbox::record('akun.permohonan_tambahan', 'akun', $akun->id);

            return $akun;
        });
    }

    /**
     * Persetujuan pengurus (M07): akun tambahan ditautkan sebagai akun bukan-utama ke keluarga yang
     * menghuni rumah, dalam satu transaksi dengan keanggotaan, versi keluarga, audit dan outbox.
     * $wargaId opsional menautkan anggota aktif yang belum punya akun (tanpa warga ganda); bila
     * kosong, warga baru dibuat dari data permohonan. Peran tetap warga; rumah/tagihan tidak berubah.
     * Urutan lock: permohonan → rumah → keluarga → akun.
     */
    public static function approve(PermohonanAkun $permohonan, Akun $pengurus, ?string $rumahId, ?string $catatan, ?string $wargaId = null): void
    {
        Links::transaction(function () use ($permohonan, $pengurus, $rumahId, $catatan, $wargaId) {
            $permohonan = PermohonanAkun::whereKey($permohonan->id)->lockForUpdate()->firstOrFail();
            if ($permohonan->status !== 'diajukan' || $permohonan->jenis !== 'tambahan') {
                throw new DomainConflict('REQUEST_ALREADY_PROCESSED', 'Permohonan sudah diproses.');
            }
            $rumahId ??= $permohonan->rumah_id;
            Links::lockHouses([$rumahId]);
            $rumah = $rumahId ? Rumah::whereNull('archived_at')->find($rumahId) : null;
            $penghunian = $rumah?->penghunianAktif;
            $keluarga = $penghunian ? (Links::lockFamilies([$penghunian->keluarga_id])[$penghunian->keluarga_id] ?? null) : null;
            if (! $keluarga || $keluarga->archived_at) {
                throw new DomainConflict('HOUSE_NOT_OCCUPIED', 'Pilih rumah yang memiliki keluarga aktif.', 422);
            }

            if ($wargaId) {
                $warga = Links::memberOf($keluarga, $wargaId);
                if ($warga->akun()->exists()) {
                    throw new DomainConflict('RESIDENT_HAS_ACCOUNT', 'Anggota ini sudah memiliki akun.');
                }
            } else {
                $warga = Warga::create([
                    'keluarga_id' => $keluarga->id,
                    'nama' => $permohonan->nama_pemohon,
                    'hubungan_keluarga' => $permohonan->hubungan_keluarga ?? 'Anggota keluarga',
                ]);
                Links::startMembership($warga, $keluarga, $warga->hubungan_keluarga, 'persetujuan', $pengurus);
            }
            $akun = Akun::whereKey($permohonan->akun_id)->lockForUpdate()->firstOrFail();
            if ($akun->status !== 'menunggu') {
                throw new DomainConflict('REQUEST_ALREADY_PROCESSED', 'Permohonan sudah diproses.');
            }
            Links::startLink($akun, $warga, false, 'persetujuan', $pengurus);
            $akun->forceFill(['status' => 'aktif'])->save();
            $akun->endBrowserSessions();
            Links::bump($keluarga);
            $permohonan->forceFill([
                'status' => 'disetujui',
                'rumah_id' => $rumah->id,
                'diproses_oleh_id' => $pengurus->id,
                'diproses_at' => now(),
                'catatan' => $catatan,
            ])->save();

            Audit::record($pengurus, 'permohonan_akun.disetujui', 'permohonan_akun', $permohonan->id, [
                'akun_id' => $akun->id, 'keluarga_id' => $keluarga->id, 'warga_existing' => (bool) $wargaId,
            ]);
            Outbox::record('akun.permohonan_disetujui', 'akun', $akun->id);
        });
    }

    /**
     * Penolakan akun tambahan: permohonan ditolak dengan alasan, akun tetap jenis tambahan berstatus
     * menunggu (bukan nonaktif) sehingga pemohon masih dapat masuk dengan akses terbatas
     * (me/status/logout) untuk membaca alasan. Tanpa warga/rumah, jadi tanpa layanan warga.
     * Bearer dan sesi lama dicabut; login ulang menerbitkan bearer terbatas yang sama.
     */
    public static function reject(PermohonanAkun $permohonan, Akun $pengurus, string $alasan): void
    {
        DB::transaction(function () use ($permohonan, $pengurus, $alasan) {
            $permohonan = PermohonanAkun::whereKey($permohonan->id)->lockForUpdate()->firstOrFail();
            if ($permohonan->status !== 'diajukan') {
                throw new DomainConflict('REQUEST_ALREADY_PROCESSED', 'Permohonan sudah diproses.');
            }
            $permohonan->forceFill(['status' => 'ditolak', 'diproses_oleh_id' => $pengurus->id, 'diproses_at' => now(), 'catatan' => $alasan])->save();
            $akun = Akun::whereKey($permohonan->akun_id)->lockForUpdate()->firstOrFail();
            $akun->revokeAllAccess();

            Audit::record($pengurus, 'permohonan_akun.ditolak', 'permohonan_akun', $permohonan->id, ['alasan' => $alasan]);
        });
    }

    /** Email dinormalisasi sama seperti constraint akun_email_lowercase dan login. */
    public static function email(string $email): string
    {
        return strtolower(trim($email));
    }

    private static function lockAndVerify(string $tokenCode)
    {
        DB::select("SELECT pg_advisory_xact_lock(hashtext('rt05:registrasi'))");
        $token = RegistrationToken::verify($tokenCode);
        if (! $token) {
            throw new DomainConflict('REGISTRATION_TOKEN_INVALID', 'Data pendaftaran tidak dapat diproses. Periksa kembali token RT.', 422);
        }

        return $token;
    }

    /** Transaksi registrasi; pelanggaran constraint unik dari penulis concurrent menjadi konflik generik. */
    private static function guarded(Closure $work): Akun
    {
        try {
            return DB::transaction($work);
        } catch (UniqueConstraintViolationException $e) {
            $constraint = $e->getMessage();
            throw match (true) {
                str_contains($constraint, 'akun_email') => new DomainConflict('REGISTRATION_REJECTED', self::REJECTED, 422),
                str_contains($constraint, 'rumah_alamat_key'), str_contains($constraint, 'penghunian_satu_aktif'),
                str_contains($constraint, 'tautan_satu_utama_per_keluarga') => new DomainConflict('HOUSE_ALREADY_REGISTERED', self::HOUSE_EXISTS),
                default => new DomainConflict('CONFLICT', 'Pendaftaran bersamaan terdeteksi. Coba lagi.'),
            };
        }
    }
}
