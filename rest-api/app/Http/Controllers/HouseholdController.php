<?php

namespace App\Http\Controllers;

use App\Domain\Residents\Households;
use App\Exceptions\DomainConflict;
use App\Http\Responses\Api;
use App\Http\Responses\Present;
use App\Models\Akun;
use App\Models\Keluarga;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Data keluarga sendiri (BR05, ADR10, M07). Keluarga ditentukan dari tautan akun aktif:
 * akun utama membaca dan mengubah; akun tambahan yang disetujui hanya membaca (403 untuk
 * mutasi); akun tanpa tautan aktif 404. Anggota keluarga lain selalu 404. Setiap mutasi wajib
 * mengirim keluarga.versi (hilang 422, basi 409); respons anggota memuat versi_keluarga terbaru.
 * Pemeriksaan di controller hanya jalur cepat dari tautan yang dimuat sebelum lock; keputusan akhir
 * ada di domain (Links::assertEditor) setelah keluarga dikunci, sehingga akun yang kehilangan status
 * utama/tautan/aktif secara bersamaan tidak dapat menulis.
 */
class HouseholdController extends Controller
{
    private const WHATSAPP = ['nullable', 'string', 'max:25', 'regex:/^[0-9+ -]{8,25}$/'];

    private function keluargaOf(Akun $akun): Keluarga
    {
        $keluarga = $akun->keluarga();
        if (! $keluarga) {
            throw new DomainConflict('HOUSEHOLD_NOT_LINKED', 'Akun belum terhubung dengan keluarga.', 404);
        }

        return $keluarga;
    }

    private function canEdit(Akun $akun): bool
    {
        return $akun->isUtama() || $akun->isPengurus();
    }

    private function editableKeluarga(Akun $akun): Keluarga
    {
        $keluarga = $this->keluargaOf($akun);
        if (! $this->canEdit($akun)) {
            throw new DomainConflict('PRIMARY_ACCOUNT_REQUIRED', 'Hanya akun utama yang dapat mengubah data keluarga.', 403);
        }

        return $keluarga;
    }

    private function versi(Keluarga $k): int
    {
        return (int) Keluarga::whereKey($k->id)->value('versi');
    }

    public function show(Request $request): JsonResponse
    {
        $akun = $request->user();
        $k = $this->keluargaOf($akun)->refresh()->load(Present::KELUARGA);

        return Api::data(Present::keluarga($k, $k->penghunianAktif?->rumah, $this->canEdit($akun)));
    }

    /** PATCH /household — ubah WhatsApp/nama kepala keluarga dengan optimistic version. */
    public function update(Request $request): JsonResponse
    {
        $akun = $request->user();
        $k = $this->editableKeluarga($akun);
        $data = $request->validate([
            'versi' => ['required', 'integer'],
            'whatsapp' => self::WHATSAPP,
            'nama_kepala' => ['sometimes', 'string', 'max:120'],
        ]);
        Households::updateHead($akun, $k->id, $data);

        return $this->show($request);
    }

    public function addMember(Request $request): JsonResponse
    {
        $akun = $request->user();
        $k = $this->editableKeluarga($akun);
        $data = $request->validate([
            'nama' => ['required', 'string', 'max:120'],
            'hubungan_keluarga' => ['required', 'string', 'max:40'],
            'whatsapp' => self::WHATSAPP,
            'versi' => ['required', 'integer'],
        ]);
        $warga = Households::addMember($akun, $k->id, $data, $data['versi'], 'akun_utama');

        return Api::data(Present::warga($warga) + ['versi_keluarga' => $this->versi($k)], 201);
    }

    public function updateMember(Request $request, string $id): JsonResponse
    {
        $akun = $request->user();
        $k = $this->editableKeluarga($akun);
        $data = $request->validate([
            'nama' => ['sometimes', 'string', 'max:120'],
            'hubungan_keluarga' => ['sometimes', 'string', 'max:40'],
            'whatsapp' => self::WHATSAPP,
            'versi' => ['required', 'integer'],
        ]);
        $versi = $data['versi'];
        unset($data['versi']);
        $warga = Households::updateMember($akun, $k->id, $id, $data, $versi);

        return Api::data(Present::warga($warga->refresh()) + ['versi_keluarga' => $this->versi($k)]);
    }

    /** DELETE /household/members/{id} — akhiri keanggotaan (arsip), bukan hapus permanen (BR18). */
    public function endMember(Request $request, string $id): JsonResponse
    {
        $akun = $request->user();
        $k = $this->editableKeluarga($akun);
        $data = $request->validate([
            'status' => ['sometimes', 'in:pindah,meninggal'],
            'versi' => ['required', 'integer'],
        ]);
        Households::endMember($akun, $k->id, $id, $data['status'] ?? 'pindah', $data['versi'], false);

        return Api::noContent();
    }
}
