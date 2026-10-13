<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Identity\Registration;
use App\Domain\Identity\RegistrationToken;
use App\Domain\Residents\Accounts;
use App\Domain\Residents\Address;
use App\Domain\Residents\Households;
use App\Domain\Residents\Links;
use App\Domain\Residents\Occupancies;
use App\Exceptions\DomainConflict;
use App\Http\Controllers\Controller;
use App\Http\Responses\Api;
use App\Http\Responses\Present;
use App\Models\Akun;
use App\Models\Keluarga;
use App\Models\PemilikRumah;
use App\Models\Penghunian;
use App\Models\PermohonanAkun;
use App\Models\Rumah;
use App\Models\Warga;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pendataan oleh pengurus (FR06, FR22, M07): rumah, keluarga, anggota, hunian, akun, permohonan,
 * token registrasi. Semua pengurus setara (BR07). Transaksi ada di App\Domain\Residents; controller
 * hanya memvalidasi allowlist field (tidak pernah peran) dan menyajikan hasil.
 */
class ResidentsController extends Controller
{
    private const WHATSAPP = ['nullable', 'string', 'max:25', 'regex:/^[0-9+ -]{8,25}$/'];

    // ---- Rumah ----

    public function houses(Request $request): JsonResponse
    {
        $q = Rumah::query()->with(['penghunianAktif.keluarga.keanggotaanAktif.warga'])
            ->when(! $request->boolean('arsip'), fn ($q) => $q->whereNull('archived_at'))
            ->when($request->query('q'), fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('kode_rumah', 'ilike', "%$s%")->orWhere('jalan', 'ilike', "%$s%")->orWhere('nomor', 'ilike', "%$s%")))
            ->orderBy('kode_rumah');

