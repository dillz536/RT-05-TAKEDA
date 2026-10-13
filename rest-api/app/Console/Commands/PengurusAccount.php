<?php

namespace App\Console\Commands;

use App\Domain\Identity\PasswordPolicy;
use App\Domain\Identity\PasswordReset;
use App\Models\Akun;
use App\Models\PreferensiNotifikasi;
use App\Notifications\ResetPasswordNotification;
use App\Support\Audit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Bootstrap/grant/revoke pengurus oleh operator (BR v1: bukan lewat API/registrasi).
 *
 * Tanpa password bawaan di Git dan password tidak pernah dicetak ke output (log CI/deploy):
 *  - env RT05_ADMIN_PASSWORD saat dijalankan (divalidasi), atau
 *  - prompt tersembunyi bila terminal interaktif, atau
 *  - password acak yang tidak ditampilkan + email reset diantrekan (pengurus mengatur sendiri).
 *    Jalur ketiga ditolak sebelum akun dibuat bila email pasti tidak terkirim (transport log,
 *    mailer tidak dikenal, APP_URL bukan HTTPS di luar local/testing, mailer array di luar tes).
 *    Kegagalan SMTP/antrean sesudah commit tidak membatalkan akun (exit 1 + petunjuk).
 * Grant/revoke mengunci akun, mencabut semua bearer dan sesi browser, dan menolak mencabut
 * pengurus aktif terakhir. Setiap perubahan diaudit (actor null = operator CLI).
 */
class PengurusAccount extends Command
{
    protected $signature = 'rt05:pengurus {aksi : buat|grant|revoke} {email} {--nama=Pengurus RT : Nama tampilan akun baru}';

    protected $description = 'Kelola akun pengurus (operator, diaudit)';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        if (strlen($email) > 254 || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Email tidak valid.');

            return self::INVALID;
        }

