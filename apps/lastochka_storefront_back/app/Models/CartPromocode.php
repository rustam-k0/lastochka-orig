<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CartPromocode extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'cart_id',
        'store_id',
        'promocode_id',
        'created_at',
    ];

    // ==================== Casts ====================

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    // ==================== Relations ====================

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function promocode(): BelongsTo
    {
        return $this->belongsTo(Promocode::class);
    }
}
