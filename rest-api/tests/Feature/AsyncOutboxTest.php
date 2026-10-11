<?php

namespace Tests\Feature;

use App\Domain\Finance\Dues;
use App\Domain\Operations\OutboxDispatcher;
use App\Domain\Services\Reports;
use App\Exceptions\OutboxConsumerFailed;
use App\Jobs\ProcessOutboxEvent;
use App\Models\KategoriPengaduan;
use App\Models\OutboxEvent;
use App\Support\Outbox;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Queue\Connectors\ConnectorInterface;
use Illuminate\Queue\NullQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\Fixtures;
use Tests\TestCase;

/**
 * M03 outbox → antrean → consumer (docs/ASYNC_JOBS.md, TESTING.md §Worker/scheduler).
 * Postgres nyata (*_test); Redis diganti fake/sync. Restart worker, AOF, dan queue loss dengan
 * proses worker sungguhan dibuktikan di CI integrasi (rt05:async-smoke), bukan di sini.
 */
class AsyncOutboxTest extends TestCase
{
    use DatabaseTransactions, Fixtures;

    protected array $connectionsToTransact = ['pgsql'];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function event(string $id): object
    {
        return DB::table('outbox_events')->where('id', $id)->first();
    }

    private function smokeEvent(array $penerima): string
    {
        return Outbox::record('rt05.smoke', 'smoke', null, ['penerima' => $penerima])->id;
    }

    private function inbox(string $eventId): int
    {
        return DB::table('notifikasi')->where('event_id', $eventId)->count();
    }

    /** Event fixture (registrasi dsb.) dianggap selesai agar tes hanya melihat event yang diuji. */
    private function settleExisting(): void
    {
        DB::table('outbox_events')->whereNull('completed_at')->update(['completed_at' => DB::raw('greatest(now(), created_at)')]);
    }

    /** Antrean "Redis mati": push melempar exception yang pesannya memuat DSN palsu. */
    private function useBrokenQueue(): void
    {
        Queue::extend('broken', fn () => new class implements ConnectorInterface
        {
            public function connect(array $config): QueueContract
            {
                return new class extends NullQueue
                {
                    public function push($job, $data = '', $queue = null)
                    {
                        throw new RuntimeException('connect failed redis://user:sangat-rahasia@redis-internal:6379');
                    }
                };
            }
        });
        config(['queue.connections.broken' => ['driver' => 'broken'], 'rt05.outbox.queue_connection' => 'broken']);
    }

    /** Antrean pulih: event yang tertahan backoff dibuat jatuh tempo lalu dikirim lewat antrean sync. */
    private function recoverQueue(string $eventId): void
    {
        config(['rt05.outbox.queue_connection' => 'sync']);
        DB::table('outbox_events')->where('id', $eventId)->update(['available_at' => DB::raw("now() - interval '1 second'")]);
    }

