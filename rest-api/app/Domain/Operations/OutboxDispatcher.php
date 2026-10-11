<?php

namespace App\Domain\Operations;

use App\Jobs\ProcessOutboxEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * Dispatcher transactional outbox (docs/ASYNC_JOBS.md §Transactional outbox).
 *
 * 1. Klaim event dengan lease singkat (FOR UPDATE SKIP LOCKED) agar beberapa dispatcher aman.
 * 2. Kirim ID event ke antrean Redis; published_at diisi HANYA setelah enqueue berhasil.
 * 3. completed_at diisi consumer (ProcessOutboxEvent), tidak pernah oleh dispatcher.
 *
 * Replay: lease kedaluwarsa (dispatcher mati sebelum enqueue) dan published tanpa completed
 * lebih lama dari stale_seconds (job hilang dari Redis) diklaim ulang. Duplikat job aman karena
 * consumer idempotent. Enqueue gagal → event tetap pending dengan kode error aman lalu backoff.
 * Semua pembanding waktu memakai jam database.
 */
final class OutboxDispatcher
{
    /** @return array{claimed:int, enqueued:int, failed:int} */
    public static function dispatch(?int $limit = null): array
    {
        $ids = self::claim($limit ?? (int) config('rt05.outbox.batch', 100));
        $enqueued = 0;

        foreach ($ids as $id) {
            try {
                Queue::connection(config('rt05.outbox.queue_connection'))
                    ->pushOn(config('rt05.outbox.queue', 'notifications'), new ProcessOutboxEvent($id));
            } catch (Throwable $e) {
                self::enqueueFailed($id, $e);

                continue;
            }

            DB::table('outbox_events')->where('id', $id)->update([
                'published_at' => DB::raw('now()'),
                'dispatch_lease_until' => null,
                'last_error_code' => null,
            ]);
            $enqueued++;
        }

        return ['claimed' => count($ids), 'enqueued' => $enqueued, 'failed' => count($ids) - $enqueued];
    }

    /** @return list<string> */
    public static function claim(int $limit): array
    {
        $lease = max(10, (int) config('rt05.outbox.lease_seconds', 120));
        $stale = max(1, (int) config('rt05.outbox.stale_seconds', 900));

        return DB::transaction(function () use ($limit, $lease, $stale) {
            $ids = DB::table('outbox_events')
                ->whereNull('completed_at')
                ->whereNull('failed_at')
                ->whereRaw('available_at <= now()')
                ->where(fn ($q) => $q->whereNull('dispatch_lease_until')->orWhereRaw('dispatch_lease_until < now()'))
                ->where(fn ($q) => $q->whereNull('published_at')->orWhereRaw('published_at < now() - make_interval(secs => ?)', [$stale]))
                ->orderBy('created_at')
                ->limit(max(1, $limit))
                ->lock('for update skip locked')
                ->pluck('id')
                ->all();

            if ($ids !== []) {
                DB::table('outbox_events')->whereIn('id', $ids)->update([
                    'dispatch_lease_until' => DB::raw("now() + make_interval(secs => $lease)"),
                    'attempts' => DB::raw('attempts + 1'),
                ]);
            }

            return $ids;
        });
    }

    /** Antrean tidak tersedia: event tetap pending (published_at tidak berubah) dan dicoba lagi. */
    private static function enqueueFailed(string $id, Throwable $e): void
    {
        // Hanya nama kelas: pesan exception Redis dapat memuat host/DSN.
        $code = substr('enqueue_failed:'.class_basename($e), 0, 100);
        // Event yang sudah diparkir consumer (antrean sync) tidak ditimpa.
        DB::table('outbox_events')->where('id', $id)->whereNull('failed_at')->update([
            'last_error_code' => $code,
            'dispatch_lease_until' => null,
            'available_at' => DB::raw('now() + make_interval(mins => least(15, power(2, least(attempts, 4))::int))'),
        ]);
        Log::warning('outbox.enqueue_failed', ['event_id' => $id, 'exception' => $e::class]);
    }
}
