<?php

namespace App\Console\Commands;

use App\Support\Outbox;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Smoke sintetis M04 di staging (Neon staging → Redis → worker), CLI saja:
 *
 *   prepare  catat SATU event rt05.smoke tanpa penerima (tanpa akun, inbox, atau keuangan);
 *            cetak UUID event saja.
 *   verify   tunggu (terbatas) event rt05.smoke itu diselesaikan consumer; cetak state dan
 *            hitungan aman. Tidak mencetak payload, DSN, atau pesan exception.
 *
 * Penjaga dievaluasi SEBELUM akses database: APP_ENV harus persis "staging" dan flag
 * --confirm-staging wajib. Event smoke tetap tersimpan sebagai penanda sintetis di outbox
 * staging (completed, tanpa efek samping). rt05:async-smoke tetap khusus database *_test.
 */
class StagingSmoke extends Command
{
    public const MARKER = 'staging-smoke';

    private const MAX_TIMEOUT = 600;

    protected $signature = 'rt05:staging-smoke
        {--phase= : prepare|verify}
        {--confirm-staging : Wajib; menegaskan target adalah staging}
        {--event= : verify: UUID dari prepare}
        {--timeout=120 : verify: detik menunggu completed (maks 600)}';

    protected $description = 'Smoke sintetis outbox → Redis → worker pada staging (tanpa data warga)';

    public function handle(): int
    {
        if ($this->laravel->environment() !== 'staging') {
            $this->error('Ditolak: rt05:staging-smoke hanya untuk APP_ENV=staging.');

            return self::FAILURE;
        }
        if (! $this->option('confirm-staging')) {
            $this->error('Ditolak: tambahkan --confirm-staging.');

            return self::FAILURE;
        }

        $phase = $this->option('phase');
        if (! in_array($phase, ['prepare', 'verify'], true)) {
            return $this->invalid('Pilih --phase=prepare|verify.');
        }
        if ($phase === 'verify' && ! Str::isUuid((string) $this->option('event'))) {
            return $this->invalid('--event harus UUID dari --phase=prepare.');
        }
        $timeout = $this->option('timeout');
        if (! is_numeric($timeout) || (int) $timeout < 0 || (int) $timeout > self::MAX_TIMEOUT) {
            return $this->invalid('--timeout harus 0–'.self::MAX_TIMEOUT.' detik.');
        }

        try {
            return $phase === 'prepare' ? $this->prepare() : $this->verify((string) $this->option('event'), (int) $timeout);
        } catch (Throwable $e) {
            // Hanya kelas: pesan exception database dapat memuat host/DSN.
            $this->error('Smoke gagal: '.class_basename($e));

            return self::FAILURE;
        }
    }

    private function prepare(): int
    {
        $id = DB::transaction(fn () => Outbox::record('rt05.smoke', 'smoke', null, ['penerima' => [], 'penanda' => self::MARKER])->id);
        $this->line($id);

        return self::SUCCESS;
    }

    private function verify(string $id, int $timeout): int
    {
        $deadline = microtime(true) + $timeout;
        do {
            $event = DB::table('outbox_events')->where('id', $id)
                ->first(['type', 'attempts', 'published_at', 'completed_at', 'failed_at', 'last_error_code']);
            if (! $event || $event->type !== 'rt05.smoke') {
                $this->error('Event smoke tidak ditemukan.');

                return self::FAILURE;
            }
            if ($event->completed_at || $event->failed_at || microtime(true) >= $deadline) {
                break;
            }
            usleep(1_000_000);
        } while (true);

        $state = $event->completed_at ? 'completed' : ($event->failed_at ? 'failed' : ($event->published_at ? 'published' : 'pending'));
        $inbox = DB::table('notifikasi')->where('event_id', $id)->count();
        $this->line("state=$state attempts={$event->attempts} inbox=$inbox error_code=".($event->last_error_code ? 'present' : 'none'));

        if ($state !== 'completed' || $inbox !== 0) {
            $this->error('Smoke staging belum lulus.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function invalid(string $message): int
    {
        $this->error($message);

        return self::INVALID;
    }
}
