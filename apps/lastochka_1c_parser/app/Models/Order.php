<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use HasFactory;

    public const STATUS_NEW = 'Новый';
    public const STATUS_ASSEMBLING = 'НаСборке';
    public const STATUS_ASSEMBLED = 'Собран';
    public const STATUS_IN_DELIVERY = 'ВДоставке';
    public const STATUS_DELIVERED = 'Доставлен';
    public const STATUS_CANCELLED = 'Отменен';

    public const STATUSES = [
        self::STATUS_NEW,
        self::STATUS_ASSEMBLING,
        self::STATUS_ASSEMBLED,
        self::STATUS_IN_DELIVERY,
        self::STATUS_DELIVERED,
        self::STATUS_CANCELLED,
    ];

    public const RABBITMQ_STATUS_MAP = [
        'in_delivery' => self::STATUS_IN_DELIVERY,
        'delivered'   => self::STATUS_DELIVERED,
        'cancelled'   => self::STATUS_CANCELLED,
    ];

    public const SHOP_STATUS_MAP = [
        'created'     => self::STATUS_NEW,
        'assembling'  => self::STATUS_ASSEMBLING,
        'assembled'   => self::STATUS_ASSEMBLED,
        'in_delivery' => self::STATUS_IN_DELIVERY,
        'delivered'   => self::STATUS_DELIVERED,
        'cancelled'   => self::STATUS_CANCELLED,
    ];

    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_SHOP = 'shop';
    public const SOURCE_LOYALTY_CARD = 'loyalty_card';

    protected $fillable = [
        'shop_order_id',
        'external_id',
        'number',
        'status',
        'source',
        'customer_name',
        'customer_phone',
        'customer_email',
        'delivery_address',
        'comment',
        'total',
        'discount',
        'delivery_cost',
        'shipping_method',
        'payment_type',
        'exported_at',
        'export_session_id',
        'received_at',
        'one_c_response',
        'shop_id',
        'bonus_earned',
        'bonus_spent',
        'has_card',
        'card_number',
        'loyalty_id',
        'confirmed_published_at',
    ];

    protected $casts = [
        'shop_order_id' => 'integer',
        'total' => 'decimal:2',
        'discount' => 'decimal:2',
        'delivery_cost' => 'decimal:2',
        'exported_at' => 'datetime',
        'received_at' => 'datetime',
        'one_c_response' => 'array',
        'bonus_earned' => 'integer',
        'bonus_spent' => 'integer',
        'has_card' => 'boolean',
        'confirmed_published_at' => 'datetime',
    ];

    /**
     * Позиции заказа
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * Пересчитать сумму заказа
     */
    public function recalculateTotal(): void
    {
        $this->total = $this->items()->sum('total');
        $this->save();
    }

    /**
     * Пометить как экспортированный
     */
    public function markAsExported(?string $externalId = null): void
    {
        $this->update([
            'exported_at' => now(),
            'external_id' => $externalId ?? $this->external_id,
        ]);
    }

    /**
     * Scope: по магазину
     */
    public function scopeForShop($query, int $shopId)
    {
        return $query->where('shop_id', $shopId);
    }

    /**
     * Scope: новые заказы (для экспорта в 1С)
     */
    public function scopeForExport($query)
    {
        return $query->whereIn('status', [
                self::STATUS_ASSEMBLED,
                self::STATUS_IN_DELIVERY,
                self::STATUS_DELIVERED,
                self::STATUS_CANCELLED,
            ])
            ->whereNull('exported_at');
    }

    /**
     * Scope: по статусу
     */
    public function scopeStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Генерация уникального номера заказа
     */
    public static function generateNumber(): string
    {
        $prefix = date('Ymd');
        $lastOrder = static::where('number', 'like', $prefix . '%')
            ->orderByDesc('number')
            ->first();

        if ($lastOrder) {
            $lastNum = (int) substr($lastOrder->number, strlen($prefix));
            $nextNum = $lastNum + 1;
        } else {
            $nextNum = 1;
        }

        return $prefix . str_pad($nextNum, 4, '0', STR_PAD_LEFT);
    }
}
