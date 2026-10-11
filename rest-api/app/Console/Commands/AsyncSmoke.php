<?php

namespace App\Console\Commands;

use App\Models\Akun;
use App\Support\Outbox;
use Illuminate\Console\Command;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Smoke sintetis jalur async M03 untuk CI/staging (docs/ASYNC_JOBS.md §Bukti penerimaan):
 *
 *   prepare  catat event rt05.smoke di outbox; cetak event_id saja.
 *            --with-inbox: buat satu akun sintetis nonaktif sebagai penerima (password acak,
 *            tidak dicetak).
 *   verify   tunggu event completed oleh worker lalu cek jumlah inbox per event.
 *   status   ringkasan outbox (pending, umur tertua, diparkir) dan failed_jobs.
 *
 * Skenario: prepare → rt05:outbox-dispatch → (kill worker / restart Redis AOF / hapus antrean
 * lalu tunggu stale) → rt05:outbox-dispatch → verify. Semua phase ditolak kecuali APP_ENV
 * non-production, database *_test pada host lokal/CI, dan Redis lokal/CI.
 */
class AsyncSmoke extends Command
{
    protected $signature = 'rt05:async-smoke
        {--phase= : prepare|verify|status}
        {--with-inbox : prepare: penerima satu akun sintetis nonaktif}
        {--event= : verify: UUID event dari prepare}
        {--timeout=60 : verify: detik menunggu completed}
        {--expect-inbox= : verify: jumlah inbox yang diharapkan untuk event}';

    protected $description = 'Smoke sintetis outbox → Redis → worker → inbox';

    public function handle(): int
    {
        if (app()->environment('production') || ! self::isLocalTestDatabase() || ! self::isLocalRedis()) {
            $this->error('Ditolak: async smoke hanya untuk APP_ENV non-production, database *_test, dan Redis lokal/CI.');

            return self::FAILURE;
        }

        try {
            return match ($this->option('phase')) {
                'prepare' => $this->prepare(),
                'verify' => $this->verify(),
                'status' => $this->status(),
                default => $this->invalid('Pilih --phase=prepare|verify|status.'),
            };
        } catch (Throwable $e) {
            $this->error('Smoke gagal: '.$e::class);

            return self::FAILURE;
        }
    }

    private function prepare(): int
    {
        $id = DB::transaction(function () {
            $penerima = [];
            if ($this->option('with-inbox')) {
                $akun = Akun::create([
                    'nama' => 'Uji Antrean',
                    'email' => 'smoke-'.Str::lower((string) Str::uuid()).'@async-smoke.invalid',
                    'password_hash' => Str::random(40),
                    'jenis' => 'utama',
                    'status' => 'nonaktif',
                ]);
                $penerima[] = $akun->id;
            }

            return Outbox::record('rt05.smoke', 'smoke', null, ['penerima' => $penerima])->id;
        });

        $this->line("event_id=$id");

        return self::SUCCESS;
    }

    private function verify(): int
    {
        $id = (string) $this->option('event');
        if (! Str::isUuid($id)) {
            return $this->invalid('--event harus UUID dari --phase=prepare.');
        }
        $expect = $this->option('expect-inbox');
        if ($expect !== null && (! is_numeric($expect) || (int) $expect < 0)) {
            return $this->invalid('--expect-inbox harus bilangan bulat >= 0.');
        }

        $deadline = microtime(true) + max(0, (int) $this->option('timeout'));
        do {
            $event = DB::table('outbox_events')->where('id', $id)->first(['completed_at', 'failed_at', 'published_at', 'attempts', 'last_error_code']);
            if (! $event) {
                $this->error('Event tidak ditemukan.');

                return self::FAILURE;
            }
            if ($event->completed_at || $event->failed_at || microtime(true) >= $deadline) {
                break;
            }
            usleep(500_000);
        } while (true);

        $inbox = DB::table('notifikasi')->where('event_id', $id)->count();
        $state = $event->completed_at ? 'completed' : ($event->failed_at ? 'failed' : ($event->published_at ? 'published' : 'pending'));
        $this->line("event_id=$id state=$state attempts={$event->attempts} inbox=$inbox last_error=".($event->last_error_code ?? '-'));

        if ($state !== 'completed') {
            $this->error('Event belum diselesaikan consumer.');

            return self::FAILURE;
        }
        if ($expect !== null && $inbox !== (int) $expect) {
            $this->error("Inbox $inbox, diharapkan $expect.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function status(): int
    {
        $row = DB::selectOne(<<<'SQL'
            select count(*) filter (where completed_at is null and failed_at is null) as pending,
                   count(*) filter (where completed_at is null and failed_at is null and published_at is not null) as published,
                   count(*) filter (where failed_at is not null and completed_at is null) as parked,
                   coalesce(extract(epoch from now() - min(created_at) filter (where completed_at is null and failed_at is null)), 0)::bigint as oldest_pending_seconds
            from outbox_events
        SQL);
        $failedJobs = DB::table('failed_jobs')->count();
        $this->line("pending={$row->pending} published_unfinished={$row->published} parked={$row->parked} oldest_pending_seconds={$row->oldest_pending_seconds} failed_jobs=$failedJobs");

        return self::SUCCESS;
    }

    private function invalid(string $message): int
    {
        $this->error($message);

        return self::INVALID;
    }

    /** Redis antrean pada host lokal atau service CI/compose lokal. */
    public static function isLocalRedis(): bool
    {
        return in_array((string) config('database.redis.queue.host'), ['127.0.0.1', 'localhost', '::1', 'redis', 'redis-queue'], true);
    }

    /** Sama dengan guard tests/bootstrap.php: hanya database *_test pada host lokal/CI. */
    public static function isLocalTestDatabase(): bool
    {
        $config = (new ConfigurationUrlParser)->parseConfiguration(config('database.connections.pgsql'));

        return in_array($config['host'] ?? '', ['127.0.0.1', 'localhost', '::1', 'postgres'], true)
            && str_ends_with((string) ($config['database'] ?? ''), '_test');
    }
}
