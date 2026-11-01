<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

final class SupplementValue extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'title',
        'price',
        'supplement_id',
        'product_id',
        'max_quantity',
        'track_stock',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'product_id' => 'integer',
            'max_quantity' => 'integer',
            'track_stock' => 'boolean',
            'position' => 'integer',
        ];
    }

    // ==================== Relations ====================

    public function supplement(): BelongsTo
    {
        return $this->belongsTo(Supplement::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    // ==================== Business Logic ====================

    public function isFromCatalog(): bool
    {
        return $this->product_id !== null;
    }

    /**
     * Цена добавки: явный override или цена товара-добавки.
     */
    public function effectivePrice(): float
    {
        if ($this->price !== null) {
            return (float) $this->price;
        }

        return $this->isFromCatalog() && $this->product
            ? $this->product->getEffectivePrice()
            : 0.0;
    }

    /**
     * Учитывается ли остаток добавки. Только для товарных.
     * track_stock override приоритетнее stock_mode товара-добавки.
     */
    public function tracksStock(): bool
    {
        if (! $this->isFromCatalog() || ! $this->product) {
            return false;
        }

        if ($this->track_stock !== null) {
            return $this->track_stock;
        }

        return ! $this->product->isStockIgnored();
    }
}
