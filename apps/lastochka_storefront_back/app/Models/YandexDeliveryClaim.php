<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\YandexClaimStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class YandexDeliveryClaim extends Model
{
    protected $fillable = [
        'order_id',
        'claim_id',
        'zone_id',
        'status',
        'price',
        'currency',
        'eta_minutes',
        'due_at',
        'courier_name',
        'courier_phone',
        'tracking_url',
        'raw_response',
    ];

    protected function casts(): array
    {
        return [
            'status' => YandexClaimStatus::class,
            'price' => 'integer',
            'eta_minutes' => 'integer',
            'due_at' => 'datetime',
            'raw_response' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
