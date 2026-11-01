<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class BonusHold extends Model
{
    public $timestamps = false;

    public const STATUS_HELD = 'held';
    public const STATUS_CONVERTED = 'converted';
    public const STATUS_RELEASED = 'released';

    protected $fillable = [
        'user_id', 'order_id', 'amount', 'status', 'created_at', 'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'created_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
