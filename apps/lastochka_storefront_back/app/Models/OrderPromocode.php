<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PromocodeType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class OrderPromocode extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'order_id',
        'original_id',
        'code',
        'type',
        'value',
        'discount_amount',
        'created_at',
    ];

    // ==================== Casts ====================

    protected function casts(): array
    {
        return [
            'type' => PromocodeType::class,
            'value' => 'integer',
            'discount_amount' => 'float',
            'created_at' => 'datetime',
        ];
    }

    // ==================== Relations ====================

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
