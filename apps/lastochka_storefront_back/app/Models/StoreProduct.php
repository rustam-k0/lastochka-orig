<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Оффер магазина на товар сети (network_catalog): цена/остаток/активность
 * товара в конкретном магазине. UNIQUE(store_id, product_id).
 */
class StoreProduct extends Model
{
    protected $fillable = [
        'store_id',
        'product_id',
        'price',
        'price_old',
        'quantity',
        'is_active',
        'synced_at',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'price_old' => 'decimal:2',
        'quantity' => 'integer',
        'is_active' => 'boolean',
        'synced_at' => 'datetime',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
