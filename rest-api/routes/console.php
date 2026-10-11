<?php

use App\Domain\Community\Notifications;
use App\Domain\Finance\Dues;
use App\Domain\Identity\RegistrationToken;
use App\Domain\Operations\OutboxDispatcher;
use App\Models\Agenda;
use App\Models\TagihanIuran;
use App\Support\Heartbeat;
use App\Support\Outbox;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

/*
| Command operasional RT05 TAKEDA dan jadwal Asia/Jakarta (docs/ASYNC_JOBS.md).
| Semua job idempotent: aman dijalankan ulang setelah downtime atau restart.
*/

Artisan::command('rt05:outbox-dispatch {--limit=100}', function () {
    $r = OutboxDispatcher::dispatch((int) $this->option('limit'));
    $this->info("Event diklaim: {$r['claimed']}, dikirim ke antrean: {$r['enqueued']}, gagal enqueue: {$r['failed']}");

    // Antrean tidak tersedia: event tetap pending di Postgres; exit non-zero agar terlihat di log scheduler.
    return $r['failed'] > 0 ? 1 : 0;
})->purpose('Klaim outbox_events lalu kirim ID event ke antrean notifications');

Artisan::command('rt05:tagihan-generate {periode? : YYYY-MM; kosong = catch-up sampai bulan berjalan}', function () {
    $p = $this->argument('periode');
    $n = $p ? Dues::generate(Dues::period($p)) : Dues::catchUp();
    $this->info("Tagihan baru: $n");
})->purpose('Buat tagihan iuran per rumah/periode (idempotent)');

Artisan::command('rt05:pengingat-agenda', function () {
    // Satu kali H-1; agenda yang waktunya berubah di-reset oleh update agenda.
    $agendas = Agenda::whereNull('archived_at')->whereNull('pengingat_terkirim_at')
        ->whereBetween('mulai_at', [now(), now()->addHours(36)])->get();
    foreach ($agendas as $a) {
        DB::transaction(function () use ($a) {
            Outbox::record('agenda.pengingat', 'agenda', $a->id);
            $a->forceFill(['pengingat_terkirim_at' => now()])->save();
        });
    }
    $this->info('Pengingat agenda: '.$agendas->count());
})->purpose('Kirim pengingat agenda H-1');

Artisan::command('rt05:pengingat-iuran', function () {
    $period = CarbonImmutable::now('Asia/Jakarta')->startOfMonth()->toDateString();
    $slot = CarbonImmutable::now('Asia/Jakarta')->format('Y-m-d');
    $count = 0;
    TagihanIuran::where('periode', $period)->where('status', 'belum_bayar')->each(function (TagihanIuran $t) use ($slot, &$count) {
        $penerima = Notifications::primaryAccountsOf($t->rumah_id)->all();
        if ($penerima === []) {
            return;
        }
        // Dedup per tagihan + slot tanggal: event yang sama tidak dibuat dua kali.
        $exists = DB::table('outbox_events')->where('type', 'iuran.pengingat')->where('aggregate_id', $t->id)
            ->whereRaw("safe_payload->>'slot' = ?", [$slot])->exists();
        if (! $exists) {
            Outbox::record('iuran.pengingat', 'tagihan_iuran', $t->id, ['tagihan_id' => $t->id, 'penerima' => $penerima, 'slot' => $slot]);
            $count++;
        }
    });
    $this->info("Pengingat iuran: $count");
})->purpose('Pengingat iuran bulan berjalan ke akun utama rumah');

Artisan::command('rt05:token-rotate', function () {
    RegistrationToken::rotate(null, 'terjadwal mingguan');
    $this->info('Token registrasi dirotasi.');
})->purpose('Rotasi token registrasi RT');

// Heartbeat scheduler M03: dibaca rt05:heartbeat-check, tidak lewat endpoint publik.
Schedule::call(fn () => Heartbeat::beat('scheduler'))->everyMinute()->name('rt05:heartbeat-scheduler');
Schedule::command('rt05:outbox-dispatch')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('rt05:tagihan-generate')->dailyAt('00:10')->timezone('Asia/Jakarta')->withoutOverlapping()->onOneServer();
Schedule::command('rt05:pengingat-agenda')->hourly()->withoutOverlapping()->onOneServer();
Schedule::command('rt05:pengingat-iuran')->monthlyOn(5, '08:00')->timezone('Asia/Jakarta')->onOneServer();
Schedule::command('rt05:pengingat-iuran')->monthlyOn(20, '08:00')->timezone('Asia/Jakarta')->onOneServer();
Schedule::command('rt05:token-rotate')->weeklyOn(1, '00:05')->timezone('Asia/Jakarta')->onOneServer();
