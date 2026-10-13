<?php

namespace App\Domain\Residents;

use Illuminate\Support\Facades\DB;

/**
 * Melengkapi histori M07 dari pointer lama (warga.keluarga_id, akun.warga_id) tanpa mengubah
 * baris yang sudah ada. Idempotent: hanya warga tanpa keanggotaan dan akun tanpa tautan yang
 * diisi, sehingga aman dijalankan ulang setelah image M06 sempat menulis registrasi.
 *
 * Tanggal tidak dikarang: mulai_at NULL (tercatat sebelum M07). Akhir hanya diisi dari kejadian
 * yang tercatat (warga.archived_at, keluarga.archived_at, akun.archived_at akun berstatus
 * diarsipkan).
 *
 * Konservatif terhadap hak akses: tautan AKTIF hanya dibuat bila akun aktif/nonaktif tanpa
 * archived_at, warganya belum diarsipkan, keluarganya belum diarsipkan, dan warga memiliki
 * keanggotaan aktif pada keluarga yang sama. Akun yang menunjuk warga/keluarga yang sudah
 * berakhir mendapat tautan yang langsung ditutup pada tanggal tercatat paling awal (alasan
 * akun_diarsipkan atau diarsipkan). Pointer yang tidak konsisten tanpa tanggal tercatat (mis.
 * akun berstatus diarsipkan tanpa archived_at, atau akun menunggu yang sudah menunjuk warga)
 * tidak ditautkan sama sekali: akses keluarga tertutup (404) sampai pengurus meninjau.
 * Bila data lama memuat lebih dari satu akun utama aktif pada satu keluarga, hanya yang tercatat
 * paling awal menjadi tautan utama; akun.jenis tidak diubah dan sisanya menjadi tautan tambahan.
 *
 * @return array{keanggotaan:int, tautan:int}
 */
final class LegacyBackfill
{
    public static function run(): array
    {
        $keanggotaan = DB::affectingStatement(<<<'SQL'
            INSERT INTO keanggotaan_keluarga (keluarga_id, warga_id, hubungan_keluarga, mulai_at, selesai_at, alasan_selesai, sumber)
            SELECT w.keluarga_id, w.id, w.hubungan_keluarga, NULL, COALESCE(w.archived_at, kel.archived_at),
                   CASE WHEN w.archived_at IS NOT NULL AND w.status IN ('pindah', 'meninggal') THEN w.status
                        WHEN COALESCE(w.archived_at, kel.archived_at) IS NOT NULL THEN 'diarsipkan' END,
                   'migrasi'
            FROM warga w
            JOIN keluarga kel ON kel.id = w.keluarga_id
            WHERE NOT EXISTS (SELECT 1 FROM keanggotaan_keluarga k WHERE k.warga_id = w.id)
            SQL);

        $tautan = DB::affectingStatement(<<<'SQL'
            WITH kandidat AS (
                SELECT a.id AS akun_id, a.warga_id, w.keluarga_id, a.jenis, a.created_at,
                       CASE WHEN a.status = 'diarsipkan' THEN a.archived_at END AS akun_selesai,
                       LEAST(CASE WHEN a.status = 'diarsipkan' THEN a.archived_at END, w.archived_at, kel.archived_at) AS selesai_at,
                       (a.status IN ('aktif', 'nonaktif') AND a.archived_at IS NULL
                        AND w.archived_at IS NULL AND kel.archived_at IS NULL
                        AND EXISTS (SELECT 1 FROM keanggotaan_keluarga m
                                    WHERE m.warga_id = w.id AND m.keluarga_id = w.keluarga_id AND m.selesai_at IS NULL)) AS aktif
                FROM akun a
                JOIN warga w ON w.id = a.warga_id
                JOIN keluarga kel ON kel.id = w.keluarga_id
                WHERE NOT EXISTS (SELECT 1 FROM tautan_akun t WHERE t.akun_id = a.id)
            ), urutan AS (
                SELECT k.*,
                       row_number() OVER (PARTITION BY k.keluarga_id, (k.jenis = 'utama' AND k.aktif)
                                          ORDER BY k.created_at, k.akun_id) AS ke
                FROM kandidat k
                WHERE k.aktif OR k.selesai_at IS NOT NULL
            )
            INSERT INTO tautan_akun (akun_id, warga_id, keluarga_id, is_utama, mulai_at, selesai_at, alasan_selesai, sumber)
            SELECT u.akun_id, u.warga_id, u.keluarga_id,
                   u.jenis = 'utama' AND u.aktif AND u.ke = 1
                       AND NOT EXISTS (SELECT 1 FROM tautan_akun t WHERE t.keluarga_id = u.keluarga_id AND t.is_utama AND t.selesai_at IS NULL),
                   NULL,
                   CASE WHEN u.aktif THEN NULL ELSE u.selesai_at END,
                   CASE WHEN u.aktif THEN NULL
                        WHEN u.akun_selesai IS NOT NULL AND u.akun_selesai = u.selesai_at THEN 'akun_diarsipkan'
                        ELSE 'diarsipkan' END,
                   'migrasi'
            FROM urutan u
            WHERE NOT (u.aktif AND EXISTS (SELECT 1 FROM tautan_akun t WHERE t.warga_id = u.warga_id AND t.selesai_at IS NULL))
            SQL);

        return ['keanggotaan' => $keanggotaan, 'tautan' => $tautan];
    }
}