        return match ($this->argument('aksi')) {
            'buat' => $this->create($email),
            'grant' => $this->setRole($email, 'pengurus'),
            'revoke' => $this->setRole($email, 'warga'),
            default => $this->invalidAction(),
        };
    }

    private function invalidAction(): int
    {
        $this->error('Aksi harus buat, grant, atau revoke.');

        return self::INVALID;
    }

    private function create(string $email): int
    {
        $nama = trim((string) $this->option('nama'));
        if ($nama === '' || mb_strlen($nama) > 120) {
            $this->error('Nama wajib diisi, maksimal 120 karakter.');

            return self::INVALID;
        }
        [$password, $source] = $this->passwordSource();
        if ($password === null) {
            return self::INVALID;
        }
        // Tanpa password eksplisit, satu-satunya jalan masuk adalah email reset. Bila konfigurasi
        // pasti tidak mengirimnya, jangan buat pengurus yang tidak dapat masuk.
        if ($source === 'email_reset' && ($reason = $this->resetUndeliverable())) {
            $this->error('Akun tidak dibuat: email reset tidak dapat dikirim ('.$reason.')');
            $this->line('Pakai RT05_ADMIN_PASSWORD=... atau jalankan interaktif (prompt tersembunyi), atau konfigurasikan SMTP lalu ulangi.');

            return self::FAILURE;
        }

        $created = DB::transaction(function () use ($email, $nama, $password, $source) {
            DB::select("SELECT pg_advisory_xact_lock(hashtext('rt05:peran-pengurus'))");
            if (Akun::where('email', $email)->exists()) {
                return false;
            }
            $akun = new Akun(['nama' => $nama, 'email' => $email, 'password_hash' => $password, 'jenis' => 'utama', 'status' => 'aktif']);
            $akun->peran = 'pengurus';
            $akun->save();
            PreferensiNotifikasi::create(['akun_id' => $akun->id]);
            Audit::record(null, 'akun.pengurus_dibuat_operator', 'akun', $akun->id, ['kanal' => 'cli', 'password' => $source]);

            return [$akun, $source === 'email_reset' ? PasswordReset::createToken($akun) : null];
        });
        if (! $created) {
            $this->error('Akun sudah ada; gunakan grant.');

            return self::FAILURE;
        }

        $this->info("Akun pengurus dibuat: $email");
        [$akun, $token] = $created;
        if ($token !== null) {
            // Sesudah commit: kegagalan SMTP/antrean tidak membatalkan akun yang sudah tersimpan.
            if (! PasswordReset::notify($akun, $token)) {
                $this->error('Email reset gagal diantrekan/dikirim. Akun tetap tersimpan; setelah SMTP/antrean pulih, minta reset lewat "Lupa kata sandi".');

                return self::FAILURE;
            }
            $this->warn('Password acak tidak ditampilkan. Email reset diantrekan; pengurus mengatur kata sandi lewat tautan/kode email.');
        }

        return self::SUCCESS;
    }

    /** Alasan email reset pasti tidak sampai (tanpa nilai rahasia), atau null. Mailer array hanya untuk tes. */
    private function resetUndeliverable(): ?string
    {
        $reason = ResetPasswordNotification::blockedReason();
        if ($reason === null && ! app()->environment('testing') && config('mail.mailers.'.config('mail.default').'.transport') === 'array') {
            $reason = 'mailer array tidak mengirim email.';
        }

        return $reason;
    }

    /** @return array{0: ?string, 1: string} */
    private function passwordSource(): array
    {
        $env = getenv('RT05_ADMIN_PASSWORD');
        if (is_string($env) && $env !== '') {
            return [$this->validPassword($env) ? $env : null, 'env'];
        }
        if ($this->input->isInteractive()) {
            $password = (string) $this->secret('Password pengurus (tidak ditampilkan)');
            if ($password !== (string) $this->secret('Ulangi password')) {
                $this->error('Konfirmasi password tidak sama.');

                return [null, 'prompt'];
            }

            return [$this->validPassword($password) ? $password : null, 'prompt'];
        }

        return [Str::password(32), 'email_reset'];
    }

    private function validPassword(string $password): bool
    {
        $validator = Validator::make(['password' => $password], ['password' => ['required', ...PasswordPolicy::rules()]]);
        if ($validator->fails()) {
            $this->error('Password minimal 8 karakter, maksimal 72 byte, dan berisi huruf serta angka.');

            return false;
        }

        return true;
    }

    private function setRole(string $email, string $role): int
    {
        $result = DB::transaction(function () use ($email, $role) {
            DB::select("SELECT pg_advisory_xact_lock(hashtext('rt05:peran-pengurus'))");
            $akun = Akun::where('email', $email)->lockForUpdate()->first();
            if (! $akun) {
                return 'tidak_ada';
            }
            if ($akun->peran === $role) {
                return 'tetap';
            }
            if ($role === 'pengurus' && $akun->status !== 'aktif') {
                return 'tidak_aktif';
            }
            if ($role === 'warga' && ! Akun::where('peran', 'pengurus')->where('status', 'aktif')->whereKeyNot($akun->id)->exists()) {
                return 'terakhir';
            }
            $akun->peran = $role;
            $akun->save();
            $akun->revokeAllAccess();
            Audit::record(null, 'akun.peran_'.$role.'_operator', 'akun', $akun->id, ['kanal' => 'cli']);

            return 'ok';
        });

        return match ($result) {
            'ok' => $this->done("Peran $email: $role (semua token dan sesi browser lama dicabut)."),
            'tetap' => $this->done("Peran $email sudah $role; tidak ada perubahan."),
            'tidak_ada' => $this->reject('Akun tidak ditemukan.'),
            'tidak_aktif' => $this->reject('Hanya akun aktif yang dapat dijadikan pengurus.'),
            'terakhir' => $this->reject('Tidak dapat mencabut pengurus aktif terakhir.'),
        };
    }

    private function done(string $message): int
    {
        $this->info($message);

        return self::SUCCESS;
    }

    private function reject(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
