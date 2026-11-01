<?php

namespace App\Models\System;

use Illuminate\Database\Eloquent\Model;

class EventSyncMetadata extends Model
{
    protected $table = 'event_sync_metadata';

    protected $fillable = [
        'entity_type',
        'entity_id',
        'source',
        'external_id',
        'shop_code',
        'last_event_id',
        'last_event_timestamp',
        'locked_at',
        'data',
    ];

    protected $casts = [
        'last_event_timestamp' => 'datetime',
        'data' => 'array',
    ];
}