        return Api::paginate($request, $q, function (Rumah $r) {
            $k = $r->penghunianAktif?->keluarga;
            $anggota = $k?->keanggotaanAktif->map->warga->filter();

            return Present::rumah($r) + [
                'keluarga' => $k ? ['id' => $k->id, 'status' => $k->status, 'penghunian_id' => $r->penghunianAktif->id,
                    'kepala' => $anggota->firstWhere('is_kepala', true)?->nama,
                    'jumlah_anggota' => $anggota->count()] : null,
            ];
        });
    }

    public function showHouse(string $id): JsonResponse
    {
        $r = Rumah::with(['penghunianAktif.keluarga', 'penghunian', 'pemilik'])->findOrFail($id);
        $k = $r->penghunianAktif?->keluarga?->load(Present::KELUARGA);

        return Api::data(Present::rumah($r) + [
            // Pemilik dicatat pengurus, terpisah dari keluarga penghuni/kepala; hanya untuk pengurus.
            'pemilik' => $r->pemilik ? ['id' => $r->pemilik->id, 'nama' => $r->pemilik->nama, 'whatsapp' => $r->pemilik->whatsapp, 'catatan' => $r->pemilik->catatan] : null,
            'penghunian_aktif_id' => $r->penghunianAktif?->id,
            'keluarga' => $k ? Present::keluarga($k, null) : null,
            'riwayat_penghunian' => $r->penghunian->sortByDesc('mulai_tanggal')->values()->map(fn (Penghunian $p) => [
                'id' => $p->id, 'keluarga_id' => $p->keluarga_id, 'jenis_hunian' => $p->jenis_hunian,
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
        $rumah = Links::transaction(function () use ($request, $data, $key) {
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

        return Api::data(Present::rumah($rumah->refresh()), 201);
    }

    /**
     * Koreksi alamat dan pengaturan tagihan rumah dengan alasan dan audit (BR v1: tidak auto-bebas).
     * kode_rumah stabil untuk transparansi, tidak diubah. versi opsional → 409 bila basi.
     */
    public function updateHouse(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'blok' => ['sometimes', 'nullable', 'string', 'max:30'],
            'jalan' => ['sometimes', 'string', 'max:150'],
            'nomor' => ['sometimes', 'string', 'max:20'],
            'tagihan_aktif' => ['sometimes', 'boolean'],
            'mulai_tagih' => ['sometimes', 'nullable', 'date_format:Y-m'],
            'pemilik' => ['sometimes', 'nullable', 'array'],
            'pemilik.nama' => ['required_with:pemilik', 'string', 'max:120'],
            'pemilik.whatsapp' => self::WHATSAPP,
            'pemilik.catatan' => ['nullable', 'string', 'max:300'],
            'alasan' => ['required', 'string', 'max:300'],
            'versi' => ['required', 'integer'],
        ]);
        $rumah = Links::transaction(function () use ($request, $id, $data) {
            $rumah = Rumah::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($rumah->archived_at) {
                throw new DomainConflict('HOUSE_ARCHIVED', 'Rumah sudah diarsipkan.');
            }
            if ($rumah->versi !== (int) $data['versi']) {
                throw new DomainConflict('VERSION_CONFLICT', 'Data rumah sudah berubah. Muat ulang lalu coba lagi.');
            }
            $before = ['tagihan_aktif' => $rumah->tagihan_aktif, 'mulai_tagih' => $rumah->mulai_tagih?->format('Y-m'), 'alamat_key' => $rumah->alamat_key];
            foreach (['blok', 'jalan', 'nomor', 'tagihan_aktif'] as $f) {
                if (array_key_exists($f, $data)) {
                    $rumah->{$f} = $data[$f];
                }
            }
            if (array_key_exists('mulai_tagih', $data)) {
                $rumah->mulai_tagih = $data['mulai_tagih'] ? $data['mulai_tagih'].'-01' : null;
            }
            if (array_key_exists('pemilik', $data)) {
                $rumah->pemilik_id = $data['pemilik'] === null ? null : $this->owner($rumah, $data['pemilik']);
            }
            $rumah->alamat_key = Address::key($rumah->blok, $rumah->jalan, $rumah->nomor);
            if ($rumah->alamat_key !== $before['alamat_key'] && Rumah::where('alamat_key', $rumah->alamat_key)->whereKeyNot($rumah->id)->exists()) {
                throw new DomainConflict('HOUSE_EXISTS', 'Rumah dengan alamat ini sudah ada.');
            }
            $rumah->versi++;
            $rumah->save();
            Audit::record($request->user(), 'rumah.diubah', 'rumah', $rumah->id, [
                'field' => array_keys(array_diff_key($data, ['alasan' => 1, 'versi' => 1])), 'sebelum' => $before, 'alasan' => $data['alasan'],
            ]);

            return $rumah;
        });

        return Api::data(Present::rumah($rumah->refresh()));
    }

    /** Pemilik rumah (bukan otomatis penghuni): perbarui catatan pemilik rumah ini atau buat baru. */
    private function owner(Rumah $rumah, array $data): string
    {
        $fields = ['nama' => trim($data['nama']), 'whatsapp' => $data['whatsapp'] ?? null, 'catatan' => $data['catatan'] ?? null];
        $owner = $rumah->pemilik_id ? PemilikRumah::whereKey($rumah->pemilik_id)->lockForUpdate()->first() : null;
        if ($owner) {
            $owner->fill($fields)->save();

            return $owner->id;
        }

        return PemilikRumah::create($fields)->id;
    }

    /** Arsip rumah: hunian aktif diakhiri lewat layanan hunian; tagihan/pembayaran/kas tidak dihapus. */
    public function archiveHouse(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['alasan' => ['required', 'string', 'max:300']]);
        $actor = $request->user();
        Links::transaction(function () use ($actor, $id, $data) {
            Links::lockHouses([$id]);
            $rumah = Rumah::findOrFail($id);
            if ($rumah->archived_at) {
                throw new DomainConflict('ALREADY_ARCHIVED', 'Rumah sudah diarsipkan.');
            }
            if ($aktif = $rumah->penghunianAktif) {
                Occupancies::end($actor, $aktif->id, null, 'Rumah diarsipkan: '.$data['alasan']);
            }
            $rumah->forceFill(['archived_at' => now(), 'tagihan_aktif' => false, 'versi' => $rumah->versi + 1])->save();
            Audit::record($actor, 'rumah.diarsipkan', 'rumah', $rumah->id, ['alasan' => $data['alasan'], 'hunian_diakhiri' => (bool) $aktif]);
        });

        return Api::noContent();
    }

    // ---- Keluarga ----

    public function families(Request $request): JsonResponse
    {
        $request->validate(['status' => ['sometimes', 'in:belum_verifikasi,terverifikasi']]);
        $q = Keluarga::query()->with(['keanggotaanAktif.warga', 'penghunianAktif.rumah'])
            ->when(! $request->boolean('arsip'), fn ($q) => $q->whereNull('archived_at'))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('q'), fn ($q, $s) => $q->whereHas('keanggotaanAktif.warga', fn ($w) => $w->where('nama', 'ilike', "%$s%")))
            ->orderByDesc('created_at')->orderBy('id');

        return Api::paginate($request, $q, fn (Keluarga $k) => Present::keluargaRingkas($k));
    }

    public function storeFamily(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nama_kepala' => ['required', 'string', 'max:120'],
            'hubungan_kepala' => ['sometimes', 'nullable', 'string', 'max:40'],
            'whatsapp' => self::WHATSAPP,
            'anggota' => ['sometimes', 'array', 'max:20'],
            'anggota.*.nama' => ['required', 'string', 'max:120'],
            'anggota.*.hubungan_keluarga' => ['required', 'string', 'max:40'],
            'anggota.*.whatsapp' => self::WHATSAPP,
            'rumah_id' => ['sometimes', 'nullable', 'uuid'],
            'jenis_hunian' => ['required_with:rumah_id', 'nullable', 'in:pemilik,kontrak'],
            'mulai_tanggal' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);
        $k = Households::create($request->user(), $data);

        return Api::data(Present::keluargaDetail($k->load(Present::KELUARGA_DETAIL)), 201);
    }

    public function showFamily(string $id): JsonResponse
    {
        return Api::data(Present::keluargaDetail(Keluarga::with(Present::KELUARGA_DETAIL)->findOrFail($id)));
    }

    /** PATCH /admin/families/{id}: ganti kepala keluarga (versi wajib). */
    public function updateFamily(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'versi' => ['required', 'integer'],
            'kepala_warga_id' => ['required', 'uuid'],
        ]);
        Households::changeHead($request->user(), $id, $data['kepala_warga_id'], $data['versi']);

        return $this->showFamily($id);
    }

    public function verifyFamily(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['versi' => ['required', 'integer']]);
        $k = Households::verify($request->user(), $id, $data['versi']);

        return Api::data(['id' => $k->id, 'status' => $k->status, 'versi' => $k->versi]);
    }

    public function archiveFamily(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['alasan' => ['required', 'string', 'max:300']]);
        Households::archive($request->user(), $id, $data['alasan']);

        return Api::noContent();
    }

    public function addFamilyMember(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'nama' => ['required', 'string', 'max:120'],
            'hubungan_keluarga' => ['required', 'string', 'max:40'],
            'whatsapp' => self::WHATSAPP,
            'versi' => ['required', 'integer'],
        ]);
        $warga = Households::addMember($request->user(), $id, $data, $data['versi'], 'pengurus');

        return Api::data(Present::wargaDetail($warga->load(Present::WARGA_DETAIL)), 201);
    }

    public function transferPrimary(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'akun_id' => ['required', 'uuid'],
            'versi' => ['required', 'integer'],
            'alasan' => ['required', 'string', 'max:300'],
        ]);
        Households::transferPrimary($request->user(), $id, $data['akun_id'], $data['versi'], $data['alasan']);

        return $this->showFamily($id);
    }

    // ---- Anggota ----

    public function showResident(string $id): JsonResponse
    {
        return Api::data(Present::wargaDetail(Warga::with(Present::WARGA_DETAIL)->findOrFail($id)));
    }

    public function updateResident(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'nama' => ['sometimes', 'string', 'max:120'],
            'hubungan_keluarga' => ['sometimes', 'string', 'max:40'],
            'whatsapp' => self::WHATSAPP,
            'versi' => ['required', 'integer'],
        ]);
        $versi = $data['versi'];
        unset($data['versi']);
        Households::updateMember($request->user(), Households::familyOf($id), $id, $data, $versi, true);

        return $this->showResident($id);
    }

    public function endResident(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:pindah,meninggal'],
            'alasan' => ['required', 'string', 'max:300'],
            'versi' => ['required', 'integer'],
        ]);
        Households::endMember($request->user(), Households::familyOf($id), $id, $data['status'], $data['versi'], true, $data['alasan']);

        return $this->showResident($id);
    }

    public function moveResident(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'keluarga_id' => ['required', 'uuid'],
            'hubungan_keluarga' => ['required', 'string', 'max:40'],
            'alasan' => ['required', 'string', 'max:300'],
            'versi' => ['required', 'integer'],
        ]);
        Households::moveMember($request->user(), $id, $data['keluarga_id'], $data['hubungan_keluarga'], $data['alasan'], $data['versi']);

        return $this->showResident($id);
    }

    // ---- Hunian ----

    public function storeOccupancy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'rumah_id' => ['required', 'uuid'],
            'keluarga_id' => ['required', 'uuid'],
            'jenis_hunian' => ['required', 'in:pemilik,kontrak'],
            'mulai_tanggal' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);
        $p = Occupancies::begin($request->user(), $data['rumah_id'], $data['keluarga_id'], $data['jenis_hunian'], $data['mulai_tanggal'] ?? null);

        return Api::data(Present::penghunian($p->load('rumah')), 201);
    }

    public function endOccupancy(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'selesai_tanggal' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'alasan' => ['required', 'string', 'max:300'],
        ]);
        $p = Occupancies::end($request->user(), $id, $data['selesai_tanggal'] ?? null, $data['alasan']);

        return Api::data(Present::penghunian($p->load('rumah')));
    }

    public function moveOccupancy(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'rumah_id' => ['required', 'uuid'],
            'jenis_hunian' => ['sometimes', 'nullable', 'in:pemilik,kontrak'],
            'tanggal' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'alasan' => ['required', 'string', 'max:300'],
        ]);
        $p = Occupancies::move($request->user(), $id, $data['rumah_id'], $data['jenis_hunian'] ?? null, $data['tanggal'] ?? null, $data['alasan']);

        return Api::data(Present::penghunian($p->load('rumah')), 201);
    }

    // ---- Akun ----

    public function accounts(Request $request): JsonResponse
    {
        $q = Akun::query()->with(Akun::PROFIL)
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('q'), fn ($q, $s) => $q->where(fn ($w) => $w->where('nama', 'ilike', "%$s%")->orWhere('email', 'ilike', "%$s%")))
            ->orderBy('nama');

        return Api::paginate($request, $q, fn (Akun $a) => Present::akun($a));
    }

    public function showAccount(string $id): JsonResponse
    {
        return Api::data(Present::akunDetail(Akun::with(Present::AKUN_DETAIL)->findOrFail($id)));
    }

    /** Nonaktifkan/aktifkan akun; peran tidak dapat diubah lewat API (hanya command operator). */
    public function updateAccount(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:aktif,nonaktif'],
            'alasan' => ['required', 'string', 'max:300'],
        ]);
        $akun = Accounts::setStatus($request->user(), $id, $data['status'], $data['alasan']);

        return Api::data(Present::akun($akun->refresh()->load(Akun::PROFIL)));
    }

    public function archiveAccount(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['alasan' => ['required', 'string', 'max:300']]);
        Accounts::archive($request->user(), $id, $data['alasan']);

        return $this->showAccount($id);
    }

    // ---- Permohonan akun tambahan ----

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
        $data = $request->validate([
            'rumah_id' => ['nullable', 'uuid'],
            'catatan' => ['nullable', 'string', 'max:300'],
            'warga_id' => ['sometimes', 'nullable', 'uuid'],
        ]);
        Registration::approve(PermohonanAkun::findOrFail($id), $request->user(), $data['rumah_id'] ?? null, $data['catatan'] ?? null, $data['warga_id'] ?? null);

        return Api::data(Present::permohonan(PermohonanAkun::with(['akun', 'rumah'])->findOrFail($id)));
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['alasan' => ['required', 'string', 'max:300']]);
        Registration::reject(PermohonanAkun::findOrFail($id), $request->user(), $data['alasan']);

        return Api::data(Present::permohonan(PermohonanAkun::with(['akun', 'rumah'])->findOrFail($id)));
    }

    // ---- Token registrasi ----

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
