<?php

namespace Tests\Feature;

use App\Support\Heartbeat;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/** Heartbeat internal worker/scheduler (DEPLOYMENT.md §Health); store array pada tes. */
class HeartbeatTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['rt05.heartbeat.store' => 'array']);
        Cache::store('array')->flush();
        Heartbeat::forgetThrottle();
    }

    public function test_check_reports_missing_fresh_and_stale(): void
    {
        $this->artisan('rt05:heartbeat-check', ['--component' => ['scheduler']])
            ->expectsOutputToContain('scheduler missing')->assertExitCode(1);

        Heartbeat::beat('scheduler');
        $this->artisan('rt05:heartbeat-check', ['--component' => ['scheduler']])
            ->expectsOutputToContain('scheduler ok')->assertExitCode(0);

        Cache::store('array')->put('rt05:heartbeat:scheduler', time() - 600);
        $this->artisan('rt05:heartbeat-check', ['--component' => ['scheduler'], '--max-age' => 300])
            ->expectsOutputToContain('scheduler stale')->assertExitCode(1);
    }

    public function test_check_rejects_unknown_or_missing_component(): void
    {
        $this->artisan('rt05:heartbeat-check')->assertExitCode(2);
        $this->artisan('rt05:heartbeat-check', ['--component' => ['api']])->assertExitCode(2);
        $this->artisan('rt05:heartbeat-check', ['--component' => ['scheduler'], '--max-age' => 'x'])->assertExitCode(2);
    }

    public function test_worker_loop_writes_heartbeat_per_queue_without_stopping_worker(): void
    {
        // until() === false menghentikan queue:work; listener heartbeat harus netral.
        $this->assertNotFalse(Event::until(new Looping('redis', 'notifications,default')));
        Event::dispatch(new Looping('redis', 'media'));

        $this->artisan('rt05:heartbeat-check', ['--component' => ['worker-default', 'worker-media']])->assertExitCode(0);
        $this->artisan('rt05:heartbeat-check', ['--component' => ['worker-exports']])->assertExitCode(1);
        $this->assertSame('worker-exports', Heartbeat::workerComponent('exports'));
        $this->assertSame('worker-default', Heartbeat::workerComponent('default'));
    }

    public function test_store_failure_does_not_throw_from_beat(): void
    {
        config(['rt05.heartbeat.store' => 'tidak-ada']);

        $this->assertFalse(Heartbeat::beat('scheduler'));
        $this->artisan('rt05:heartbeat-check', ['--component' => ['scheduler']])
            ->expectsOutputToContain('store_unavailable')->assertExitCode(1);
    }

    public function test_scheduler_heartbeat_runs_every_minute(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => $e->description === 'rt05:heartbeat-scheduler');

        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
    }
}
