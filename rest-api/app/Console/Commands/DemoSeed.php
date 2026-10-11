<?php

namespace App\Console\Commands;

use App\Domain\Finance\Dues;
use App\Domain\Identity\Registration;
use App\Domain\Identity\RegistrationToken;
use App\Domain\Operations\OutboxDispatcher;
use App\Domain\Services\Reports;
use App\Models\Agenda;
use App\Models\Akun;
use App\Models\KategoriPengaduan;
use App\Models\Pengumuman;
use App\Support\Outbox;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Data SINTETIS untuk dev/staging/demo kelas. Ditolak pada APP_ENV=production.
 * Nama/alamat fiktif berlabel "Demo"; bukan data warga RT 05 yang sebenarnya.
 */
class DemoSeed extends Command
{
    protected $signature = 'rt05:demo-seed {--password= : Password akun demo (default acak, ditampilkan)}';

    protected $description = 'Isi data demo sintetis (bukan production)';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('Ditolak: demo seed tidak boleh dijalankan di production.');

            return self::FAILURE;
        }
        if (Akun::where('email', 'like', '%@demo.rt05.test')->exists()) {
            $this->warn('Data demo sudah ada.');

            return self::SUCCESS;
        }
        $password = (string) ($this->option('password') ?: Str::password(12, symbols: false));
        $code = RegistrationToken::rotate(null, 'demo seed');

        $families = [
            ['Demo Warga Satu', 'A', 'Jl. Kedaung Demo', '1', [['Demo Pasangan Satu', 'Istri']]],
            ['Demo Warga Dua', 'A', 'Jl. Kedaung Demo', '2', [['Demo Anak Dua', 'Anak']]],
            ['Demo Warga Tiga', 'B', 'Jl. Kedaung Demo', '7', []],
        ];
        $accounts = [];
        foreach ($families as $i => [$nama, $blok, $jalan, $nomor, $anggota]) {
            $accounts[] = Registration::primary([
                'email' => 'warga'.($i + 1).'@demo.rt05.test', 'password' => $password, 'nama_kepala' => $nama,
                'whatsapp' => null, 'blok' => $blok, 'jalan' => $jalan, 'nomor' => $nomor, 'jenis_hunian' => $i === 2 ? 'kontrak' : 'pemilik',
                'anggota' => array_map(fn ($a) => ['nama' => $a[0], 'hubungan_keluarga' => $a[1]], $anggota),
            ], $code);
        }
        Registration::additional([
            'email' => 'tambahan@demo.rt05.test', 'password' => $password, 'nama' => 'Demo Anggota Tambahan',
            'hubungan_keluarga' => 'Anak', 'blok' => 'A', 'jalan' => 'Jl. Kedaung Demo', 'nomor' => '1',
        ], $code);

        $pengurus = new Akun(['nama' => 'Demo Pengurus', 'email' => 'pengurus@demo.rt05.test', 'password_hash' => $password, 'jenis' => 'utama', 'status' => 'aktif']);
        $pengurus->peran = 'pengurus';
        $pengurus->save();

        DB::table('rumah')->update(['mulai_tagih' => CarbonImmutable::now('Asia/Jakarta')->subMonths(2)->startOfMonth()->toDateString()]);
        DB::table('tarif_iuran')->update(['mulai_periode' => CarbonImmutable::now('Asia/Jakarta')->subMonths(2)->startOfMonth()->toDateString()]);
        Dues::catchUp($pengurus);
        Dues::recordCash($pengurus, 'saldo_awal', 500000, CarbonImmutable::now('Asia/Jakarta')->subMonths(2)->startOfMonth()->toDateString(), 'Saldo awal', 'Saldo awal kas demo');
        $first = CarbonImmutable::now('Asia/Jakarta')->subMonths(2)->format('Y-m');
        $second = CarbonImmutable::now('Asia/Jakarta')->subMonth()->format('Y-m');
        Dues::recordPayment($pengurus, $accounts[0]->rumah()->id, [$first, $second], 150000, now('Asia/Jakarta')->toDateString(), 'Demo: bayar dua bulan');
        Dues::recordCash($pengurus, 'pengeluaran', 120000, now('Asia/Jakarta')->toDateString(), 'Kebersihan', 'Demo: pembelian kantong sampah');

        $kategori = KategoriPengaduan::where('nama', 'Infrastruktur')->first();
        $p = Reports::createComplaint($accounts[1], ['kategori_id' => $kategori->id, 'judul' => 'Demo: lampu jalan mati', 'deskripsi' => 'Contoh pengaduan sintetis untuk demo.', 'sembunyikan_identitas' => true], []);
        Reports::changeComplaintStatus($pengurus, $p, 'diproses', 'Demo: sedang dicek', 1);
        Reports::createAspiration($accounts[2], ['judul' => 'Demo: kerja bakti bulanan', 'isi' => 'Contoh aspirasi sintetis.']);

        DB::transaction(function () use ($pengurus) {
            $a = Agenda::create(['dibuat_oleh_id' => $pengurus->id, 'nama' => 'Demo: kerja bakti', 'lokasi' => 'Lapangan RT (demo)',
                'deskripsi' => 'Agenda sintetis untuk demo.', 'mulai_at' => now()->addDays(3)->setTime(0, 0), 'selesai_at' => now()->addDays(3)->setTime(3, 0)]);
            Outbox::record('agenda.dibuat', 'agenda', $a->id);
            $p = Pengumuman::create(['dibuat_oleh_id' => $pengurus->id, 'judul' => 'Demo: pengumuman', 'isi' => 'Pengumuman sintetis untuk demo aplikasi.', 'published_at' => now()]);
            Outbox::record('pengumuman.terbit', 'pengumuman', $p->id);
        });
        OutboxDispatcher::dispatch();

        $this->info('Data demo sintetis dibuat. Token registrasi: '.$code);
        $this->table(['Email', 'Peran'], [
            ['pengurus@demo.rt05.test', 'pengurus'], ['warga1@demo.rt05.test', 'warga utama'],
            ['warga2@demo.rt05.test', 'warga utama'], ['warga3@demo.rt05.test', 'warga utama (kontrak)'],
            ['tambahan@demo.rt05.test', 'akun tambahan (menunggu)'],
        ]);
        $this->warn('Password semua akun demo: '.$password);

        return self::SUCCESS;
    }
}
