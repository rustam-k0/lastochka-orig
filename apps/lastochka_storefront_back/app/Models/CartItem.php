<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MeasurementUnit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class CartItem extends Model
{
    use HasFactory;

    /**
     * Связи одной позиции для выдачи {@see \App\Http\Resources\Api\V1\CartItemResource}
     * вне коллекции корзины — то же, что {@see Cart::RESPONSE_RELATIONS}
     * без префикса `items.` (кроме `store`: ресурс позиции его не читает).
     * Держать в синхроне с Cart::RESPONSE_RELATIONS.
     */
    public const RESPONSE_RELATIONS = [
        'product.images',
        'product.supplements.values',
        'supplements.supplement',
        'supplements.values',
        'childItems.sourceSupplementValue.product.images',
    ];

    protected $fillable = [
        'cart_id',
        'product_id',
        'store_id',
        'quantity',
        'price',
        'price_old',
        'delivery_date',
        'parent_id',
        'source_supplement_value_id',
    ];

    // ==================== Casts ====================

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'price' => 'float',
            'price_old' => 'float',
            'delivery_date' => 'date',
        ];
    }

    // ==================== Relations ====================

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function supplements(): HasMany
    {
        return $this->hasMany(CartItemSupplement::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(CartItem::class, 'parent_id');
    }

    public function childItems(): HasMany
    {
        return $this->hasMany(CartItem::class, 'parent_id');
    }

    public function sourceSupplementValue(): BelongsTo
    {
        return $this->belongsTo(SupplementValue::class, 'source_supplement_value_id');
    }

    public function scopeRoots(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }

    /**
     * Количество порций (quantity / step).
     * Для штучных товаров (step=1) возвращает quantity как есть.
     */
    public function getPortionsAttribute(): int
    {
        $step = $this->product?->quantity_step ?? 1;

        return $step > 1 ? intdiv($this->quantity, $step) : $this->quantity;
    }

    /**
     * Вес позиции корзины в граммах (тариф доставки, плашка «Тяжёлый заказ»
     * на чекауте). Для товаров с квантом (step>1) quantity уже в граммах/мл —
     * принимаем мл ≈ г для тарифной оценки. Для kilogram/liter-товаров без
     * кванта корзина хранит кг/л (CreateOrderAction переводит в граммы при
     * создании позиции заказа), для gram/milliliter — граммы/мл. Штучные —
     * product.weight × quantity, null если у штучного нет weight (MID-259).
     */
    public function grams(): ?int
    {
        $product = $this->product;
        if ($product === null) {
            return null;
        }

        $step = (int) ($product->quantity_step ?? 1);
        if ($step > 1) {
            return (int) $this->quantity;
        }

        $unit = $product->measurement_unit;
        if ($unit === MeasurementUnit::Kilogram || $unit === MeasurementUnit::Liter) {
            return (int) $this->quantity * 1000;
        }
        if ($unit === MeasurementUnit::Gram || $unit === MeasurementUnit::Milliliter) {
            return (int) $this->quantity;
        }

        $weight = $product->weight;
        if ($weight === null) {
            return null;
        }

        return (int) $weight * (int) $this->quantity;
    }
}
