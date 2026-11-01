<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BonusOperation extends Model
{
    public const DIRECTION_INBOUND = 'inbound_from_1c';
    public const DIRECTION_OUTBOUND = 'outbound_to_1c';

    protected $fillable = [
        'operation_id', 'loyalty_id', 'type', 'amount', 'source',
        'order_external_id', 'direction', 'status', 'payload', 'exported_at',
    ];

    protected $casts = [
        'amount' => 'integer',
        'payload' => 'array',
        'exported_at' => 'datetime',
    ];
}
