<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * Tes multi-proses tidak bisa memakai DatabaseTransactions: proses lain hanya melihat data yang
 * sudah commit. Trait ini mencatat waktu mulai lalu menghapus baris identitas yang dibuat sejak
 * itu (urutan aman FK), sehingga tes lain tetap melihat database bersih hasil migration.
 * Seed migration (kategori, tarif) tidak disentuh.
 */
trait CommittedData
{
    private string $committedSince;

    /** Tabel anak lebih dulu agar FK RESTRICT tidak menolak penghapusan. */
    private const COMMITTED_TABLES = [
        'personal_access_tokens', 'password_reset_tokens', 'notifikasi', 'preferensi_notifikasi',
        'permohonan_akun', 'audit_logs', 'outbox_events', 'token_registrasi', 'tautan_akun', 'keanggotaan_keluarga', 'akun',
        'penghunian', 'warga', 'keluarga', 'rumah',
    ];

    protected function markCommittedData(): void
    {
        $this->committedSince = DB::selectOne("SELECT (clock_timestamp() - interval '1 second')::text AS t")->t;
    }

    /**
     * Memakai koneksi pemilik schema (*_test, dijaga tests/bootstrap.php) karena audit_logs
     * append-only. Trigger dimatikan hanya di dalam transaksi pembersihan ini dan dinyalakan
     * lagi sebelum commit, jadi sesi lain tidak pernah melihat audit tanpa proteksi.
     */
    protected function purgeCommittedData(): void
    {
        DB::connection('pgsql_migrations')->transaction(function ($db) {
            $db->statement('ALTER TABLE audit_logs DISABLE TRIGGER audit_logs_no_update_delete');
            foreach (self::COMMITTED_TABLES as $table) {
                $db->table($table)->where('created_at', '>=', $this->committedSince)->delete();
            }
            $db->statement('ALTER TABLE audit_logs ENABLE TRIGGER audit_logs_no_update_delete');
        });
        DB::connection('pgsql_migrations')->disconnect();
    }
}