    public function test_domain_rollback_leaves_no_outbox_event(): void
    {
        $a = $this->warga('31');
        $before = DB::table('outbox_events')->count();

        // Aksi domain nyata menulis pengaduan + audit + outbox; kegagalan sebelum commit membatalkan semuanya.
        try {
            DB::transaction(function () use ($a) {
                Reports::createComplaint($a, ['kategori_id' => KategoriPengaduan::first()->id, 'judul' => 'Uji rollback', 'deskripsi' => 'd', 'sembunyikan_identitas' => true], []);
                $this->assertSame(1, DB::table('outbox_events')->where('type', 'pengaduan.dibuat')->count());
                throw new RuntimeException('gagal sebelum commit');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(0, DB::table('pengaduan')->count());
        $this->assertSame($before, DB::table('outbox_events')->count(), 'rollback tidak boleh meninggalkan event outbox');
    }

    public function test_dispatcher_publishes_after_enqueue_and_only_consumer_completes(): void
    {
        Queue::fake();
        $a = $this->warga('32');
        $this->settleExisting();
        $id = $this->smokeEvent([$a->id]);

        $result = OutboxDispatcher::dispatch();

        $this->assertSame(['claimed' => 1, 'enqueued' => 1, 'failed' => 0], $result);
        Queue::assertPushedOn('notifications', ProcessOutboxEvent::class, fn (ProcessOutboxEvent $job) => $job->eventId === $id);
        $row = $this->event($id);
        $this->assertNotNull($row->published_at);
        $this->assertNull($row->completed_at, 'dispatcher tidak boleh menandai completed');
        $this->assertNull($row->dispatch_lease_until);
        $this->assertSame(0, $this->inbox($id));

        (new ProcessOutboxEvent($id))->handle();
        $this->assertNotNull($this->event($id)->completed_at);
        $this->assertSame(1, $this->inbox($id));
    }

    public function test_duplicate_delivery_creates_one_inbox_per_recipient(): void
    {
        Queue::fake();
        $a = $this->warga('33');
        $b = $this->warga('34');
        $id = $this->smokeEvent([$a->id, $b->id, $a->id]);

        (new ProcessOutboxEvent($id))->handle();
        $completedAt = $this->event($id)->completed_at;
        (new ProcessOutboxEvent($id))->handle();

        // Replay setelah completion hilang (mis. crash sesudah inbox): constraint inbox menahan duplikat.
        DB::table('outbox_events')->where('id', $id)->update(['completed_at' => null]);
        (new ProcessOutboxEvent($id))->handle();

        $this->assertSame(2, $this->inbox($id));
        $this->assertSame(1, DB::table('notifikasi')->where('event_id', $id)->where('penerima_id', $a->id)->count());
        $this->assertNotNull($completedAt);
    }

    public function test_enqueue_failure_keeps_event_and_domain_row_durable_with_safe_code(): void
    {
        $a = $this->warga('35');
        $this->pengurus();
        $this->settleExisting();
        $complaint = $this->as($a)->postJson('/api/v1/complaints', [
            'kategori_id' => KategoriPengaduan::first()->id, 'judul' => 'Uji antrean mati', 'deskripsi' => 'd', 'sembunyikan_identitas' => true,
        ])->assertCreated()->json('data.id');
        $id = DB::table('outbox_events')->where('type', 'pengaduan.dibuat')->where('aggregate_id', $complaint)->value('id');

        $this->useBrokenQueue();

        $this->assertSame(['claimed' => 1, 'enqueued' => 0, 'failed' => 1], OutboxDispatcher::dispatch());
        $this->artisan('rt05:outbox-dispatch')->assertExitCode(0); // tertahan backoff: tidak diklaim ulang

        $row = $this->event($id);
        $this->assertNull($row->published_at);
        $this->assertNull($row->completed_at);
        $this->assertNull($row->dispatch_lease_until);
        $this->assertSame('enqueue_failed:RuntimeException', $row->last_error_code);
        $this->assertStringNotContainsString('rahasia', (string) $row->last_error_code);
        $this->assertTrue((bool) DB::selectOne('select available_at > now() as later from outbox_events where id = ?', [$id])->later);
        $this->assertSame(1, DB::table('pengaduan')->where('id', $complaint)->count(), 'transaksi domain tetap tersimpan');

        // Redis pulih: event dikirim ulang dan consumer menyelesaikannya.
        $this->recoverQueue($id);
        $this->assertSame(1, OutboxDispatcher::dispatch()['enqueued']);
        $this->assertNotNull($this->event($id)->completed_at);
        $this->assertNull($this->event($id)->last_error_code);
        $this->assertSame(1, $this->inbox($id));
    }

    public function test_offline_payment_stays_single_through_queue_outage_retry_and_redelivery(): void
    {
        $warga = $this->warga('37');
        $rumah = $warga->rumah();
        $period = now('Asia/Jakarta')->format('Y-m');
        DB::table('rumah')->where('id', $rumah->id)->update(['mulai_tagih' => $period.'-01']);
        Dues::generate(Dues::period($period));
        $this->as($this->pengurus());
        $this->settleExisting();
        $this->useBrokenQueue();

        $finance = fn () => [
            'pembayaran' => DB::table('pembayaran_iuran')->count(),
            'alokasi' => DB::table('alokasi_pembayaran')->count(),
            'kas' => DB::table('transaksi_kas')->count(),
            'saldo' => Dues::balance(),
            'belum_bayar' => DB::table('tagihan_iuran')->where('rumah_id', $rumah->id)->where('status', 'belum_bayar')->count(),
        ];
        $expected = ['pembayaran' => 1, 'alokasi' => 1, 'kas' => 1, 'saldo' => 75000, 'belum_bayar' => 0];
        $key = (string) Str::uuid();
        $body = ['rumah_id' => $rumah->id, 'periode' => [$period], 'nominal' => 75000, 'tanggal_bayar' => now('Asia/Jakarta')->toDateString()];

        // Antrean mati setelah commit: pembayaran tetap tercatat satu kali, side effect pending.
        $this->postJson('/api/v1/admin/dues-payments', $body, ['Idempotency-Key' => $key])->assertCreated();
        $this->assertSame(1, OutboxDispatcher::dispatch()['failed']);
        $id = DB::table('outbox_events')->where('type', 'iuran.pembayaran_dicatat')->value('id');
        $this->assertSame('enqueue_failed:RuntimeException', $this->event($id)->last_error_code);
        $this->assertSame($expected, $finance());

        // Klien timeout lalu mengulang dengan key sama: replay, bukan posting kedua.
        $this->postJson('/api/v1/admin/dues-payments', $body, ['Idempotency-Key' => $key])
            ->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame(1, DB::table('outbox_events')->where('type', 'iuran.pembayaran_dicatat')->count());

        // Antrean pulih, lalu event terkirim ganda (replay stale / duplikat Redis).
        $this->recoverQueue($id);
        OutboxDispatcher::dispatch();
        (new ProcessOutboxEvent($id))->handle();
        DB::table('outbox_events')->where('id', $id)->update(['completed_at' => null]);
        (new ProcessOutboxEvent($id))->handle();

        $this->assertNotNull($this->event($id)->completed_at);
        $this->assertSame(1, DB::table('notifikasi')->where('event_id', $id)->where('penerima_id', $warga->id)->count());
        $this->assertSame(1, $this->inbox($id));
        $this->assertSame($expected, $finance(), 'consumer tidak boleh menulis ulang keuangan');
    }

    public function test_stale_published_event_is_replayed_but_recent_one_is_not(): void
    {
        Queue::fake();
        $lost = $this->smokeEvent([]);
        $inFlight = $this->smokeEvent([]);
        DB::table('outbox_events')->where('id', $lost)->update(['published_at' => DB::raw("now() - interval '20 minutes'"), 'attempts' => 1]);
        DB::table('outbox_events')->where('id', $inFlight)->update(['published_at' => DB::raw("now() - interval '1 minute'"), 'attempts' => 1]);

        OutboxDispatcher::dispatch();

        Queue::assertPushed(ProcessOutboxEvent::class, 1);
        Queue::assertPushed(ProcessOutboxEvent::class, fn (ProcessOutboxEvent $job) => $job->eventId === $lost);
        $this->assertEquals(2, $this->event($lost)->attempts);
        $this->assertTrue((bool) DB::selectOne("select published_at > now() - interval '1 minute' as fresh from outbox_events where id = ?", [$lost])->fresh);
        $this->assertEquals(1, $this->event($inFlight)->attempts);
    }

    public function test_expired_lease_is_reclaimed_and_active_lease_is_skipped(): void
    {
        Queue::fake();
        $crashed = $this->smokeEvent([]);
        $claimed = $this->smokeEvent([]);
        DB::table('outbox_events')->where('id', $crashed)->update(['dispatch_lease_until' => DB::raw("now() - interval '1 second'"), 'attempts' => 1]);
        DB::table('outbox_events')->where('id', $claimed)->update(['dispatch_lease_until' => DB::raw("now() + interval '1 minute'"), 'attempts' => 1]);

        OutboxDispatcher::dispatch();

        Queue::assertPushed(ProcessOutboxEvent::class, 1);
        Queue::assertPushed(ProcessOutboxEvent::class, fn (ProcessOutboxEvent $job) => $job->eventId === $crashed);
        $this->assertNull($this->event($claimed)->published_at);
    }

    public function test_consumer_failure_rolls_back_then_parks_and_manual_retry_recovers(): void
    {
        Queue::fake();
        $a = $this->warga('36');
        $this->settleExisting();
        $missing = (string) Str::uuid();
        $id = $this->smokeEvent([$a->id, $missing]); // penerima kedua tidak ada → FK gagal

        $caught = null;
        try {
            (new ProcessOutboxEvent($id))->handle();
        } catch (OutboxConsumerFailed $e) {
            $caught = $e;
        }
        $this->assertNotNull($caught, 'consumer seharusnya gagal');
        // Yang sampai ke failed_jobs/log hanya kelas + SQLSTATE, tanpa SQL/binding/ID akun.
        $this->assertSame('QueryException:23503', $caught->getMessage());
        $this->assertNull($caught->getPrevious());
        $this->assertStringNotContainsString($a->id, (string) $caught);
        $this->assertSame(0, $this->inbox($id), 'inbox sebagian tidak boleh tersimpan');
        $this->assertNull($this->event($id)->completed_at);

        // Worker memanggil failed() setelah percobaan habis: event diparkir, tidak di-replay otomatis.
        (new ProcessOutboxEvent($id))->failed($caught);
        $this->assertNotNull($this->event($id)->failed_at);
        $this->assertSame('consumer_failed:QueryException:23503', $this->event($id)->last_error_code);
        DB::table('outbox_events')->where('id', $id)->update(['published_at' => DB::raw("now() - interval '1 hour'")]);
        OutboxDispatcher::dispatch();
        Queue::assertNothingPushed();

        // Operator memperbaiki penyebab lalu mengulang event ini saja.
        OutboxEvent::whereKey($id)->update(['safe_payload' => json_encode(['penerima' => [$a->id]])]);
        $this->artisan('rt05:outbox-retry', ['event' => $id])->assertExitCode(0);
        $this->assertNull($this->event($id)->failed_at);
        OutboxDispatcher::dispatch();
        Queue::assertPushed(ProcessOutboxEvent::class, fn (ProcessOutboxEvent $job) => $job->eventId === $id);
        (new ProcessOutboxEvent($id))->handle();
        $this->assertSame(1, $this->inbox($id));

        $this->artisan('rt05:outbox-retry', ['event' => 'bukan-uuid'])->assertExitCode(2);
        $this->artisan('rt05:outbox-retry', ['event' => (string) Str::uuid()])->assertExitCode(1);
    }

    public function test_unknown_event_type_is_parked_not_silently_completed(): void
    {
        $id = Outbox::record('tidak.dikenal', 'uji', null)->id;

        OutboxDispatcher::dispatch(); // antrean sync: consumer berjalan di proses ini

        $row = $this->event($id);
        $this->assertNull($row->completed_at);
        $this->assertNotNull($row->failed_at);
        $this->assertSame('consumer_failed:UnknownOutboxEvent', $row->last_error_code);
    }

    public function test_async_smoke_prepare_and_verify_without_leaking_identity(): void
    {
        $this->artisan('rt05:async-smoke', ['--phase' => 'prepare', '--with-inbox' => true])
            ->expectsOutputToContain('event_id=')
            ->doesntExpectOutputToContain('@')
            ->assertExitCode(0);
        $id = DB::table('outbox_events')->where('type', 'rt05.smoke')->latest('created_at')->value('id');

        $this->artisan('rt05:async-smoke', ['--phase' => 'verify', '--event' => $id, '--timeout' => 0])->assertExitCode(1);
        OutboxDispatcher::dispatch();
        $this->artisan('rt05:async-smoke', ['--phase' => 'verify', '--event' => $id, '--timeout' => 0, '--expect-inbox' => 1])
            ->expectsOutputToContain('state=completed')
            ->assertExitCode(0);
        $this->artisan('rt05:async-smoke', ['--phase' => 'status'])->expectsOutputToContain('pending=')->assertExitCode(0);
        $this->artisan('rt05:async-smoke', ['--phase' => 'bogus'])->assertExitCode(2);
    }

    public function test_async_smoke_refuses_non_test_targets(): void
    {
        config(['database.redis.queue.host' => 'redis.example.net']);
        $this->artisan('rt05:async-smoke', ['--phase' => 'prepare'])->assertExitCode(1);

        config(['database.redis.queue.host' => '127.0.0.1', 'database.connections.pgsql.url' => 'postgresql://rt05_app:x@127.0.0.1:5432/rt05_staging']);
        $this->artisan('rt05:async-smoke', ['--phase' => 'status'])->assertExitCode(1);
    }

    public function test_outbox_dispatch_is_scheduled_every_minute_without_overlap(): void
    {
        $events = collect(app(Schedule::class)->events());
        $dispatch = $events->first(fn ($e) => str_contains((string) $e->command, 'rt05:outbox-dispatch'));

        $this->assertSame('* * * * *', $dispatch->expression);
        $this->assertTrue($dispatch->withoutOverlapping);
        $this->assertTrue($dispatch->onOneServer);
    }
}
