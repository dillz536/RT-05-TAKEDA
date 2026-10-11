<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Heartbeat internal worker/scheduler (docs/DEPLOYMENT.md §Health). Disimpan di store
 * koordinasi (Redis instance queue, noeviction); dibaca hanya oleh rt05:heartbeat-check,
 * tidak lewat endpoint publik. Nilai hanya unix timestamp, tanpa host/credential.
 */
final class Heartbeat
{
    /** @var array<string, int> waktu tulis terakhir per komponen dalam proses ini */
    private static array $lastWrite = [];

    public static function components(): array
    {
        return config('rt05.heartbeat.components');
    }

    /** Komponen worker dari daftar antrean --queue (mis. "notifications,default"). */
    public static function workerComponent(?string $queues): string
    {
        $names = array_map('trim', explode(',', (string) $queues));

        return match (true) {
            in_array('media', $names, true) => 'worker-media',
            in_array('exports', $names, true) => 'worker-exports',
            default => 'worker-default',
        };
    }

    /** Tulis heartbeat; gagal Redis tidak boleh menghentikan worker/scheduler. */
    public static function beat(string $component, bool $throttle = false): bool
    {
        $now = time();
        if ($throttle && $now - (self::$lastWrite[$component] ?? 0) < (int) config('rt05.heartbeat.write_interval_seconds', 30)) {
            return false;
        }

        try {
            self::store()->put(self::key($component), $now, 86400);
            self::$lastWrite[$component] = $now;

            return true;
        } catch (Throwable $e) {
            Log::warning('heartbeat.write_failed', ['component' => $component, 'exception' => $e::class]);

            return false;
        }
    }

    /** Umur heartbeat dalam detik; null bila belum pernah tercatat. Exception dibiarkan naik. */
    public static function age(string $component): ?int
    {
        $at = self::store()->get(self::key($component));

        return is_numeric($at) ? max(0, time() - (int) $at) : null;
    }

    public static function forgetThrottle(): void
    {
        self::$lastWrite = [];
    }

    private static function store()
    {
        return Cache::store(config('rt05.heartbeat.store'));
    }

    private static function key(string $component): string
    {
        return 'rt05:heartbeat:'.$component;
    }
}
