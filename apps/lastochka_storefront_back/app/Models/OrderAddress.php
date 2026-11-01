<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class OrderAddress extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'order_id',
        'original_id',
        'country',
        'city',
        'street',
        'house_number',
        'entrance',
        'floor',
        'apartment',
        'intercom',
        'comment',
        'full_address',
        'latitude',
        'longitude',
        'object_data',
        'created_at',
    ];

    // ==================== Casts ====================

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'object_data' => 'array',
            'created_at' => 'datetime',
        ];
    }

    // ==================== Relations ====================

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
