<?php

/*
| Worker proses independen untuk tes concurrency M06 (tests/Support/Concurrent.php).
| Setiap worker mem-boot aplikasi sendiri dengan koneksi PostgreSQL sendiri, menunggu barrier
| advisory lock yang dipegang proses PHPUnit, lalu menjalankan satu request HTTP lewat kernel
| (stack middleware/controller penuh) atau satu aksi domain. Hasil dicetak sebagai satu baris JSON.
| Menolak berjalan bila database bukan *_test pada host lokal.
*/

use App\Domain\Identity\RegistrationToken;
use App\Domain\Identity\TokenAttempts;
use App\Models\Akun;
use Illuminate\Cache\Events\WritingKey;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

require __DIR__.'/../../vendor/autoload.php';

$spec = json_decode(base64_decode($argv[1] ?? ''), true, 512, JSON_THROW_ON_ERROR);

$app = require __DIR__.'/../../bootstrap/app.php';
$http = $app->make(HttpKernel::class);
$app->make(ConsoleKernel::class)->bootstrap();

$db = (new ConfigurationUrlParser)->parseConfiguration(config('database.connections.pgsql'));
if (! in_array($db['host'] ?? '', ['127.0.0.1', 'localhost', '::1', 'postgres'], true) || ! str_ends_with((string) ($db['database'] ?? ''), '_test')) {
    fwrite(STDERR, "Worker ditolak: database harus *_test pada host lokal.\n");
    exit(3);
}

// Label koneksi agar PHPUnit dapat mengamati worker ini di pg_stat_activity (handshake).
DB::select("SELECT set_config('application_name', ?, false)", ['rt05-race-'.($spec['label'] ?? 'worker')]);

// Barrier: semua worker menunggu lock shared sampai PHPUnit melepas lock eksklusif.
if (isset($spec['barrier'])) {
    DB::select('SELECT pg_advisory_lock_shared(?)', [$spec['barrier']]);
    DB::select('SELECT pg_advisory_unlock_shared(?)', [$spec['barrier']]);
}

// Gerbang deterministik: penulisan akun pertama (di dalam transaksi, setelah token/baris reset
// dikunci) berhenti pada lock yang dipegang PHPUnit sampai tes melepasnya. Tanpa jeda waktu.
// Gerbang pada tulis penghitung limiter token (event WritingKey sebelum put): menahan proses tepat
// di antara pembacaan/INCR penghitung dan penulisannya.
if ($limiterGate = (int) ($spec['gate_on_limiter_write'] ?? 0)) {
    $held = false;
    Event::listen(WritingKey::class, function (WritingKey $e) use ($limiterGate, &$held) {
        if (! $held && str_contains($e->key, 'token-registrasi-gagal:') && ! str_ends_with($e->key, ':timer')) {
            $held = true;
            DB::select('SELECT pg_advisory_lock_shared(?)', [$limiterGate]);
            DB::select('SELECT pg_advisory_unlock_shared(?)', [$limiterGate]);
        }
    });
}

if ($gate = (int) ($spec['gate'] ?? 0)) {
    $passed = false;
    Akun::saving(function () use ($gate, &$passed) {
        if (! $passed) {
            $passed = true;
            DB::select('SELECT pg_advisory_lock_shared(?)', [$gate]);
            DB::select('SELECT pg_advisory_unlock_shared(?)', [$gate]);
        }
    });
}

$result = match ($spec['kind']) {
    'http' => (function () use ($http, $spec) {
        $request = Request::create($spec['uri'], $spec['method'], [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => $spec['ip'] ?? '127.0.0.1',
        ], json_encode($spec['body'] ?? [], JSON_THROW_ON_ERROR));
        $response = $http->handle($request);
        $body = json_decode((string) $response->getContent(), true);

        return ['status' => $response->getStatusCode(), 'code' => $body['error']['code'] ?? null, 'data' => $body['data'] ?? null];
    })(),
    'ensure-token' => ['status' => 200, 'code' => RegistrationToken::reveal(RegistrationToken::ensureActive(null))],
    'rotate' => ['status' => 200, 'code' => RegistrationToken::rotate(null, 'tes concurrency')],
    // Satu reservasi slot kegagalan token (tanpa HTTP/throttle registrasi); opsional langsung release.
    'reserve' => (function () use ($spec) {
        $request = Request::create('/', 'POST', server: ['REMOTE_ADDR' => $spec['ip'] ?? '127.0.0.1']);
        try {
            TokenAttempts::reserve($request);
            if (! empty($spec['release'])) {
                TokenAttempts::release($request);
            }

            return ['status' => 200, 'code' => null, 'data' => null];
        } catch (ThrottleRequestsException $e) {
            return ['status' => 429, 'code' => 'TOO_MANY_REQUESTS', 'data' => $e->getHeaders()['Retry-After'] ?? null];
        }
    })(),
};

echo json_encode($result, JSON_THROW_ON_ERROR), "\n";
