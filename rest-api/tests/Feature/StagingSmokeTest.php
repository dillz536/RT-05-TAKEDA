<?php

namespace Tests\Feature;

use App\Domain\Operations\OutboxDispatcher;
use App\Support\Outbox;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * rt05:staging-smoke (M04): penjaga APP_ENV=staging + --confirm-staging sebelum akses DB, dan
 * event sintetis tanpa penerima diproses consumer. Berjalan pada database *_test lokal/CI;
 * "staging" hanya disimulasikan lewat environment aplikasi, tidak pernah ke Neon.
 */
class StagingSmokeTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['pgsql'];

    /** @return array{0:int, 1:string, 2:int} exit code, output, jumlah query DB */
    private function smoke(array $options): array
    {
        DB::connection('pgsql')->flushQueryLog();
        DB::connection('pgsql')->enableQueryLog();
        $exit = Artisan::call('rt05:staging-smoke', $options);
        $queries = count(DB::connection('pgsql')->getQueryLog());
        DB::connection('pgsql')->disableQueryLog();

        return [$exit, Artisan::output(), $queries];
    }

    private function sideEffectCounts(): array
    {
        return collect(['akun', 'notifikasi', 'pembayaran_iuran', 'transaksi_kas', 'tagihan_iuran'])
            ->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();
    }

    public function test_rejects_non_staging_environments_before_database_access(): void
    {
        foreach (['production', 'testing', 'local', 'Staging'] as $env) {
            $this->app['env'] = $env;
            [$exit, $output, $queries] = $this->smoke(['--phase' => 'prepare', '--confirm-staging' => true]);

            $this->assertSame(1, $exit, $env);
            $this->assertStringContainsString('APP_ENV=staging', $output);
            $this->assertSame(0, $queries, "tidak boleh ada query DB pada env $env");
        }
        $this->assertSame(0, DB::table('outbox_events')->where('type', 'rt05.smoke')->count());
    }

    public function test_staging_requires_explicit_confirmation_before_database_access(): void
    {
        $this->app['env'] = 'staging';

        [$exit, $output, $queries] = $this->smoke(['--phase' => 'prepare']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('--confirm-staging', $output);
        $this->assertSame(0, $queries);
        $this->assertSame(0, DB::table('outbox_events')->where('type', 'rt05.smoke')->count());
    }

    public function test_invalid_arguments_rejected_without_database_access(): void
    {
        $this->app['env'] = 'staging';

        foreach ([
            ['--phase' => 'status'],
            ['--phase' => 'verify', '--event' => 'bukan-uuid'],
            ['--phase' => 'verify', '--event' => (string) Str::uuid(), '--timeout' => 601],
        ] as $options) {
            [$exit, , $queries] = $this->smoke($options + ['--confirm-staging' => true]);
            $this->assertSame(2, $exit, json_encode($options));
            $this->assertSame(0, $queries);
        }
    }

    public function test_synthetic_event_is_processed_without_accounts_inbox_or_finance(): void
    {
        $this->app['env'] = 'staging';
        $before = $this->sideEffectCounts();

        [$exit, $output] = $this->smoke(['--phase' => 'prepare', '--confirm-staging' => true]);
        $id = trim($output);
        $this->assertSame(0, $exit);
        $this->assertTrue(Str::isUuid($id), 'prepare hanya mencetak UUID');
        $event = DB::table('outbox_events')->where('id', $id)->first();
        $this->assertSame('rt05.smoke', $event->type);
        $this->assertEquals(['penerima' => [], 'penanda' => 'staging-smoke'], json_decode($event->safe_payload, true));

        // Belum diproses consumer: verify gagal setelah batas waktu, tanpa membocorkan payload.
        [$exit, $output] = $this->smoke(['--phase' => 'verify', '--event' => $id, '--timeout' => 0, '--confirm-staging' => true]);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('state=pending', $output);

        OutboxDispatcher::dispatch(); // antrean sync pada tes: dispatcher → consumer

        [$exit, $output] = $this->smoke(['--phase' => 'verify', '--event' => $id, '--timeout' => 0, '--confirm-staging' => true]);
        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('state=completed attempts=1 inbox=0 error_code=none', $output);
        foreach (['penerima', 'penanda', 'pgsql', 'postgres'] as $leak) {
            $this->assertStringNotContainsString($leak, $output);
        }
        $this->assertSame($before, $this->sideEffectCounts(), 'smoke staging tidak boleh membuat akun/inbox/keuangan');
    }

    public function test_verify_accepts_only_rt05_smoke_events(): void
    {
        $this->app['env'] = 'staging';
        $other = Outbox::record('pengumuman.terbit', 'pengumuman', (string) Str::uuid())->id;

        [$exit, $output] = $this->smoke(['--phase' => 'verify', '--event' => $other, '--timeout' => 0, '--confirm-staging' => true]);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('tidak ditemukan', $output);

        [$exit] = $this->smoke(['--phase' => 'verify', '--event' => (string) Str::uuid(), '--timeout' => 0, '--confirm-staging' => true]);
        $this->assertSame(1, $exit);
    }
}
