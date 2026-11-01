<?php

namespace App\Models\System;

use Illuminate\Database\Eloquent\Model;

class EventOutbox extends Model
{
    protected $table = 'event_outbox';

    protected $fillable = [
        'event_id',
        'entity_type',
        'entity_id',
        'event_type',
        'routing_key',
        'payload',
        'status',
        'retries',
        'error',
        'published_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'published_at' => 'datetime',
    ];

    public function scopeUnpublished($query)
    {
        return $query->whereNull('published_at');
    }

    public function scopeForEntity($query, string $entityType)
    {
        return $query->where('entity_type', $entityType);
    }
}
