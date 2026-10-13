<?php

namespace App\Http\Controllers;

use App\Domain\Identity\PasswordPolicy;
use App\Domain\Identity\PasswordReset;
use App\Domain\Identity\Registration;
use App\Domain\Identity\TokenAttempts;
use App\Exceptions\DomainConflict;
use App\Http\Responses\Api;
use App\Http\Responses\Present;
use App\Models\Akun;
use App\Support\Audit;
use App\Support\BrowserSession;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /** Status yang boleh memulihkan kata sandi; akun nonaktif/diarsipkan tidak menerima email. */
    private const STATUS_PULIH = ['aktif', 'menunggu'];

    /** Respons lupa password identik untuk semua hasil; tidak mengklaim email sudah terkirim/tiba. */
    public const FORGOT_MESSAGE = 'Jika email terdaftar, permintaan reset kata sandi telah diterima dan diantrekan.';

    /**
     * POST /auth/register — akun utama + rumah + keluarga + token perangkat dalam satu transaksi
     * (FR03). Gagal di langkah mana pun, termasuk penerbitan bearer, tidak meninggalkan rekaman.
     */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:254'],
            'password' => ['required', 'confirmed', ...PasswordPolicy::rules()],
            'registration_token' => ['required', 'string', 'size:6'],
            'device_name' => ['required', 'string', 'max:100'],
            'nama_kepala' => ['required', 'string', 'max:120'],
            'whatsapp' => ['nullable', 'string', 'max:25', 'regex:/^[0-9+ -]{8,25}$/'],
            'blok' => ['nullable', 'string', 'max:30'],
            'jalan' => ['required', 'string', 'max:150'],
            'nomor' => ['required', 'string', 'max:20'],
            'jenis_hunian' => ['required', 'in:pemilik,kontrak'],
            'anggota' => ['array', 'max:15'],
            'anggota.*.nama' => ['required', 'string', 'max:120'],
            'anggota.*.hubungan_keluarga' => ['required', 'string', 'max:40'],
        ]);

        $issued = $this->registerWithToken($request, fn () => Registration::primary($data, $data['registration_token']), $data['device_name']);

        return Api::data($issued, 201);
    }

    /** POST /auth/additional-register — akun tambahan menunggu persetujuan pengurus (FR05). */
    public function additionalRegister(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:254'],
            'password' => ['required', 'confirmed', ...PasswordPolicy::rules()],
            'registration_token' => ['required', 'string', 'size:6'],
            'device_name' => ['required', 'string', 'max:100'],
            'nama' => ['required', 'string', 'max:120'],
            'hubungan_keluarga' => ['required', 'string', 'max:40'],
            'blok' => ['nullable', 'string', 'max:30'],
            'jalan' => ['required', 'string', 'max:150'],
            'nomor' => ['required', 'string', 'max:20'],
        ]);

        $issued = $this->registerWithToken($request, fn () => Registration::additional($data, $data['registration_token']), $data['device_name']);

        return Api::data($issued, 202);
    }

    /** POST /auth/mobile-login — bearer Sanctum per perangkat, berlaku 30 hari. */
    public function mobileLogin(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:254'],
            'password' => ['required', 'string', 'max:200'],
            'device_name' => ['required', 'string', 'max:100'],
        ]);

        $akun = Akun::where('email', Registration::email($data['email']))->first();
        if (! PasswordPolicy::matches($akun, $data['password']) || in_array($akun->status, ['nonaktif', 'diarsipkan'], true)) {
            throw ValidationException::withMessages(['email' => ['Email atau kata sandi salah.']]);
        }

        Audit::record($akun, 'akun.login', 'akun', $akun->id, ['perangkat' => mb_substr($data['device_name'], 0, 60)]);

        return Api::data($this->issueToken($akun, $data['device_name']));
    }

    /**
     * POST /auth/web-login — sesi cookie CMS untuk pengurus aktif (route group sesi-api: CSRF
     * wajib). Tidak menerbitkan bearer. Akun bukan pengurus/nonaktif mendapat pesan generik.
     */
    public function webLogin(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:254'],
            'password' => ['required', 'string', 'max:200'],
        ]);

        $akun = Akun::where('email', Registration::email($data['email']))->first();
        if (! PasswordPolicy::matches($akun, $data['password']) || ! $akun->isAktif() || ! $akun->isPengurus()) {
            throw ValidationException::withMessages(['email' => ['Email atau kata sandi salah.']]);
        }

        BrowserSession::start($request, $akun);
        Audit::record($akun, 'akun.login_web', 'akun', $akun->id);

        return Api::data(Present::akun($akun->load(Akun::PROFIL)));
    }

    public function me(Request $request): JsonResponse
    {
        return Api::data(Present::akun($request->user()->load(Akun::PROFIL)));
    }

    /** Bearer: cabut token perangkat ini. Cookie CMS: akhiri sesi browser ini (CSRF wajib). */
    public function logout(Request $request): JsonResponse
    {
        $akun = $request->user();
        if (BrowserSession::isCookie($akun)) {
            BrowserSession::end($request);
        } else {
            $akun->currentAccessToken()?->delete();
        }

        return Api::noContent();
    }

    /** Cabut semua bearer dan semua sesi browser akun, termasuk sesi/token request ini. */
    public function logoutAll(Request $request): JsonResponse
    {
        $request->validate(['password' => ['required', PasswordPolicy::current($request->user())]]);
        $akun = $request->user();
        DB::transaction(function () use ($akun) {
            $akun->revokeAllAccess();
            Audit::record($akun, 'akun.logout_semua', 'akun', $akun->id);
        });
        if (BrowserSession::isCookie($akun)) {
            BrowserSession::end($request);
        }

        return Api::noContent();
    }

    /**
     * Ganti password: bearer perangkat ini tetap, bearer lain dicabut, semua sesi browser lama
     * berakhir. Bila request ini sendiri sesi cookie, sesi ini diikat ke generasi baru.
     */
    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', PasswordPolicy::current($request->user())],
            'password' => ['required', 'confirmed', 'different:current_password', ...PasswordPolicy::rules()],
        ]);
        $akun = $request->user();
        $cookie = BrowserSession::isCookie($akun);
        DB::transaction(function () use ($akun, $data, $cookie) {
            $akun->forceFill(['password_hash' => $data['password']])->save();
            $current = $cookie ? null : $akun->currentAccessToken()?->id;
            $akun->tokens()->when($current, fn ($q) => $q->where('id', '!=', $current))->delete();
            $akun->endBrowserSessions();
            DB::table('password_reset_tokens')->where('email', $akun->email)->delete();
            Audit::record($akun, 'akun.ganti_password', 'akun', $akun->id);
        });
        if ($cookie) {
            $request->session()->regenerate();
            BrowserSession::bind($request, $akun);
        }

        return Api::data(['message' => 'Kata sandi diperbarui. Perangkat lain telah dikeluarkan.']);
    }

    /**
     * Respons selalu generik agar tidak membuka daftar email terdaftar. Token reset dibuat dalam
     * transaksi; email diantrekan sesudah commit dan kegagalannya tidak mengubah respons 202.
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email', 'max:254']]);
        $email = Registration::email((string) $request->string('email'));

        $pending = DB::transaction(function () use ($email) {
            $akun = Akun::where('email', $email)->whereIn('status', self::STATUS_PULIH)->first();
            if (! $akun) {
                // Biaya hash setara pembuatan token agar waktu respons tidak membedakan email.
                Hash::make(Str::random(40));

                return null;
            }
            $token = PasswordReset::createToken($akun);

            return $token === null ? null : [$akun, $token];
        });
        if ($pending) {
            PasswordReset::notify(...$pending);
        }

        return Api::data(['message' => self::FORGOT_MESSAGE], 202);
    }

    /**
     * Reset: token sekali pakai berlaku auth.passwords.akun.expire menit. Baris token dikunci agar
     * dua request concurrent dengan token sama tidak sama-sama berhasil. Semua bearer dan sesi
     * browser dicabut.
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:254'],
            'token' => ['required', 'string', 'max:200'],
            'password' => ['required', 'confirmed', ...PasswordPolicy::rules()],
        ]);
        $email = Registration::email($data['email']);

        $status = DB::transaction(function () use ($data, $email) {
            DB::table('password_reset_tokens')->where('email', $email)->lockForUpdate()->first();
            if (! Akun::where('email', $email)->whereIn('status', self::STATUS_PULIH)->exists()) {
                return Password::INVALID_TOKEN;
            }

            return Password::broker('akun')->reset(
                ['email' => $email, 'token' => $data['token'], 'password' => $data['password'], 'password_confirmation' => $data['password']],
                function (Akun $akun, string $password) {
                    $akun->forceFill(['password_hash' => $password])->save();
                    $akun->revokeAllAccess();
                    Audit::record($akun, 'akun.reset_password', 'akun', $akun->id);
                }
            );
        });
        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['token' => ['Tautan reset tidak valid atau kedaluwarsa.']]);
        }

        return Api::data(['message' => 'Kata sandi berhasil direset. Silakan masuk kembali.']);
    }

    public function additionalAccountStatus(Request $request): JsonResponse
    {
        $p = $request->user()->permohonan;

        return Api::data([
            'status_akun' => $request->user()->status,
            'permohonan' => $p ? ['jenis' => $p->jenis, 'status' => $p->status, 'catatan' => $p->status === 'ditolak' ? $p->catatan : null, 'created_at' => Present::ts($p->created_at)] : null,
        ]);
    }

    /**
     * Registrasi + bearer perangkat atomik. Kuota kegagalan token RT per IP direservasi sebelum
     * verifikasi dan dikembalikan kecuali token memang ditolak (TokenAttempts).
     */
    private function registerWithToken(Request $request, Closure $register, string $device): array
    {
        TokenAttempts::reserve($request);
        $tokenRejected = false;
        try {
            return DB::transaction(fn () => $this->issueToken($register(), $device));
        } catch (DomainConflict $e) {
            $tokenRejected = $e->errorCode === 'REGISTRATION_TOKEN_INVALID';
            throw $e;
        } finally {
            if (! $tokenRejected) {
                TokenAttempts::release($request);
            }
        }
    }

    private function issueToken(Akun $akun, string $device): array
    {
        $expires = now()->addMinutes((int) config('sanctum.expiration'));
        $token = $akun->createToken(mb_substr($device, 0, 100), ['*'], $expires);

        return [
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => Present::ts($expires),
            'akun' => Present::akun($akun->fresh()->load(Akun::PROFIL)),
        ];
    }
}
