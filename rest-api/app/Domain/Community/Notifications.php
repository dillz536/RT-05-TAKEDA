<?php

namespace App\Domain\Community;

use App\Exceptions\UnknownOutboxEvent;
use App\Models\Agenda;
use App\Models\Akun;
use App\Models\AlokasiPembayaran;
use App\Models\Aspirasi;
use App\Models\OutboxEvent;
use App\Models\Pengaduan;
use App\Models\Pengumuman;
use App\Models\PermohonanAkun;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Konsumen outbox → inbox notifikasi (FR20, BR21), dipanggil job ProcessOutboxEvent setelah
 * OutboxDispatcher mengirim ID event ke antrean. Idempotent: UNIQUE(event_id, penerima_id)
 * sehingga replay event tidak menggandakan notifikasi. Teks aman: tidak memuat identitas pelapor
 * anonim, data keluarga, atau nominal pribadi rumah lain. Push FCM menyusul (adapter M10).
 */
final class Notifications
{
    public static function handle(OutboxEvent $event): void
    {
        $id = $event->aggregate_id;
        match ($event->type) {
            'pengaduan.dibuat' => self::toPengurus($event, 'pengaduan', ['pengaduan_id' => $id], 'Pengaduan baru', 'Ada pengaduan baru yang perlu ditindaklanjuti.'),
            'pengaduan.status_berubah' => ($p = Pengaduan::find($id)) && self::send($event, [$p->pelapor_id], 'pengaduan', ['pengaduan_id' => $id],
                'Status pengaduan diperbarui', 'Pengaduan "'.Str::limit($p->judul, 60).'" kini berstatus '.self::label($p->status).'.'),
            'aspirasi.dibuat' => self::toPengurus($event, 'aspirasi', ['aspirasi_id' => $id], 'Aspirasi baru', 'Ada aspirasi baru dari warga.'),
            'aspirasi.status_berubah' => ($a = Aspirasi::find($id)) && self::send($event, [$a->pengirim_id], 'aspirasi', ['aspirasi_id' => $id],
                'Status aspirasi diperbarui', 'Aspirasi "'.Str::limit($a->judul, 60).'" kini berstatus '.self::label($a->status).'.'),
            'pengumuman.terbit' => ($p = Pengumuman::find($id)) && self::send($event, self::activeResidents(), 'pengumuman', ['pengumuman_id' => $id],
                Str::limit($p->judul, 150), Str::limit(strip_tags($p->isi), 180)),
            'agenda.dibuat', 'agenda.pengingat' => self::agenda($event),
            'akun.permohonan_tambahan' => self::toPengurus($event, 'sistem', [], 'Permohonan akun tambahan', 'Ada permohonan akun tambahan yang menunggu persetujuan.'),
            'akun.permohonan_disetujui' => self::send($event, [$id], 'sistem', [], 'Akun disetujui', 'Akun Anda telah disetujui pengurus. Selamat datang di RT05 TAKEDA.'),
            'iuran.pembayaran_dicatat' => self::paymentRecorded($event),
            // Event sintetis: rt05:async-smoke (penerima akun sintetis, database *_test) atau
            // rt05:staging-smoke (penerima kosong → tanpa inbox).
            'rt05.smoke' => self::send($event, $event->safe_payload['penerima'] ?? [], 'sistem', [], 'Uji antrean', 'Notifikasi sintetis uji worker.'),
            'iuran.pengingat' => self::send($event, $event->safe_payload['penerima'] ?? [], 'iuran', ['tagihan_id' => $event->safe_payload['tagihan_id'] ?? null],
                'Pengingat iuran', 'Iuran RT bulan ini belum tercatat. Abaikan bila sudah membayar kepada pengurus.'),
            // Dicatat untuk audit/integrasi berikutnya; v1 sengaja tanpa notifikasi inbox.
            'akun.registrasi_utama', 'iuran.tagihan_dibuat', 'iuran.pembayaran_dibalik' => null,
            default => throw new UnknownOutboxEvent($event->type),
        };
    }

    private static function agenda(OutboxEvent $event): bool
    {
        $agenda = Agenda::find($event->aggregate_id);
        if (! $agenda || $agenda->archived_at) {
            return false;
        }
        $when = $agenda->mulai_at->setTimezone('Asia/Jakarta')->translatedFormat('l, d F Y H:i');
        $title = $event->type === 'agenda.pengingat' ? 'Pengingat agenda besok' : 'Agenda baru';

        return self::send($event, self::activeResidents(), 'agenda', ['agenda_id' => $agenda->id], $title, Str::limit($agenda->nama, 80).' — '.$when.' WIB, '.Str::limit($agenda->lokasi, 60).'.');
    }

    private static function paymentRecorded(OutboxEvent $event): bool
    {
        $alokasi = AlokasiPembayaran::where('pembayaran_id', $event->aggregate_id)->first();
        $periods = implode(', ', $event->safe_payload['periode'] ?? []);

        return self::send($event, self::primaryAccountsOf($event->safe_payload['rumah_id'] ?? null), 'iuran', ['tagihan_id' => $alokasi?->tagihan_id],
            'Pembayaran iuran tercatat', 'Pembayaran iuran periode '.$periods.' telah dicatat pengurus.');
    }

    private static function toPengurus(OutboxEvent $event, string $jenis, array $context, string $title, string $body): bool
    {
        return self::send($event, Akun::where('peran', 'pengurus')->where('status', 'aktif')->pluck('id')->all(), $jenis, $context, $title, $body);
    }

    /** @param iterable<string> $recipients */
    public static function send(OutboxEvent $event, iterable $recipients, string $jenis, array $context, string $title, string $body): bool
    {
        $rows = collect($recipients)->filter()->unique()->map(fn ($akunId) => array_merge([
            'id' => (string) Str::uuid(),
            'event_id' => $event->id,
            'penerima_id' => $akunId,
            'jenis' => $jenis,
            'judul' => $title,
            'isi' => $body,
        ], $context))->values();
        foreach ($rows->chunk(500) as $chunk) {
            DB::table('notifikasi')->insertOrIgnore($chunk->all());
        }

        return true;
    }

    public static function activeResidents(): Collection
    {
        return Akun::where('status', 'aktif')->pluck('id');
    }

    /**
     * Akun utama aktif pada keluarga yang menghuni rumah (penerima pengingat iuran, BR v1).
     * M07: dari tautan_akun aktif is_utama, bukan pointer akun.warga_id/jenis.
     */
    public static function primaryAccountsOf(?string $rumahId): Collection
    {
        if (! $rumahId) {
            return collect();
        }

        return DB::table('tautan_akun')
            ->join('akun', 'akun.id', '=', 'tautan_akun.akun_id')
            ->join('penghunian', 'penghunian.keluarga_id', '=', 'tautan_akun.keluarga_id')
            ->where('penghunian.rumah_id', $rumahId)
            ->whereNull('penghunian.selesai_tanggal')
            ->whereNull('tautan_akun.selesai_at')
            ->where('tautan_akun.is_utama', true)
            ->where('akun.status', 'aktif')
            ->pluck('akun.id');
    }

    public static function label(string $status): string
    {
        return ['diajukan' => 'Diajukan', 'diproses' => 'Diproses', 'ditinjau' => 'Ditinjau', 'selesai' => 'Selesai'][$status] ?? $status;
    }

    public static function pendingRequests(): int
    {
        return PermohonanAkun::where('status', 'diajukan')->count();
    }
}
