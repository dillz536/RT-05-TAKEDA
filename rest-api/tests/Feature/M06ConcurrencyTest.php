<?php

namespace Tests\Feature;

use App\Domain\Identity\Registration;
use App\Domain\Identity\RegistrationToken;
use App\Models\Akun;
use App\Models\TokenRegistrasi;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Tests\Support\CommittedData;
use Tests\Support\Concurrent;
use Tests\TestCase;

/**
 * M06 concurrency dengan proses PHP independen dan PostgreSQL nyata (bukan transaksi tes):
 * registrasi alamat/email sama, rotasi token vs registrasi, token admin pertama, dan pemakaian
 * ulang token reset. Data commit dibersihkan di tearDown.
 */
class M06ConcurrencyTest extends TestCase
{
    use CommittedData, Concurrent;

    private const WORKERS = 6;

    protected function setUp(): void
    {
        parent::setUp();
        $this->markCommittedData();
    }

    protected function tearDown(): void
    {
        $this->purgeCommittedData();
        parent::tearDown();
    }

    private function register(string $token, array $overrides, int $i): array
    {
        return [
            'kind' => 'http', 'method' => 'POST', 'uri' => '/api/v1/auth/register', 'ip' => "10.6.0.$i",
            'body' => array_merge([
                'email' => "ras$i@proses.test", 'password' => 'Rahasia123', 'password_confirmation' => 'Rahasia123',
                'registration_token' => $token, 'device_name' => "hp-$i", 'nama_kepala' => "Uji Ras $i",
                'blok' => 'R', 'jalan' => "Jl. Ras $i", 'nomor' => (string) $i, 'jenis_hunian' => 'pemilik',
                'anggota' => [['nama' => "Uji Anggota $i", 'hubungan_keluarga' => 'Anak']],
            ], $overrides),
        ];
    }

    /** Baris rumah/keluarga/warga yatim tidak boleh tersisa dari transaksi yang kalah. */
    private function assertNoOrphans(): void
    {
        $this->assertSame(0, DB::table('keluarga')->whereNotExists(fn ($q) => $q->from('penghunian')->whereColumn('penghunian.keluarga_id', 'keluarga.id'))->count());
        $this->assertSame(0, DB::table('rumah')->where('created_at', '>=', $this->committedSince)
            ->whereNotExists(fn ($q) => $q->from('penghunian')->whereColumn('penghunian.rumah_id', 'rumah.id'))->count());
        $this->assertSame(0, DB::table('akun')->where('created_at', '>=', $this->committedSince)
            ->whereNotExists(fn ($q) => $q->from('personal_access_tokens')->whereColumn('personal_access_tokens.tokenable_id', 'akun.id'))->count());
    }

    public function test_same_normalized_address_from_independent_processes_creates_exactly_one_household(): void
    {
        $token = RegistrationToken::rotate(null, 'tes');
        $spellings = [['jalan' => 'Jl. Kenanga', 'nomor' => '7'], ['jalan' => 'jalan kenanga', 'nomor' => 'No. 7'], ['jalan' => 'JLN  Kenanga', 'nomor' => 'no 7'], ['jalan' => 'Kenanga', 'nomor' => 'Nomor 7']];
        $specs = [];
        for ($i = 0; $i < self::WORKERS; $i++) {
            $specs[] = $this->register($token, $spellings[$i % count($spellings)] + ['blok' => 'K'], $i);
        }

        $results = $this->runConcurrently($specs);

        $this->assertSame(['201' => 1, '409:HOUSE_ALREADY_REGISTERED' => self::WORKERS - 1], $this->tally($results));
        $this->assertSame(1, DB::table('rumah')->where('alamat_key', 'k|kenanga|7')->count());
        $this->assertSame(1, DB::table('akun')->where('email', 'like', 'ras%@proses.test')->count());
        $this->assertSame(1, DB::table('penghunian')->join('rumah', 'rumah.id', '=', 'penghunian.rumah_id')->where('alamat_key', 'k|kenanga|7')->count());
        $this->assertNoOrphans();
    }

