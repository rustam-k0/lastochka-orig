<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'product_id',
        'external_id',
        'name',
        'quantity',
        'price',
        'total',
        'actual_weight_grams',
        'base_unit_code',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'price' => 'decimal:2',
        'total' => 'decimal:2',
        'actual_weight_grams' => 'integer',
    ];

    /**
     * Заказ
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Товар
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Пересчитать сумму позиции
     */
    public function recalculateTotal(): void
    {
        $this->total = $this->price * $this->quantity;
        $this->save();
    }

    /**
     * Boot методы
     */
    protected static function boot()
    {
        parent::boot();

        // Автоматически пересчитываем total при сохранении
        static::saving(function (OrderItem $item) {
            $item->total = $item->price * $item->quantity;
        });
    }
}
