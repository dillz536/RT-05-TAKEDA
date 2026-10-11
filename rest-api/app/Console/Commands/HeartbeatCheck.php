<?php

namespace App\Console\Commands;

use App\Support\Heartbeat;
use Illuminate\Console\Command;
use Throwable;

/**
 * Probe internal heartbeat worker/scheduler untuk healthcheck container, CI, atau operator.
 * Exit 0 semua sehat, 1 ada yang basi/tidak ada/store tidak terjangkau, 2 argumen salah.
 * Output hanya nama komponen dan umur detik.
 */
class HeartbeatCheck extends Command
{
    protected $signature = 'rt05:heartbeat-check
        {--component=* : worker-default|worker-media|worker-exports|scheduler (boleh berulang)}
        {--max-age= : Batas umur detik (default rt05.heartbeat.max_age_seconds)}';

    protected $description = 'Periksa heartbeat internal worker/scheduler';

    public function handle(): int
    {
        $components = array_values(array_unique((array) $this->option('component')));
        $unknown = array_diff($components, Heartbeat::components());
        if ($components === [] || $unknown !== []) {
            $this->error('Pilih --component dari: '.implode(', ', Heartbeat::components()));

            return self::INVALID;
        }

        $maxAge = $this->option('max-age') ?? config('rt05.heartbeat.max_age_seconds', 300);
        if (! is_numeric($maxAge) || (int) $maxAge < 1) {
            $this->error('--max-age harus bilangan bulat positif.');

            return self::INVALID;
        }

        $healthy = true;
        foreach ($components as $component) {
            try {
                $age = Heartbeat::age($component);
            } catch (Throwable $e) {
                $this->error("$component store_unavailable (".$e::class.')');
                $healthy = false;

                continue;
            }

            if ($age === null) {
                $this->error("$component missing");
                $healthy = false;
            } elseif ($age > (int) $maxAge) {
                $this->error("$component stale age={$age}s max={$maxAge}s");
                $healthy = false;
            } else {
                $this->line("$component ok age={$age}s");
            }
        }

        return $healthy ? self::SUCCESS : self::FAILURE;
    }
}
