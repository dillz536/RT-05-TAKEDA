<?php

namespace App\Jobs;

use App\Domain\Community\Notifications;
use App\Exceptions\OutboxConsumerFailed;
use App\Exceptions\UnknownOutboxEvent;
use App\Models\OutboxEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\FailOnException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Consumer outbox (worker-default, antrean notifications). Payload hanya ID event; data dimuat
 * ulang dari Postgres. Event dikunci FOR UPDATE, lalu inbox dan completed_at ditulis dalam satu
 * transaksi: job ganda (replay/duplikat Redis) berhenti karena event sudah completed, dan inbox
 * tetap unik lewat UNIQUE(event_id, penerima_id).
 */
class ProcessOutboxEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    // Timeout menghentikan proses worker; transaksi Postgres batal dan job dicoba ulang setelah
    // retry_after (180 detik) sampai $tries habis.
    public int $timeout = 60;

    public function __construct(public string $eventId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 30, 120, 300];
    }

    /** Jenis event tak dikenal tidak akan berhasil dengan retry: langsung gagal dan diparkir. */
    public function middleware(): array
    {
        return [new FailOnException([UnknownOutboxEvent::class])];
    }

    public function handle(): void
    {
        try {
            $this->consume();
        } catch (UnknownOutboxEvent $e) {
            throw $e;
        } catch (Throwable $e) {
            // Pesan QueryException memuat SQL + binding (bisa ID pelapor anonim); jangan sampai ke
            // failed_jobs atau log. Transaksi sudah dibatalkan; job dicoba ulang oleh worker.
            throw OutboxConsumerFailed::from($e);
        }
    }

    private function consume(): void
    {
        DB::transaction(function () {
            $event = OutboxEvent::whereKey($this->eventId)->lockForUpdate()->first();
            if (! $event || $event->completed_at || $event->failed_at) {
                return;
            }

            Notifications::handle($event);

            DB::table('outbox_events')->where('id', $event->id)->update([
                'completed_at' => DB::raw('greatest(now(), created_at)'),
                'dispatch_lease_until' => null,
                'last_error_code' => null,
            ]);
        });
    }

    /** Semua percobaan habis: parkir event (tidak di-replay otomatis); ulang manual rt05:outbox-retry. */
    public function failed(?Throwable $e): void
    {
        $reason = match (true) {
            $e instanceof OutboxConsumerFailed => $e->getMessage(),
            $e !== null => class_basename($e),
            default => 'unknown',
        };
        $code = substr('consumer_failed:'.$reason, 0, 100);
        DB::table('outbox_events')->where('id', $this->eventId)->whereNull('completed_at')->update([
            'failed_at' => DB::raw('now()'),
            'last_error_code' => $code,
        ]);
        Log::error('outbox.consumer_failed', ['event_id' => $this->eventId, 'exception' => $e ? $e::class : null]);
    }
}
