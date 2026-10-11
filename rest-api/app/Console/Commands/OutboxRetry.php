<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ulang manual satu event outbox yang diparkir (failed_at) atau tertahan backoff, berdasarkan
 * ID event (docs/ASYNC_JOBS.md: jangan replay seluruh antrean tanpa melihat dampak). Event
 * dikirim ulang oleh rt05:outbox-dispatch berikutnya; consumer tetap idempotent.
 */
class OutboxRetry extends Command
{
    protected $signature = 'rt05:outbox-retry {event : UUID outbox_events.id}';

    protected $description = 'Jadwalkan ulang satu event outbox yang belum selesai';

    public function handle(): int
    {
        $id = (string) $this->argument('event');
        if (! Str::isUuid($id)) {
            $this->error('ID event harus UUID.');

            return self::INVALID;
        }

        $event = DB::table('outbox_events')->where('id', $id)->first(['completed_at']);
        if (! $event) {
            $this->error('Event tidak ditemukan.');

            return self::FAILURE;
        }
        if ($event->completed_at) {
            $this->warn('Event sudah selesai; tidak diubah.');

            return self::SUCCESS;
        }

        DB::table('outbox_events')->where('id', $id)->whereNull('completed_at')->update([
            'failed_at' => null,
            'published_at' => null,
            'dispatch_lease_until' => null,
            'available_at' => DB::raw('now()'),
        ]);
        $this->info('Event dijadwalkan ulang; diproses pada rt05:outbox-dispatch berikutnya.');

        return self::SUCCESS;
    }
}
