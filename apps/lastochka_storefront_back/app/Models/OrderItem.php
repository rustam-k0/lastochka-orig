<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MeasurementUnit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class OrderItem extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'order_id',
        'product_id',
        'title',
        'sku',
        'price',
        'price_old',
        'quantity',
        'returned_quantity',
        'total',
        'vat_rate',
        'image_url',
        'created_at',
        'status',
        'origin_product_id',
        'origin_title',
        'origin_price',
        'origin_quantity',
        'measurement_unit',
        'actual_weight',
        'eligible_for_bonus',
        'price_tiers',
        'parent_id',
        'source_supplement_value_id',
    ];

    // ==================== Casts ====================

    protected function casts(): array
    {
        return [
            'price' => 'float',
            'price_old' => 'float',
            'quantity' => 'integer',
            'total' => 'float',
            'origin_price' => 'float',
            'origin_quantity' => 'integer',
            'vat_rate' => 'integer',
            'created_at' => 'datetime',
            'measurement_unit' => MeasurementUnit::class,
            'actual_weight' => 'integer',
            'eligible_for_bonus' => 'boolean',
            // Снапшот лесенки «цены от количества» (MID-308); null у позиций без лесенки
            'price_tiers' => 'array',
        ];
    }

    // ==================== Relations ====================

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function originProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'origin_product_id');
    }

    public function supplements(): HasMany
    {
        return $this->hasMany(OrderItemSupplement::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'parent_id');
    }

    public function childItems(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'parent_id');
    }

    public function sourceSupplementValue(): BelongsTo
    {
        return $this->belongsTo(SupplementValue::class, 'source_supplement_value_id');
    }

    /**
     * Статусы позиций, которых нет в фактическом составе заказа: отменённые
     * сборщиком и заменённые на другой товар. По ним не считается ни
     * `orders.subtotal` (см. OrderEventTransformer), ни фискальный чек.
     */
    public const INACTIVE_STATUSES = ['cancelled', 'replaced'];

    public function scopeRoots(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }

    /**
     * Позиции фактического состава заказа. NULL-статус — активная позиция:
     * так лежат заказы, ещё не прошедшие сборку, и заказы, созданные до
     * появления колонки `status` (whereNotIn в MySQL не включает NULL).
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where(function (Builder $q): void {
            $q->whereNotIn('status', self::INACTIVE_STATUSES)->orWhereNull('status');
        });
    }

    public function isActive(): bool
    {
        return ! in_array($this->status, self::INACTIVE_STATUSES, true);
    }

    /**
     * Вес позиции в граммах для плановой оценки веса заказа (total_weight,
     * плашка «Тяжёлый заказ» на чекауте).
     *
     * Приоритет — actual_weight (после сборки). Весовые позиции хранят quantity
     * в граммах/мл (единица хранения gram/milliliter, см. CreateOrderAction) —
     * это и есть их вес; у таких товаров product.weight не заполняется, и без
     * этой ветки курица на 1 кг «не весила ничего» (MID-259). Штучные —
     * product.weight × quantity, null если веса в карточке нет.
     */
    public function grams(): ?int
    {
        if ($this->actual_weight !== null) {
            return (int) $this->actual_weight;
        }

        $storageUnit = $this->measurement_unit;
        if ($storageUnit === MeasurementUnit::Gram || $storageUnit === MeasurementUnit::Milliliter) {
            return (int) $this->quantity;
        }
        if ($storageUnit === MeasurementUnit::Kilogram || $storageUnit === MeasurementUnit::Liter) {
            return (int) $this->quantity * 1000;
        }

        $product = $this->product;
        if ($product === null) {
            return null;
        }

        // Старые позиции без measurement_unit: весовой товар или товар с квантом
        // хранит quantity в граммах/мл
        if ($product->isMeasuredByWeight() || (int) ($product->quantity_step ?? 1) > 1) {
            return (int) $this->quantity;
        }

        $weight = $product->weight;
        if ($weight === null) {
            return null;
        }

        return (int) $weight * (int) $this->quantity;
    }
}