    public function test_same_email_from_independent_processes_creates_exactly_one_account(): void
    {
        $token = RegistrationToken::rotate(null, 'tes');
        $specs = [];
        for ($i = 0; $i < self::WORKERS; $i++) {
            // Email sama dengan kapitalisasi/spasi berbeda; alamat berbeda.
            $specs[] = $this->register($token, ['email' => ($i % 2 ? '  RAS.SAMA@Proses.TEST ' : 'ras.sama@proses.test')], $i);
        }

        $results = $this->runConcurrently($specs);

        $this->assertSame(['201' => 1, '422:REGISTRATION_REJECTED' => self::WORKERS - 1], $this->tally($results));
        $this->assertSame(1, DB::table('akun')->where('email', 'ras.sama@proses.test')->count());
        $this->assertSame(1, DB::table('rumah')->where('jalan', 'like', 'Jl. Ras %')->count());
        $this->assertNoOrphans();
    }

    /**
     * Urutan deterministik dengan handshake pg_locks (tanpa jeda waktu):
     * 1. registrasi awal memverifikasi token lama lalu berhenti di gerbang di dalam transaksinya;
     * 2. rotasi dimulai dan teramati mengantre pada advisory lock registrasi (token lama masih aktif);
     * 3. registrasi telat dimulai dan teramati mengantre di belakang rotasi (antrean FIFO);
     * 4. gerbang dilepas: awal 201 dengan token lama, rotasi commit, telat 422 (token dicabut);
     * 5. sesudah rotasi selesai, token lama 422 dan kode baru 201.
     */
    public function test_rotation_queues_behind_inflight_registration_and_late_registrar_sees_revoked_token(): void
    {
        $old = RegistrationToken::rotate(null, 'tes');
        $oldId = TokenRegistrasi::where('aktif', true)->value('id');

        $gate = $this->holdGate();
        try {
            $early = $this->spawnWorker($this->register($old, [], 1) + ['label' => 'awal', 'gate' => $gate]);
            $this->awaitAdvisoryWait('awal', $gate, [$early]);

            $rotate = $this->spawnWorker(['kind' => 'rotate', 'label' => 'rotasi']);
            $this->awaitAdvisoryWait('rotasi', 'rt05:registrasi', [$early, $rotate]);
            $this->assertTrue((bool) TokenRegistrasi::whereKey($oldId)->value('aktif'), 'Rotasi belum boleh mencabut token selama registrasi awal berjalan.');

            $late = $this->spawnWorker($this->register($old, [], 2) + ['label' => 'telat']);
            $this->awaitAdvisoryWait('telat', 'rt05:registrasi', [$early, $rotate, $late]);
            // Antrean lock berurutan: rotasi di depan registrasi telat.
            $queue = DB::select("SELECT a.application_name FROM pg_locks l JOIN pg_stat_activity a ON a.pid = l.pid
                WHERE l.locktype = 'advisory' AND NOT l.granted AND a.application_name IN ('rt05-race-rotasi', 'rt05-race-telat')
                ORDER BY a.query_start");
            $this->assertSame(['rt05-race-rotasi', 'rt05-race-telat'], array_column($queue, 'application_name'));
        } finally {
            $this->releaseGate($gate);
        }

        $e = $this->workerResult($early);
        $r = $this->workerResult($rotate);
        $l = $this->workerResult($late);
        $this->assertSame(201, $e['status'], 'Registrasi yang sudah memverifikasi token sebelum rotasi selesai dengan token lama.');
        $this->assertMatchesRegularExpression('/^\d{6}$/', $r['code']);
        $this->assertNotSame($old, $r['code']);
        $this->assertSame([422, 'REGISTRATION_TOKEN_INVALID'], [$l['status'], $l['code']], 'Registrasi yang mengantre di belakang rotasi melihat token lama dicabut.');

        // Penanda selesai: setelah rotasi commit, token lama selalu ditolak dan kode baru diterima.
        $after = $this->workerResult($this->spawnWorker($this->register($old, [], 3) + ['label' => 'sesudah']));
        $this->assertSame([422, 'REGISTRATION_TOKEN_INVALID'], [$after['status'], $after['code']]);
        $fresh = $this->workerResult($this->spawnWorker($this->register($r['code'], [], 4) + ['label' => 'baru']));
        $this->assertSame(201, $fresh['status']);

        $this->assertSame(1, TokenRegistrasi::where('aktif', true)->count());
        $this->assertNotNull(DB::table('token_registrasi')->where('id', $oldId)->value('dicabut_at'));
        $this->assertSame(1, DB::table('permohonan_akun')->where('token_id', $oldId)->count());
        $this->assertNoOrphans();
    }

    public function test_first_admin_token_view_from_concurrent_processes_creates_one_token(): void
    {
        DB::table('token_registrasi')->where('aktif', true)->update(['aktif' => false, 'dicabut_at' => now()]);

        $results = $this->runConcurrently(array_fill(0, self::WORKERS, ['kind' => 'ensure-token']));

        $this->assertCount(1, array_unique(array_column($results, 'code')));
        $this->assertSame(1, TokenRegistrasi::where('aktif', true)->count());
        $this->assertSame(1, TokenRegistrasi::where('created_at', '>=', $this->committedSince)->count());
    }

    public function test_concurrent_rotations_leave_exactly_one_active_token(): void
    {
        $results = $this->runConcurrently(array_fill(0, 4, ['kind' => 'rotate']));

        $this->assertCount(4, array_filter($results, fn ($r) => preg_match('/^\d{6}$/', $r['code'])));
        $this->assertSame(1, TokenRegistrasi::where('aktif', true)->count());
        $this->assertSame(4, TokenRegistrasi::where('created_at', '>=', $this->committedSince)->count());
    }

    /**
     * Reset pertama mengunci baris token reset lalu berhenti di gerbang sebelum menulis akun;
     * tiga request lain dengan token sama teramati menunggu lock baris itu. Gerbang dilepas:
     * tepat satu 200, tiga 422 generik (token sudah dihapus).
     */
    public function test_same_reset_token_used_concurrently_succeeds_once(): void
    {
        RegistrationToken::rotate(null, 'tes');
        $akun = Registration::primary([
            'email' => 'reset.ras@proses.test', 'password' => 'Rahasia123', 'nama_kepala' => 'Uji Reset Ras', 'whatsapp' => null,
            'blok' => 'R', 'jalan' => 'Jl. Reset', 'nomor' => '1', 'jenis_hunian' => 'pemilik', 'anggota' => [],
        ], RegistrationToken::reveal(RegistrationToken::active()));
        $resetToken = Password::broker('akun')->createToken($akun);
        $spec = fn (int $i, string $label) => ['kind' => 'http', 'method' => 'POST', 'uri' => '/api/v1/auth/reset-password', 'ip' => "10.7.0.$i", 'label' => $label, 'body' => [
            'email' => 'reset.ras@proses.test', 'token' => $resetToken, 'password' => "BaruSekali{$i}9", 'password_confirmation' => "BaruSekali{$i}9",
        ]];

        $gate = $this->holdGate();
        try {
            $first = $this->spawnWorker($spec(0, 'reset-pertama') + ['gate' => $gate]);
            $this->awaitAdvisoryWait('reset-pertama', $gate, [$first]);
            $others = [];
            for ($i = 1; $i <= 3; $i++) {
                $others[] = $this->spawnWorker($spec($i, 'reset-lain'));
            }
            $this->awaitRowLockWait('reset-lain', [$first, ...$others], 3);
        } finally {
            $this->releaseGate($gate);
        }

        $this->assertSame(200, $this->workerResult($first)['status']);
        $this->assertSame(['422:VALIDATION_FAILED' => 3], $this->tally(array_map(fn ($p) => $this->workerResult($p), $others)));
        $this->assertTrue(password_verify('BaruSekali09', Akun::find($akun->id)->password_hash));
        $this->assertSame(0, DB::table('password_reset_tokens')->where('email', 'reset.ras@proses.test')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'akun.reset_password')->where('entity_id', $akun->id)->count());
        $this->assertGreaterThan(1, Akun::find($akun->id)->versi_sesi);
    }
}
