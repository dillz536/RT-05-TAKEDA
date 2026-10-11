<?php

namespace App\Models;

class OutboxEvent extends Model
{
    protected $table = 'outbox_events';

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'safe_payload' => 'array',
            'available_at' => 'datetime',
            'dispatch_lease_until' => 'datetime',
            'published_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }
}
