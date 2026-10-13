<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Identity\Registration;
use App\Domain\Identity\RegistrationToken;
use App\Domain\Residents\Address;
use App\Exceptions\DomainConflict;
use App\Http\Controllers\Controller;
use App\Http\Responses\Api;
use App\Http\Responses\Present;
use App\Models\Akun;
use App\Models\Keluarga;
use App\Models\Penghunian;
use App\Models\PermohonanAkun;
use App\Models\Rumah;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Pendataan oleh pengurus (FR06, FR22): rumah, keluarga, akun, permohonan, token registrasi. */
class ResidentsController extends Controller
{
    public function houses(Request $request): JsonResponse
    {
        $q = Rumah::query()->with(['penghunianAktif.keluarga.warga'])
            ->when(! $request->boolean('arsip'), fn ($q) => $q->whereNull('archived_at'))
            ->when($request->query('q'), fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('kode_rumah', 'ilike', "%$s%")->orWhere('jalan', 'ilike', "%$s%")->orWhere('nomor', 'ilike', "%$s%")))
            ->orderBy('kode_rumah');

        return Api::paginate($request, $q, fn (Rumah $r) => Present::rumah($r) + [
            'keluarga' => $r->penghunianAktif?->keluarga
                ? ['id' => $r->penghunianAktif->keluarga->id, 'status' => $r->penghunianAktif->keluarga->status,
                    'kepala' => $r->penghunianAktif->keluarga->warga->firstWhere('is_kepala', true)?->nama,
                    'jumlah_anggota' => $r->penghunianAktif->keluarga->warga->whereNull('archived_at')->count()]
                : null,
        ]);
    }

    public function showHouse(string $id): JsonResponse
    {
        $r = Rumah::with(['penghunianAktif.keluarga.warga.akun', 'penghunian'])->findOrFail($id);
        $k = $r->penghunianAktif?->keluarga;

        return Api::data(Present::rumah($r) + [
            'keluarga' => $k ? Present::keluarga($k, null) : null,
            'riwayat_penghunian' => $r->penghunian->sortByDesc('mulai_tanggal')->values()->map(fn (Penghunian $p) => [
                'keluarga_id' => $p->keluarga_id, 'jenis_hunian' => $p->jenis_hunian,
                'mulai_tanggal' => $p->mulai_tanggal->toDateString(), 'selesai_tanggal' => $p->selesai_tanggal?->toDateString(),
            ])->all(),
        ]);
    }

    public function storeHouse(Request $request): JsonResponse
    {
        $data = $request->validate([
            'blok' => ['nullable', 'string', 'max:30'],
            'jalan' => ['required', 'string', 'max:150'],
            'nomor' => ['required', 'string', 'max:20'],
            'kode_rumah' => ['nullable', 'string', 'max:30', 'regex:/^[A-Z0-9-]+$/'],
            'tagihan_aktif' => ['boolean'],
            'mulai_tagih' => ['nullable', 'date_format:Y-m'],
        ]);
        $key = Address::key($data['blok'] ?? null, $data['jalan'], $data['nomor']);
        if (Rumah::where('alamat_key', $key)->exists()) {
            throw new DomainConflict('HOUSE_EXISTS', 'Rumah dengan alamat ini sudah ada.');
        }
        $rumah = DB::transaction(function () use ($request, $data, $key) {
            $r = Rumah::create([
                'kode_rumah' => $data['kode_rumah'] ?? Address::code($data['blok'] ?? null, $data['nomor']),
                'blok' => $data['blok'] ?? null,
                'jalan' => $data['jalan'],
                'nomor' => $data['nomor'],
                'alamat_key' => $key,
                'tagihan_aktif' => $data['tagihan_aktif'] ?? true,
                'mulai_tagih' => isset($data['mulai_tagih']) ? $data['mulai_tagih'].'-01' : null,
            ]);
            Audit::record($request->user(), 'rumah.dibuat', 'rumah', $r->id);

            return $r;
        });

        return Api::data(Present::rumah($rumah), 201);
    }

    /** Pengaturan tagihan rumah ditetapkan pengurus dengan catatan audit (BR v1: tidak auto-bebas). */
    public function updateHouse(Request $request, string $id): JsonResponse
    {
        $rumah = Rumah::findOrFail($id);
        $data = $request->validate([
            'tagihan_aktif' => ['sometimes', 'boolean'],
            'mulai_tagih' => ['sometimes', 'nullable', 'date_format:Y-m'],
            'alasan' => ['required', 'string', 'max:300'],
        ]);
        DB::transaction(function () use ($request, $rumah, $data) {
            $before = ['tagihan_aktif' => $rumah->tagihan_aktif, 'mulai_tagih' => $rumah->mulai_tagih?->format('Y-m')];
            if (array_key_exists('tagihan_aktif', $data)) {
                $rumah->tagihan_aktif = $data['tagihan_aktif'];
            }
            if (array_key_exists('mulai_tagih', $data)) {
                $rumah->mulai_tagih = $data['mulai_tagih'] ? $data['mulai_tagih'].'-01' : null;
            }
            $rumah->save();
            Audit::record($request->user(), 'rumah.pengaturan_tagihan', 'rumah', $rumah->id, ['sebelum' => $before, 'alasan' => $data['alasan']]);
        });

        return Api::data(Present::rumah($rumah->refresh()));
    }

    public function archiveHouse(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['alasan' => ['required', 'string', 'max:300']]);
        $rumah = Rumah::findOrFail($id);
        DB::transaction(function () use ($request, $rumah, $data) {
            Penghunian::where('rumah_id', $rumah->id)->whereNull('selesai_tanggal')->update(['selesai_tanggal' => now('Asia/Jakarta')->toDateString()]);
            $rumah->forceFill(['archived_at' => now(), 'tagihan_aktif' => false])->save();
            Audit::record($request->user(), 'rumah.diarsipkan', 'rumah', $rumah->id, ['alasan' => $data['alasan']]);
        });

        return Api::noContent();
    }

    public function verifyFamily(Request $request, string $id): JsonResponse
    {
        $k = Keluarga::findOrFail($id);
        DB::transaction(function () use ($request, $k) {
            $k->forceFill(['status' => 'terverifikasi', 'versi' => $k->versi + 1])->save();
            Audit::record($request->user(), 'keluarga.diverifikasi', 'keluarga', $k->id);
        });

        return Api::data(['id' => $k->id, 'status' => 'terverifikasi']);
    }

    public function accounts(Request $request): JsonResponse
    {
        $q = Akun::query()->with('warga.keluarga.penghunianAktif.rumah')
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('q'), fn ($q, $s) => $q->where(fn ($w) => $w->where('nama', 'ilike', "%$s%")->orWhere('email', 'ilike', "%$s%")))
            ->orderBy('nama');

        return Api::paginate($request, $q, fn (Akun $a) => Present::akun($a));
    }

    /** Nonaktifkan/aktifkan akun; peran tidak dapat diubah lewat API (hanya command operator). */
    public function updateAccount(Request $request, string $id): JsonResponse
    {
        $akun = Akun::findOrFail($id);
        $data = $request->validate([
            'status' => ['required', 'in:aktif,nonaktif'],
            'alasan' => ['required', 'string', 'max:300'],
        ]);
        if ($akun->id === $request->user()->id) {
            throw new DomainConflict('SELF_STATUS_CHANGE', 'Tidak dapat mengubah status akun sendiri.', 422);
        }
        if ($akun->status === 'menunggu') {
            throw new DomainConflict('PENDING_ACCOUNT', 'Akun menunggu diproses melalui permohonan.', 422);
        }
        DB::transaction(function () use ($request, $akun, $data) {
            $akun = Akun::whereKey($akun->id)->lockForUpdate()->firstOrFail();
            $akun->forceFill(['status' => $data['status']])->save();
            // Nonaktif: cabut bearer dan sesi browser. Perubahan status apa pun mengakhiri sesi CMS lama.
            if ($data['status'] === 'nonaktif') {
                $akun->revokeAllAccess();
            } else {
                $akun->endBrowserSessions();
            }
            Audit::record($request->user(), 'akun.status', 'akun', $akun->id, ['status' => $data['status'], 'alasan' => $data['alasan']]);
        });

        return Api::data(Present::akun($akun->refresh()));
    }

    public function requests(Request $request): JsonResponse
    {
        $q = PermohonanAkun::with(['akun', 'rumah'])
            ->where('jenis', 'tambahan')
            ->where('status', $request->query('status', 'diajukan'))
            ->orderBy('created_at');

        return Api::paginate($request, $q, fn (PermohonanAkun $p) => Present::permohonan($p));
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['rumah_id' => ['nullable', 'uuid'], 'catatan' => ['nullable', 'string', 'max:300']]);
        Registration::approve(PermohonanAkun::findOrFail($id), $request->user(), $data['rumah_id'] ?? null, $data['catatan'] ?? null);

        return Api::data(Present::permohonan(PermohonanAkun::with(['akun', 'rumah'])->findOrFail($id)));
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['alasan' => ['required', 'string', 'max:300']]);
        Registration::reject(PermohonanAkun::findOrFail($id), $request->user(), $data['alasan']);

        return Api::data(Present::permohonan(PermohonanAkun::with(['akun', 'rumah'])->findOrFail($id)));
    }

    /** GET /admin/registration-token — lihat/salin token aktif (no-store). */
    public function token(Request $request): JsonResponse
    {
        $token = RegistrationToken::ensureActive($request->user());
        Audit::record($request->user(), 'token_registrasi.dilihat', 'token_registrasi', $token->id);

        return Api::data([
            'kode' => RegistrationToken::reveal($token),
            'berlaku_sampai' => Present::ts($token->berlaku_sampai),
            'dibuat_at' => Present::ts($token->created_at),
        ])->header('Cache-Control', 'no-store');
    }

    public function rotateToken(Request $request): JsonResponse
    {
        RegistrationToken::rotate($request->user(), 'manual');

        return $this->token($request);
    }
}
