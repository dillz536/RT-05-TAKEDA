<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Menjalankan beberapa proses PHP independen (tests/Support/race-worker.php) yang dilepas
 * bersamaan oleh barrier advisory lock PostgreSQL. Setiap proses memakai koneksi database
 * sendiri, jadi transaksi benar-benar bersaing di server Postgres (bukan simulasi in-process).
 */
trait Concurrent
{
    /**
     * @param  list<array<string, mixed>>  $specs  satu spec per proses (kind http|ensure-token|rotate)
     * @return list<array{status:int, code:?string, data:mixed}>
     */
    protected function runConcurrently(array $specs): array
    {
        $barrier = random_int(1_000_000, 2_000_000_000);
        DB::select('SELECT pg_advisory_lock(?)', [$barrier]);

        $processes = [];
        try {
            foreach ($specs as $spec) {
                $processes[] = $this->spawnWorker($spec + ['barrier' => $barrier]);
            }

            // Tunggu sampai semua worker benar-benar memblok di barrier, lalu lepaskan sekaligus.
            $deadline = microtime(true) + 90;
            do {
                foreach ($processes as $process) {
                    if (! $process->isRunning() && ! $process->isSuccessful()) {
                        throw new RuntimeException('Worker gagal sebelum barrier: '.$process->getErrorOutput().$process->getOutput());
                    }
                }
                $waiting = (int) DB::selectOne(
                    "SELECT count(*) AS n FROM pg_locks WHERE locktype = 'advisory' AND classid = 0 AND objid = ? AND objsubid = 1 AND NOT granted",
                    [$barrier]
                )->n;
                if ($waiting === count($processes)) {
                    break;
                }
                usleep(50_000);
            } while (microtime(true) < $deadline);
            $this->assertSame(count($processes), $waiting, 'Semua worker harus menunggu di barrier.');
        } finally {
            DB::select('SELECT pg_advisory_unlock(?)', [$barrier]);
        }

        return array_map(fn (Process $process) => $this->workerResult($process), $processes);
    }

    /** Menjalankan satu worker (tanpa barrier bila spec tidak memuat 'barrier'). */
    protected function spawnWorker(array $spec): Process
    {
        $process = new Process(
            [PHP_BINARY, base_path('tests/Support/race-worker.php'), base64_encode(json_encode($spec, JSON_THROW_ON_ERROR))],
            base_path(),
            $this->workerEnvironment(),
            null,
            120,
        );
        $process->start();

        return $process;
    }

    /** @return array{status:int, code:?string, data:mixed} */
    protected function workerResult(Process $process): array
    {
        $process->wait();
        $this->assertTrue($process->isSuccessful(), 'Worker gagal: '.$process->getErrorOutput().$process->getOutput());
        $lines = array_values(array_filter(explode("\n", trim($process->getOutput()))));

        return json_decode((string) end($lines), true, 512, JSON_THROW_ON_ERROR);
    }

    /** Gerbang yang dipegang PHPUnit; worker dengan spec 'gate' berhenti di dalam transaksinya. */
    protected function holdGate(): int
    {
        $gate = random_int(1_000_000, 2_000_000_000);
        DB::select('SELECT pg_advisory_lock(?)', [$gate]);

        return $gate;
    }

    protected function releaseGate(int $gate): void
    {
        DB::select('SELECT pg_advisory_unlock(?)', [$gate]);
    }

    /**
     * Handshake: tunggu sampai $count koneksi worker berlabel $label menunggu advisory lock $key
     * (int = kunci langsung, string = hashtext(nama) seperti kode domain), dibaca dari pg_locks.
     *
     * @param  list<Process>  $processes  worker yang harus tetap berjalan selama menunggu
     */
    protected function awaitAdvisoryWait(string $label, int|string $key, array $processes, int $count = 1): void
    {
        $k = is_int($key) ? '?::bigint' : 'hashtext(?)::bigint';
        $this->awaitWaiting($label, $count, $processes, "SELECT count(*) AS n FROM pg_locks l JOIN pg_stat_activity a ON a.pid = l.pid
            WHERE a.application_name = ? AND l.locktype = 'advisory' AND NOT l.granted AND l.objsubid = 1
              AND l.classid::bigint = (($k >> 32) & 4294967295) AND l.objid::bigint = ($k & 4294967295)", [$key, $key]);
    }

    /**
     * Handshake: tunggu sampai $count worker berlabel $label menunggu lock baris (FOR UPDATE/SHARE).
     *
     * @param  list<Process>  $processes
     */
    protected function awaitRowLockWait(string $label, array $processes, int $count = 1): void
    {
        $this->awaitWaiting($label, $count, $processes, "SELECT count(*) AS n FROM pg_stat_activity a
            WHERE a.application_name = ? AND a.wait_event_type = 'Lock' AND a.wait_event IN ('transactionid', 'tuple')");
    }

    private function awaitWaiting(string $label, int $count, array $processes, string $sql, array $bindings = []): void
    {
        $n = 0;
        $deadline = microtime(true) + 60;
        do {
            foreach ($processes as $process) {
                if (! $process->isRunning()) {
                    $this->fail("Worker selesai sebelum handshake '$label' (tidak menunggu lock yang diharapkan): ".$process->getErrorOutput().$process->getOutput());
                }
            }
            $n = (int) DB::selectOne($sql, ['rt05-race-'.$label, ...$bindings])->n;
            if ($n === $count) {
                return;
            }
            usleep(20_000);
        } while (microtime(true) < $deadline);
        $this->fail("Handshake '$label': $count worker harus menunggu lock, teramati $n.");
    }

    /** Env worker eksplisit: database tes yang sama, tanpa koneksi migrasi, adapter lokal. */
    private function workerEnvironment(): array
    {
        return [
            'APP_ENV' => 'testing',
            'APP_KEY' => (string) config('app.key'),
            'APP_URL' => 'https://takeda-test.invalid',
            'DB_CONNECTION' => 'pgsql',
            'DATABASE_URL' => (string) config('database.connections.pgsql.url'),
            'DATABASE_URL_UNPOOLED' => '',
            'DB_SSLMODE' => 'prefer',
            'BCRYPT_ROUNDS' => '4',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'MAIL_MAILER' => 'array',
            'LOG_CHANNEL' => 'stderr',
        ];
    }

    /** Hitung hasil per status/kode, mis. ['201' => 1, '409:HOUSE_ALREADY_REGISTERED' => 7]. */
    protected function tally(array $results): array
    {
        $tally = [];
        foreach ($results as $r) {
            $key = $r['status'].($r['code'] && $r['status'] >= 400 ? ':'.$r['code'] : '');
            $tally[$key] = ($tally[$key] ?? 0) + 1;
        }
        ksort($tally);

        return $tally;
    }
}
