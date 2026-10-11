<?php

namespace Tests\Feature;

use App\Domain\Operations\OutboxDispatcher;
use App\Models\KategoriPengaduan;
use App\Models\Pengaduan;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Fixtures;
use Tests\TestCase;

/** Pengaduan/aspirasi/notifikasi (TESTING.md): anonimitas, kepemilikan, versi, foto, outbox. */
class ServicesTest extends TestCase
{
    use DatabaseTransactions, Fixtures;

    protected array $connectionsToTransact = ['pgsql'];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function outboxDiagnostics(): string
    {
        return json_encode(DB::table('outbox_events')->get(['type', 'attempts', 'completed_at', 'last_error_code', 'available_at', 'created_at'])->all()).' now='.DB::selectOne('select now() as n')->n;
    }

    private function complaint($akun, bool $anon = true, array $photos = [])
    {
        return $this->as($akun)->post('/api/v1/complaints', [
            'kategori_id' => KategoriPengaduan::first()->id, 'judul' => 'Uji lampu mati', 'deskripsi' => 'Deskripsi uji',
            'sembunyikan_identitas' => $anon ? '1' : '0', 'foto' => $photos,
        ], ['Accept' => 'application/json']);
    }

    public function test_anonymous_complaint_hides_reporter_from_other_residents_only(): void
    {
        $a = $this->warga('1');
        $b = $this->warga('2');
        $admin = $this->pengurus();
        $id = $this->complaint($a)->assertCreated()->json('data.id');

        foreach (["/api/v1/complaints/$id", '/api/v1/complaints', "/api/v1/complaints/$id/history"] as $url) {
            $body = $this->as($b)->getJson($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('Uji Kepala 1', $body, $url);
            $this->assertStringNotContainsString($a->id, $body, $url);
        }
        $this->as($a)->getJson("/api/v1/complaints/$id")->assertJsonPath('data.milik_saya', true);
        $this->as($admin)->getJson("/api/v1/complaints/$id")->assertJsonPath('data.pelapor.nama', 'Uji Kepala 1');
    }

    public function test_owner_edits_only_while_submitted_and_others_cannot(): void
    {
        $a = $this->warga('3');
        $b = $this->warga('4');
        $admin = $this->pengurus();
        $id = $this->complaint($a, false)->json('data.id');

        $this->as($b)->patchJson("/api/v1/complaints/$id", ['versi' => 1, 'judul' => 'diretas'])->assertForbidden();
        $this->as($a)->patchJson("/api/v1/complaints/$id", ['versi' => 1, 'judul' => 'Judul baru'])->assertOk();
        $this->as($admin)->postJson("/api/v1/admin/complaints/$id/status", ['status' => 'diproses', 'expected_version' => 2])->assertOk();
        $this->as($a)->patchJson("/api/v1/complaints/$id", ['versi' => 3, 'judul' => 'lagi'])->assertForbidden();
    }

    public function test_status_flow_stale_version_and_reopen_reason(): void
    {
        $a = $this->warga('5');
        $admin = $this->pengurus();
        $id = $this->complaint($a)->json('data.id');
        $this->as($admin);

        $this->postJson("/api/v1/admin/complaints/$id/status", ['status' => 'selesai', 'expected_version' => 1])->assertStatus(422)->assertJsonPath('error.code', 'INVALID_STATUS_TRANSITION');
        $this->postJson("/api/v1/admin/complaints/$id/status", ['status' => 'diproses', 'expected_version' => 1])->assertOk();
        $this->postJson("/api/v1/admin/complaints/$id/status", ['status' => 'selesai', 'expected_version' => 1])->assertStatus(409)->assertJsonPath('error.code', 'VERSION_CONFLICT');
        $this->postJson("/api/v1/admin/complaints/$id/status", ['status' => 'selesai', 'expected_version' => 2])->assertOk();
        $this->postJson("/api/v1/admin/complaints/$id/status", ['status' => 'diproses', 'expected_version' => 3])->assertStatus(422)->assertJsonPath('error.code', 'REOPEN_REASON_REQUIRED');
        $this->postJson("/api/v1/admin/complaints/$id/status", ['status' => 'diproses', 'expected_version' => 3, 'catatan' => 'belum tuntas'])->assertOk();
        $this->assertSame(4, DB::table('riwayat_pengaduan')->where('pengaduan_id', $id)->count());
    }

    public function test_status_change_notifies_owner_once_even_if_replayed(): void
    {
        $a = $this->warga('6');
        $admin = $this->pengurus();
        $id = $this->complaint($a)->json('data.id');
        $this->as($admin)->postJson("/api/v1/admin/complaints/$id/status", ['status' => 'diproses', 'expected_version' => 1])->assertOk();

        OutboxDispatcher::dispatch();
        DB::table('outbox_events')->update(['completed_at' => null, 'dispatch_lease_until' => null, 'published_at' => null]);
        OutboxDispatcher::dispatch();

        $this->assertSame(1, DB::table('notifikasi')->where('penerima_id', $a->id)->where('pengaduan_id', $id)->count(), $this->outboxDiagnostics());
        $this->assertSame(1, DB::table('notifikasi')->where('penerima_id', $admin->id)->where('pengaduan_id', $id)->count(), 'pengurus diberi tahu pengaduan baru');
        $text = DB::table('notifikasi')->where('pengaduan_id', $id)->pluck('isi')->implode(' ');
        $this->assertStringNotContainsString('Uji Kepala 6', $text);
    }

    public function test_photo_is_reencoded_private_and_spoofed_file_rejected(): void
    {
        $a = $this->warga('7');
        $b = $this->warga('8');
        $jpeg = UploadedFile::fake()->image('rumah-uji7.jpg', 2400, 1200);
        $res = $this->complaint($a, true, [$jpeg])->assertCreated();
        $foto = $res->json('data.foto.0');

        $this->assertSame(1600, $foto['lebar']);
        $stored = DB::table('foto_pengaduan')->first();
        $this->assertStringNotContainsString('uji7', $stored->storage_key);
        $this->as($b)->get($foto['url'])->assertOk()->assertHeader('Content-Type', 'image/jpeg');

        $spoof = UploadedFile::fake()->createWithContent('bukan.jpg', '<?php echo 1; ?>');
        $this->complaint($a, true, [$spoof])->assertStatus(415);
        $this->assertSame(1, Pengaduan::count());
    }

    public function test_archived_complaint_hidden_from_residents_history_kept(): void
    {
        $a = $this->warga('9');
        $admin = $this->pengurus();
        $id = $this->complaint($a)->json('data.id');
        $this->as($admin)->postJson("/api/v1/admin/complaints/$id/archive", ['alasan' => 'duplikat'])->assertNoContent();

        $this->as($a)->getJson("/api/v1/complaints/$id")->assertNotFound();
        $this->as($admin)->getJson("/api/v1/complaints/$id")->assertOk();
        $this->assertTrue(DB::table('riwayat_pengaduan')->where('pengaduan_id', $id)->exists());
    }

    public function test_aspiration_is_separate_with_its_own_flow(): void
    {
        $a = $this->warga('10');
        $admin = $this->pengurus();
        $id = $this->as($a)->postJson('/api/v1/aspirations', ['judul' => 'Uji aspirasi', 'isi' => 'Usulan', 'sembunyikan_identitas' => true])->assertCreated()->json('data.id');

        $this->as($admin)->postJson("/api/v1/admin/aspirations/$id/status", ['status' => 'diproses', 'expected_version' => 1])->assertStatus(422);
        $this->as($admin)->postJson("/api/v1/admin/aspirations/$id/status", ['status' => 'ditinjau', 'expected_version' => 1])->assertOk()->assertJsonPath('data.status', 'ditinjau');
        $this->assertSame(0, Pengaduan::count());
    }

    public function test_agenda_calendar_and_announcement_fanout(): void
    {
        $a = $this->warga('11');
        $admin = $this->pengurus();
        $agenda = $this->as($admin)->postJson('/api/v1/admin/agendas', [
            'nama' => 'Uji kerja bakti', 'deskripsi' => 'd', 'lokasi' => 'Lapangan', 'mulai_at' => now()->addDays(2)->toIso8601String(),
        ])->assertCreated();
        $this->as($admin)->postJson('/api/v1/admin/announcements', ['judul' => 'Uji pengumuman', 'isi' => 'Isi'])->assertCreated();
        OutboxDispatcher::dispatch();

        $this->as($a)->get('/api/v1/agendas/'.$agenda->json('data.id').'/calendar')->assertOk()->assertHeader('Content-Type', 'text/calendar; charset=utf-8');
        $this->assertStringStartsWith('https://calendar.google.com/', $agenda->json('data.google_calendar_url'));
        $inbox = $this->as($a)->getJson('/api/v1/notifications')->assertOk();
        $this->assertSame(['agenda', 'pengumuman'], collect($inbox->json('data'))->pluck('jenis')->sort()->values()->all(), $this->outboxDiagnostics());
        $this->assertSame(['agenda', 'pengumuman'], collect($inbox->json('data'))->pluck('jenis')->sort()->values()->all());
        $this->assertSame(2, $inbox->json('meta.belum_dibaca'));
        $this->as($a)->postJson('/api/v1/notifications/read-all')->assertOk()->assertJsonPath('data.ditandai', 2);
    }
}
