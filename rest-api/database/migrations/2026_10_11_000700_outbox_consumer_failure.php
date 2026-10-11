<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| M03: event yang consumer-nya gagal permanen (semua percobaan job habis) diparkir dengan
| failed_at agar tidak di-replay otomatis tanpa batas; operator mengulang per event
| (rt05:outbox-retry). Additive: kolom nullable, aplikasi lama tetap berjalan.
*/
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE outbox_events ADD COLUMN failed_at timestamptz(6)');
        DB::statement('DROP INDEX IF EXISTS outbox_events_pending_idx');
        DB::statement('CREATE INDEX outbox_events_pending_idx ON outbox_events (available_at) WHERE completed_at IS NULL AND failed_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS outbox_events_pending_idx');
        DB::statement('CREATE INDEX outbox_events_pending_idx ON outbox_events (available_at) WHERE completed_at IS NULL');
        DB::statement('ALTER TABLE outbox_events DROP COLUMN failed_at');
    }
};
