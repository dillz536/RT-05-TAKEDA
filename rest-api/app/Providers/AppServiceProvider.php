<?php

namespace App\Providers;

use App\Support\Heartbeat;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Batas default docs/API.md; berdasar IP + email ternormalisasi agar NAT bersama tidak terkunci total.
        $email = fn (Request $r) => strtolower(trim((string) $r->input('email')));
        RateLimiter::for('login', fn (Request $r) => Limit::perMinute(5)->by($r->ip().'|'.$email($r)));
        RateLimiter::for('registrasi', fn (Request $r) => Limit::perMinutes(10, 5)->by($r->ip()));
        RateLimiter::for('lupa-password', fn (Request $r) => Limit::perMinutes(15, 3)->by($r->ip().'|'.$email($r)));
        // Submit reset memakai bucket sendiri: permintaan lupa password tidak menghabiskan kuota
        // reset dengan token yang masih berlaku. Token reset 64 hex, jadi batas ini untuk biaya bcrypt.
        RateLimiter::for('reset-password', fn (Request $r) => Limit::perMinutes(15, 10)->by($r->ip().'|'.$email($r)));
        RateLimiter::for('laporan', fn (Request $r) => Limit::perHour(20)->by((string) $r->user()?->id));

        // Heartbeat worker tiap putaran loop queue:work (dibatasi write_interval). Listener tidak
        // mengembalikan nilai: return false pada event Looping akan menghentikan worker.
        Event::listen(function (Looping $event): void {
            Heartbeat::beat(Heartbeat::workerComponent($event->queue), throttle: true);
        });
    }
}
